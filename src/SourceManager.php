<?php

declare(strict_types=1);

/**
 * Manages the lifecycle of a source (a plugin, theme, or WordPress install)
 * entirely from Tackle - no wp-admin, no WP-CLI, no database required.
 *
 * Installing a plugin means: resolve its download link from WordPress.org,
 * download the zip, extract it into wp-content, and index its hooks. Updating
 * means re-fetching the latest zip and re-casting. "Auto-recast" keeps a
 * source's files and index fresh against the repository.
 */
class SourceManager
{
	private Database $db;
	private Indexer $indexer;
	private WordPressOrg $org;
	private string $wpContentPath;

	public function __construct(Database $db, Indexer $indexer, WordPressOrg $org, string $wpContentPath)
	{
		$this->db = $db;
		$this->indexer = $indexer;
		$this->indexer->verbose = false;
		$this->org = $org;
		$this->wpContentPath = rtrim($wpContentPath, '/\\');
	}

	/**
	 * The Tackle Box: every source as a UI-ready row, with update status when
	 * we already know the latest version from a previous check.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function listSources(): array
	{
		$rows = $this->db->getAllSources();
		foreach ($rows as &$row) {
			$row['has_update'] = $this->isOutdated($row);
			$row['auto_recast'] = (int)($row['auto_recast'] ?? 0) === 1;
			$row['managed'] = ($row['origin'] ?? 'path') === 'wporg';
			$row['exists'] = is_dir($row['path']);
		}
		return $rows;
	}

	/**
	 * Install a plugin/theme from WordPress.org and index it.
	 *
	 * @return array{ok: bool, message: string, source_id?: int, hooks?: int, name?: string}
	 */
	public function installFromRepo(string $type, string $slug): array
	{
		$type = $type === 'theme' ? 'theme' : 'plugin';
		$slug = $this->safeSlug($slug);
		if ($slug === '') {
			return ['ok' => false, 'message' => 'Invalid slug.'];
		}

		$info = $this->org->info($type, $slug);
		if (!$info || empty($info['download_link'])) {
			return ['ok' => false, 'message' => "Could not find “{$slug}” in the WordPress.org {$type} directory."];
		}

		try {
			$path = $this->downloadAndExtract($info['download_link'], $type);
		} catch (\Throwable $e) {
			return ['ok' => false, 'message' => 'Download/extract failed: ' . $e->getMessage()];
		}

		$config = [
			'name'    => $info['name'] ?: $slug,
			'type'    => $type,
			'version' => $info['version'] ?? '',
			'path'    => $path,
		];

		$hooks = $this->indexer->indexSource($config);

		$source = $this->db->getSourceByName($config['name']);
		$sourceId = (int)($source['id'] ?? 0);
		if ($sourceId) {
			$this->db->setSourceOrigin($sourceId, $slug, 'wporg', $info['version'] ?? null);
		}

		return [
			'ok'        => true,
			'message'   => "Added {$config['name']} - {$hooks} hooks indexed.",
			'source_id' => $sourceId,
			'hooks'     => $hooks,
			'name'      => $config['name'],
		];
	}

	/**
	 * Install a plugin/theme from an uploaded zip file (for sources not in the
	 * WordPress.org directory, e.g. commercial plugins). The display name and
	 * version are read from the package's WordPress header.
	 *
	 * An optional progress callback receives stage updates so a web caller can
	 * stream them: function(string $stage, ?int $done, ?int $total): void where
	 * $stage is "extracting" or "indexing".
	 *
	 * @return array{ok: bool, message: string, source_id?: int, hooks?: int, name?: string}
	 */
	public function installFromZip(string $type, string $zipPath, string $originalName = '', ?callable $onProgress = null): array
	{
		$type = $type === 'theme' ? 'theme' : 'plugin';

		if (!is_file($zipPath)) {
			return ['ok' => false, 'message' => 'Uploaded file is missing.'];
		}

		try {
			$path = $this->extractZip($zipPath, $type, $onProgress
				? fn(int $done, int $total) => $onProgress('extracting', $done, $total)
				: null);
		} catch (\Throwable $e) {
			return ['ok' => false, 'message' => 'Could not extract the zip: ' . $e->getMessage()];
		}

		$header = $this->readHeader($path, $type);
		$folder = basename($path);
		$name = $header['name'] !== '' ? $header['name'] : $this->prettifySlug($folder);

		$config = [
			'name'    => $name,
			'type'    => $type,
			'version' => $header['version'],
			'path'    => $path,
		];

		$hooks = $this->indexer->indexSource($config, $onProgress
			? fn(int $done, int $total) => $onProgress('indexing', $done, $total)
			: null);

		$source = $this->db->getSourceByName($name);
		$sourceId = (int)($source['id'] ?? 0);
		if ($sourceId) {
			// Uploaded sources have no WordPress.org counterpart to check against.
			$this->db->setSourceOrigin($sourceId, $folder, 'upload', null);
		}

		return [
			'ok'        => true,
			'message'   => "Added {$name}" . ($header['version'] !== '' ? " {$header['version']}" : '') . " - {$hooks} hooks indexed.",
			'source_id' => $sourceId,
			'hooks'     => $hooks,
			'name'      => $name,
		];
	}

