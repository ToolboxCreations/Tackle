<?php

declare(strict_types=1);

class Database
{
	private \PDO $pdo;

	public function __construct(string $dbPath)
	{
		$dir = dirname($dbPath);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}

		$this->pdo = new \PDO("sqlite:$dbPath");
		$this->pdo->exec('PRAGMA journal_mode=WAL');
		$this->pdo->exec('PRAGMA busy_timeout=5000');
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

		$this->createSchema();
		$this->migrate();
	}

	private function createSchema(): void
	{
		$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK(type IN ('core', 'plugin', 'theme')),
    version TEXT,
    path TEXT NOT NULL,
    last_indexed_at DATETIME,
    hook_count INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS hooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    name_normalized TEXT NOT NULL,
    type TEXT NOT NULL CHECK(type IN ('action', 'filter')),
    is_dynamic INTEGER DEFAULT 0,
    raw_expression TEXT,
    file_path TEXT NOT NULL,
    line_number INTEGER NOT NULL,
    docblock TEXT,
    parameters TEXT,
    since_version TEXT,
    deprecated INTEGER DEFAULT 0,
    UNIQUE(source_id, file_path, line_number)
);

CREATE VIRTUAL TABLE IF NOT EXISTS hooks_fts USING fts5(
    name, docblock, content='hooks', content_rowid='id'
);

CREATE TABLE IF NOT EXISTS callers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    hook_name TEXT NOT NULL,
    hook_name_normalized TEXT NOT NULL,
    type TEXT NOT NULL CHECK(type IN ('action', 'filter')),
    is_dynamic INTEGER DEFAULT 0,
    raw_expression TEXT,
    callback TEXT,
    priority TEXT,
    accepted_args TEXT,
    file_path TEXT NOT NULL,
    line_number INTEGER NOT NULL,
    UNIQUE(source_id, file_path, line_number)
);