	/**
	 * Scan wp-content for plugins and themes that exist on disk but are not yet
	 * in the tackle box. This finds packages that were copied in by hand, left
	 * behind after a source was removed (files kept), or installed by WordPress
	 * itself - so they can be cast without re-uploading a zip.
	 *
	 * @return array<int, array{type: string, folder: string, name: string, version: string}>
	 */
	public function discoverOnDisk(): array
	{
		$known = [];
		foreach ($this->db->getAllSources() as $s) {
			if (!empty($s['path'])) {
				$known[$this->normalizePath(realpath($s['path']) ?: $s['path'])] = true;
			}
		}

		$found = [];
		foreach (['plugin' => 'plugins', 'theme' => 'themes'] as $type => $dir) {
			$parent = $this->wpContentPath . DIRECTORY_SEPARATOR . $dir;
			if (!is_dir($parent)) {
				continue;
			}
			foreach (glob($parent . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $path) {
				if (isset($known[$this->normalizePath(realpath($path) ?: $path)])) {
					continue;
				}
				$folder = basename($path);
				$header = $this->readHeader($path, $type);
				$found[] = [
					'type'    => $type,
					'folder'  => $folder,
					'name'    => $header['name'] !== '' ? $header['name'] : $this->prettifySlug($folder),
					'version' => $header['version'],
				];
			}
		}

		usort($found, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
		return $found;
	}

	/**
	 * Cast a plugin/theme that already exists on disk in wp-content, identified
	 * by its folder name. Name and version come from the package's WordPress
	 * header. Unlike a removal of an uploaded source, these files are never
	 * deleted by Tackle (origin "disk").
	 *
	 * @return array{ok: bool, message: string, source_id?: int, hooks?: int, name?: string}
	 */
	public function castFromDisk(string $type, string $folder): array
	{
		$type = $type === 'theme' ? 'theme' : 'plugin';
		$folder = basename(trim($folder)); // guard against path traversal
		if ($folder === '' || $folder === '.' || $folder === '..') {
			return ['ok' => false, 'message' => 'Invalid package folder.'];
		}

		$parent = $this->wpContentPath . DIRECTORY_SEPARATOR . ($type === 'theme' ? 'themes' : 'plugins');
		$path = $parent . DIRECTORY_SEPARATOR . $folder;
		if (!is_dir($path) || !$this->isInsideWpContent($path)) {
			return ['ok' => false, 'message' => 'Could not find that package on disk.'];
		}

		$header = $this->readHeader($path, $type);
		$name = $header['name'] !== '' ? $header['name'] : $this->prettifySlug($folder);

		$config = [
			'name'    => $name,
			'type'    => $type,
			'version' => $header['version'],
			'path'    => $path,
		];

		$hooks = $this->indexer->indexSource($config);

		$source = $this->db->getSourceByName($name);
		$sourceId = (int)($source['id'] ?? 0);
		if ($sourceId) {
			// Found on disk - no WordPress.org counterpart to check against, and
			// Tackle must not delete files it did not place there.
			$this->db->setSourceOrigin($sourceId, $folder, 'disk', null);
		}

		return [
			'ok'        => true,
			'message'   => "Added {$name}" . ($header['version'] !== '' ? " {$header['version']}" : '') . " - {$hooks} hooks indexed.",
			'source_id' => $sourceId,
			'hooks'     => $hooks,
			'name'      => $name,
		];
	}

	/**
	 * Permanently delete a plugin/theme that exists on disk in wp-content,
	 * identified by its folder name. This is for packages found via
	 * discoverOnDisk() that are not in the box - so there are no index rows to
	 * clean up. Confined to wp-content and guarded against path traversal.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function deleteFromDisk(string $type, string $folder): array
	{
		$type = $type === 'theme' ? 'theme' : 'plugin';
		$folder = basename(trim($folder)); // guard against path traversal
		if ($folder === '' || $folder === '.' || $folder === '..') {
			return ['ok' => false, 'message' => 'Invalid package folder.'];
		}

		$parent = $this->wpContentPath . DIRECTORY_SEPARATOR . ($type === 'theme' ? 'themes' : 'plugins');
		$path = $parent . DIRECTORY_SEPARATOR . $folder;
		if (!is_dir($path) || !$this->isInsideWpContent($path)) {
			return ['ok' => false, 'message' => 'Could not find that package on disk.'];
		}

		$this->removeDirectory($path);
		if (is_dir($path)) {
			return ['ok' => false, 'message' => "Could not delete {$folder} - check file permissions."];
		}

		return ['ok' => true, 'message' => "Deleted {$folder} from disk."];
	}

	/**
	 * Re-index an existing source from the files already on disk.
	 *
	 * @return array{ok: bool, message: string, hooks?: int}
	 */
	public function recast(int $id): array
	{
		$source = $this->db->getSourceById($id);
		if (!$source) {
			return ['ok' => false, 'message' => 'Source not found.'];
		}
		if (!is_dir($source['path'])) {
			return ['ok' => false, 'message' => "Files are missing at {$source['path']}."];
		}

		$hooks = $this->indexer->indexSource([
			'name'    => $source['name'],
			'type'    => $source['type'],
			'version' => $source['version'] ?? '',
			'path'    => $source['path'],
		]);

		return ['ok' => true, 'message' => "Re-cast {$source['name']} - {$hooks} hooks.", 'hooks' => $hooks];
	}

	/**
	 * Check WordPress.org for a newer version and remember the result.
	 *
	 * @return array{ok: bool, message: string, current?: string, latest?: string, has_update?: bool}
	 */
	public function checkUpdate(int $id): array
	{
		$source = $this->db->getSourceById($id);
		if (!$source) {
			return ['ok' => false, 'message' => 'Source not found.'];
		}
		if (($source['origin'] ?? 'path') !== 'wporg' || empty($source['slug'])) {
			return ['ok' => false, 'message' => 'This source is not managed from WordPress.org.'];
		}

		$info = $this->org->info($source['type'], $source['slug']);
		if (!$info) {
			return ['ok' => false, 'message' => 'Could not reach WordPress.org.'];
		}

		$latest = $info['version'] ?? '';
		$this->db->setLatestVersion($id, $latest);
		$hasUpdate = $source['version'] && $latest && version_compare($latest, $source['version'], '>');

		return [
			'ok'         => true,
			'message'    => $hasUpdate ? "Update available: {$latest}." : 'Up to date.',
			'current'    => (string)$source['version'],
			'latest'     => $latest,
			'has_update' => $hasUpdate,
		];
	}

	/**
	 * Download the latest version from WordPress.org and re-cast.
	 *
	 * @return array{ok: bool, message: string, hooks?: int, version?: string}
	 */
	public function update(int $id): array
	{
		$source = $this->db->getSourceById($id);
		if (!$source) {
			return ['ok' => false, 'message' => 'Source not found.'];
		}
		if (($source['origin'] ?? 'path') !== 'wporg' || empty($source['slug'])) {
			return ['ok' => false, 'message' => 'This source is not managed from WordPress.org.'];
		}

		$info = $this->org->info($source['type'], $source['slug']);
		if (!$info || empty($info['download_link'])) {
			return ['ok' => false, 'message' => 'Could not resolve the latest download.'];
		}

		try {
			$path = $this->downloadAndExtract($info['download_link'], $source['type']);
		} catch (\Throwable $e) {
			return ['ok' => false, 'message' => 'Update failed: ' . $e->getMessage()];
		}

		$this->db->setSourceVersion($id, $info['version'] ?? ($source['version'] ?? ''));
		$this->db->setLatestVersion($id, $info['version'] ?? null);

		$hooks = $this->indexer->indexSource([
			'name'    => $source['name'],
			'type'    => $source['type'],
			'version' => $info['version'] ?? '',
			'path'    => $path,
		]);

		return [
			'ok'      => true,
			'message' => "Updated {$source['name']} to {$info['version']} - {$hooks} hooks.",
			'hooks'   => $hooks,
			'version' => $info['version'] ?? '',
		];
	}

	/**
	 * Remove a source from the index. If Tackle installed it from the repo and
	 * $deleteFiles is true, the downloaded files are removed too.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function remove(int $id, bool $deleteFiles = false): array
	{
		$source = $this->db->getSourceById($id);
		if (!$source) {
			return ['ok' => false, 'message' => 'Source not found.'];
		}

		$this->db->deleteSource($id); // cascades to hooks

		// Only delete files Tackle itself placed in wp-content (repo or upload).
		$installed = in_array($source['origin'] ?? 'path', ['wporg', 'upload'], true);
		if ($deleteFiles && $installed && !empty($source['path'])) {
			if ($this->isInsideWpContent($source['path'])) {
				$this->removeDirectory($source['path']);
			}
		}

		return ['ok' => true, 'message' => "Removed {$source['name']} from the tackle box."];
	}

	public function setAutoRecast(int $id, bool $enabled): array
	{
		if (!$this->db->getSourceById($id)) {
			return ['ok' => false, 'message' => 'Source not found.'];
		}
		$this->db->setAutoRecast($id, $enabled);
		return ['ok' => true, 'message' => $enabled ? 'Auto re-cast enabled.' : 'Auto re-cast disabled.'];
	}

	// ---------------------------------------------------------------- helpers

	private function isOutdated(array $row): bool
	{
		$latest = $row['latest_version'] ?? '';
		$current = $row['version'] ?? '';
		return $latest !== '' && $current !== '' && version_compare((string)$latest, (string)$current, '>');
	}

	/**
	 * Download a zip from a URL and extract it into wp-content/{plugins,themes}.
	 * Returns the absolute path of the extracted directory.
	 */
	private function downloadAndExtract(string $url, string $type): string
	{
		$tmp = tempnam(sys_get_temp_dir(), 'tackle_') ?: throw new \RuntimeException('Could not create temp file.');

		$fp = fopen($tmp, 'wb');
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Tackle/1.0');
		Http::secure($ch);
		$ok = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		fclose($fp);

		if (!$ok || $code !== 200) {
			@unlink($tmp);
			throw new \RuntimeException("download returned HTTP {$code}" . ($err ? " ({$err})" : ''));
		}

		try {
			return $this->extractZip($tmp, $type);
		} finally {
			@unlink($tmp);
		}
	}

	/**
	 * Extract a zip into wp-content/{plugins,themes}, returning the path of the
	 * package directory. Guards against zip-slip (path traversal / absolute
	 * paths) and removes any existing copy so updates leave no stale files.
	 *
	 * @param callable|null $onProgress function(int $done, int $total): void
	 */
	private function extractZip(string $zipPath, string $type, ?callable $onProgress = null): string
	{
		if (!class_exists('ZipArchive')) {
			throw new \RuntimeException('The PHP zip extension is required. Enable "extension=zip" in php.ini.');
		}

		$targetParent = $this->wpContentPath . DIRECTORY_SEPARATOR . ($type === 'theme' ? 'themes' : 'plugins');
		if (!is_dir($targetParent) && !mkdir($targetParent, 0755, true) && !is_dir($targetParent)) {
			throw new \RuntimeException("Could not create {$targetParent}.");
		}

		$zip = new \ZipArchive();
		if ($zip->open($zipPath) !== true) {
			throw new \RuntimeException('Not a valid zip archive.');
		}

		// Inspect every entry: reject unsafe paths, and learn the structure.
		$topSegments = [];
		$hasRootFile = false;
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entry = str_replace('\\', '/', (string)$zip->getNameIndex($i));
			if ($entry === '' || str_starts_with($entry, '/') || str_contains($entry, '../') || preg_match('#^[A-Za-z]:#', $entry)) {
				$zip->close();
				throw new \RuntimeException('Archive contains an unsafe file path.');
			}
			$entries[] = (string)$zip->getNameIndex($i);
			$parts = explode('/', trim($entry, '/'));
			$topSegments[$parts[0]] = true;
			if (count($parts) === 1 && !str_ends_with($entry, '/')) {
				$hasRootFile = true;
			}
		}

		if (empty($topSegments)) {
			$zip->close();
			throw new \RuntimeException('Archive is empty.');
		}

		// A well-formed package wraps everything in a single top-level folder.
		// Otherwise (loose files / multiple roots) wrap it ourselves.
		if (count($topSegments) === 1 && !$hasRootFile) {
			$folder = (string)array_key_first($topSegments);
			$dest = $targetParent;
		} else {
			$folder = pathinfo($zipPath, PATHINFO_FILENAME) ?: ('package-' . time());
			$dest = $targetParent . DIRECTORY_SEPARATOR . $folder;
		}

		$extracted = $targetParent . DIRECTORY_SEPARATOR . $folder;
		if (is_dir($extracted) && $this->isInsideWpContent($extracted)) {
			$this->removeDirectory($extracted);
		}

		// Extract entry-by-entry so we can report progress on large packages.
		// (extractTo() with no entry list does it all at once, with no progress.)
		$total = count($entries);
		$done = 0;
		if ($onProgress) {
			$onProgress(0, $total);
		}
		foreach ($entries as $entry) {
			if (!$zip->extractTo($dest, $entry)) {
				$zip->close();
				throw new \RuntimeException('Extraction failed.');
			}
			$done++;
			if ($onProgress && ($done === $total || $done % 10 === 0)) {
				$onProgress($done, $total);
			}
		}
		$zip->close();

		if (!is_dir($extracted)) {
			throw new \RuntimeException('Extraction did not produce the expected directory.');
		}

		return $extracted;
	}

	/**
	 * Read the WordPress header from an extracted package to get its name and
	 * version. Plugins declare these in a PHP file's header comment; themes in
	 * style.css.
	 *
	 * @return array{name: string, version: string}
	 */
	private function readHeader(string $dir, string $type): array
	{
		$files = [];
		if ($type === 'theme') {
			$files[] = $dir . DIRECTORY_SEPARATOR . 'style.css';
			$nameField = 'Theme Name';
		} else {
			// WordPress scans top-level PHP files for the plugin header.
			$files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
			$nameField = 'Plugin Name';
		}

		foreach ($files as $file) {
			if (!is_file($file)) {
				continue;
			}
			$head = file_get_contents($file, false, null, 0, 8192);
			if ($head === false) {
				continue;
			}
			$name = $this->headerField($head, $nameField);
			if ($name !== '') {
				return ['name' => $name, 'version' => $this->headerField($head, 'Version')];
			}
		}

		return ['name' => '', 'version' => ''];
	}

	private function headerField(string $haystack, string $field): string
	{
		// Header lines look like: " * Plugin Name: Foo" or "Theme Name: Foo".
		if (preg_match('/^[ \t\/*#@]*' . preg_quote($field, '/') . ':(.+)$/mi', $haystack, $m)) {
			return trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
		}
		return '';
	}

	private function prettifySlug(string $slug): string
	{
		return ucwords(trim(str_replace(['-', '_'], ' ', $slug))) ?: $slug;
	}

	/**
	 * Canonical form for comparing two filesystem paths: forward slashes, no
	 * trailing slash, and case-folded on Windows where paths are case-insensitive.
	 */
	private function normalizePath(string $path): string
	{
		$p = rtrim(str_replace('\\', '/', $path), '/');
		return DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p;
	}

	private function isInsideWpContent(string $path): bool
	{
		$real = realpath($path) ?: $path;
		$base = realpath($this->wpContentPath) ?: $this->wpContentPath;
		$real = str_replace('\\', '/', $real);
		$base = rtrim(str_replace('\\', '/', $base), '/');
		return $base !== '' && str_starts_with($real, $base . '/');
	}

	private function safeSlug(string $slug): string
	{
		$slug = strtolower(trim($slug));
		return preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug) ? $slug : '';
	}

	private function removeDirectory(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($path);
	}
}