CREATE INDEX IF NOT EXISTS idx_callers_name ON callers(hook_name);
CREATE INDEX IF NOT EXISTS idx_callers_normalized ON callers(hook_name_normalized);
CREATE INDEX IF NOT EXISTS idx_callers_source ON callers(source_id);
SQL;

		foreach (explode(';', $sql) as $statement) {
			$statement = trim($statement);
			if ($statement) {
				$this->pdo->exec($statement . ';');
			}
		}
	}

	/**
	 * Lightweight additive migrations. Adds columns that newer versions of
	 * Tackle expect onto existing databases. Safe to run on every boot.
	 */
	private function migrate(): void
	{
		$columns = [];
		foreach ($this->pdo->query('PRAGMA table_info(sources)') as $col) {
			$columns[$col['name']] = true;
		}

		$additions = [
			'slug'             => "ALTER TABLE sources ADD COLUMN slug TEXT",
			'origin'           => "ALTER TABLE sources ADD COLUMN origin TEXT DEFAULT 'path'",
			'auto_recast'      => "ALTER TABLE sources ADD COLUMN auto_recast INTEGER DEFAULT 0",
			'latest_version'   => "ALTER TABLE sources ADD COLUMN latest_version TEXT",
			'latest_checked_at' => "ALTER TABLE sources ADD COLUMN latest_checked_at DATETIME",
		];

		foreach ($additions as $name => $sql) {
			if (!isset($columns[$name])) {
				$this->pdo->exec($sql);
			}
		}
	}

	public function getPdo(): \PDO
	{
		return $this->pdo;
	}

	public function insertHooks(int $sourceId, array $hooks): void
	{
		if (empty($hooks)) {
			return;
		}

		$stmt = $this->pdo->prepare(<<<'SQL'
INSERT OR IGNORE INTO hooks
(source_id, name, name_normalized, type, is_dynamic, raw_expression, file_path, line_number, docblock, parameters, since_version, deprecated)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);

		foreach ($hooks as $hook) {
			$stmt->execute([
				$sourceId,
				$hook['name'],
				$hook['name_normalized'],
				$hook['type'],
				$hook['is_dynamic'] ? 1 : 0,
				$hook['raw_expression'] ?? null,
				$hook['file_path'],
				$hook['line_number'],
				$hook['docblock'] ?? null,
				$hook['parameters'] ? json_encode($hook['parameters']) : null,
				$hook['since_version'] ?? null,
				$hook['deprecated'] ? 1 : 0,
			]);
		}
	}

	public function rebuildFts(): void
	{
		$this->pdo->exec("INSERT INTO hooks_fts(hooks_fts) VALUES('rebuild')");
	}

	public function deleteSourceCallers(int $sourceId): void
	{
		$stmt = $this->pdo->prepare('DELETE FROM callers WHERE source_id = ?');
		$stmt->execute([$sourceId]);
	}

	/**
	 * Store add_action()/add_filter() registrations found in a source.
	 *
	 * @param array<int, array<string, mixed>> $callers
	 */
	public function insertCallers(int $sourceId, array $callers): void
	{
		if (empty($callers)) {
			return;
		}

		$stmt = $this->pdo->prepare(<<<'SQL'
INSERT OR IGNORE INTO callers
(source_id, hook_name, hook_name_normalized, type, is_dynamic, raw_expression, callback, priority, accepted_args, file_path, line_number)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);

		foreach ($callers as $caller) {
			$stmt->execute([
				$sourceId,
				$caller['hook_name'],
				$caller['hook_name_normalized'],
				$caller['type'],
				$caller['is_dynamic'] ? 1 : 0,
				$caller['raw_expression'] ?? null,
				$caller['callback'] ?? null,
				$caller['priority'] ?? null,
				$caller['accepted_args'] ?? null,
				$caller['file_path'],
				$caller['line_number'],
			]);
		}
	}

	/**
	 * Find everything hooked into a given hook name. Matches the literal name, its
	 * normalized form, and dynamic registrations whose pattern (e.g. "save_*")
	 * would resolve to the requested name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getCallers(string $hookName, string $type = '', ?int $sourceId = null, int $limit = 1000): array
	{
		$normalized = strtolower((string)preg_replace('/\$\w+|\{[^}]*\}/', '*', $hookName));

		$conditions = [
			'(c.hook_name = ? OR c.hook_name_normalized = ? OR ? LIKE REPLACE(c.hook_name_normalized, ' . "'*', '%'" . '))',
		];
		$params = [$hookName, $normalized, $hookName];

		if ($type === 'action' || $type === 'filter') {
			$conditions[] = 'c.type = ?';
			$params[] = $type;
		}
		if ($sourceId) {
			$conditions[] = 'c.source_id = ?';
			$params[] = $sourceId;
		}

		$where = 'WHERE ' . implode(' AND ', $conditions);
		$sql = <<<SQL
SELECT c.id, c.hook_name, c.type, c.is_dynamic, c.raw_expression, c.callback,
       c.priority, c.accepted_args, c.file_path, c.line_number,
       s.name AS source_name, s.path AS source_path
FROM callers c
JOIN sources s ON c.source_id = s.id
$where
LIMIT ?
SQL;

		$stmt = $this->pdo->prepare($sql);
		$stmt->execute(array_merge($params, [$limit]));
		$rows = $stmt->fetchAll();

		foreach ($rows as &$row) {
			$row['file_path'] = $this->shortenPath($row['file_path'], $row['source_path'] ?? null);
			unset($row['source_path']);
		}

		return $rows;
	}

	public function countCallersForHook(string $hookName): int
	{
		$normalized = strtolower((string)preg_replace('/\$\w+|\{[^}]*\}/', '*', $hookName));
		$stmt = $this->pdo->prepare(
			'SELECT COUNT(*) AS c FROM callers '
				. 'WHERE hook_name = ? OR hook_name_normalized = ? OR ? LIKE REPLACE(hook_name_normalized, ' . "'*', '%'" . ')'
		);
		$stmt->execute([$hookName, $normalized, $hookName]);
		return (int)($stmt->fetch()['c'] ?? 0);
	}

	/**
	 * Caller counts per source id, for the index status report.
	 *
	 * @return array<int, int>
	 */
	public function getCallerCounts(): array
	{
		$counts = [];
		foreach ($this->pdo->query('SELECT source_id, COUNT(*) AS c FROM callers GROUP BY source_id') as $row) {
			$counts[(int)$row['source_id']] = (int)$row['c'];
		}
		return $counts;
	}

	public function updateSourceMetadata(int $sourceId, int $hookCount, string $lastIndexedAt): void
	{
		$stmt = $this->pdo->prepare(
			'UPDATE sources SET hook_count = ?, last_indexed_at = ? WHERE id = ?'
		);
		$stmt->execute([$hookCount, $lastIndexedAt, $sourceId]);
	}

	public function getSourceByName(string $name): ?array
	{
		$stmt = $this->pdo->prepare('SELECT * FROM sources WHERE name = ?');
		$stmt->execute([$name]);
		return $stmt->fetch() ?: null;
	}

	public function getAllSources(): array
	{
		$stmt = $this->pdo->query('SELECT * FROM sources ORDER BY name ASC');
		return $stmt->fetchAll();
	}

	public function getSourceById(int $id): ?array
	{
		$stmt = $this->pdo->prepare('SELECT * FROM sources WHERE id = ?');
		$stmt->execute([$id]);
		return $stmt->fetch() ?: null;
	}

	/**
	 * Record where a source came from (WordPress.org slug, origin, version).
	 */
	public function setSourceOrigin(int $id, ?string $slug, string $origin, ?string $latestVersion = null): void
	{
		$stmt = $this->pdo->prepare(
			'UPDATE sources SET slug = ?, origin = ?, latest_version = ?, latest_checked_at = ? WHERE id = ?'
		);
		$stmt->execute([$slug, $origin, $latestVersion, date('Y-m-d H:i:s'), $id]);
	}

	public function setSourceVersion(int $id, string $version): void
	{
		$stmt = $this->pdo->prepare('UPDATE sources SET version = ? WHERE id = ?');
		$stmt->execute([$version, $id]);
	}

	public function setLatestVersion(int $id, ?string $latestVersion): void
	{
		$stmt = $this->pdo->prepare(
			'UPDATE sources SET latest_version = ?, latest_checked_at = ? WHERE id = ?'
		);
		$stmt->execute([$latestVersion, date('Y-m-d H:i:s'), $id]);
	}

	public function setAutoRecast(int $id, bool $enabled): void
	{
		$stmt = $this->pdo->prepare('UPDATE sources SET auto_recast = ? WHERE id = ?');
		$stmt->execute([$enabled ? 1 : 0, $id]);
	}

	public function getHookById(int $id): ?array
	{
		$stmt = $this->pdo->prepare(<<<'SQL'
SELECT h.*, s.name as source_name, s.type as source_type, s.path as source_path
FROM hooks h
JOIN sources s ON h.source_id = s.id
WHERE h.id = ?
SQL);
		$stmt->execute([$id]);
		$hook = $stmt->fetch();
		if ($hook) {
			$hook['file_path'] = $this->shortenPath($hook['file_path'], $hook['source_path'] ?? null);
		}
		return $hook ?: null;
	}

	/**
	 * Normalise a stored file path for display: always forward slashes, and -
	 * for any legacy rows still holding an absolute path - trimmed to be relative
	 * to the source root ("/wp-includes/post.php"). Paths are stored relative at
	 * index time, so for fresh data this just guarantees consistent separators
	 * and never leaks an absolute server path.
	 */
	public function shortenPath(?string $file, ?string $sourcePath): ?string
	{
		if (empty($file)) {
			return $file;
		}

		$normalizedFile = str_replace('\\', '/', $file);

		if (!empty($sourcePath)) {
			$normalizedSource = rtrim(str_replace('\\', '/', $sourcePath), '/\\');
			// On Windows the same path can differ only in drive-letter case
			// (c:\ vs C:\), so compare case-insensitively.
			$matches = DIRECTORY_SEPARATOR === '\\'
				? stripos($normalizedFile, $normalizedSource) === 0
				: str_starts_with($normalizedFile, $normalizedSource);
			if ($normalizedSource !== '' && $matches) {
				return '/' . ltrim(substr($normalizedFile, strlen($normalizedSource)), '/');
			}
		}

		return $normalizedFile;
	}

	public function deleteSourceHooks(int $sourceId): void
	{
		$stmt = $this->pdo->prepare('DELETE FROM hooks WHERE source_id = ?');
		$stmt->execute([$sourceId]);
	}

	public function deleteSource(int $sourceId): void
	{
		$stmt = $this->pdo->prepare('DELETE FROM sources WHERE id = ?');
		$stmt->execute([$sourceId]);
	}

	public function createOrGetSource(array $sourceConfig): int
	{
		$existing = $this->getSourceByName($sourceConfig['name']);
		if ($existing) {
			return (int)$existing['id'];
		}

		$stmt = $this->pdo->prepare(
			'INSERT INTO sources (name, type, version, path) VALUES (?, ?, ?, ?)'
		);
		$stmt->execute([
			$sourceConfig['name'],
			$sourceConfig['type'],
			$sourceConfig['version'] ?? null,
			$sourceConfig['path'],
		]);

		return (int)$this->pdo->lastInsertId();
	}
}
