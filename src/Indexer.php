<?php

declare(strict_types=1);

class Indexer
{
	private Database $db;
	private Parser $parser;
	private ?RecastStatus $status;

	/** Label for who triggered casts through this indexer ('user', 'mcp', ...). */
	private string $initiator;

	/** When false, progress messages are suppressed (e.g. for web/JSON callers). */
	public bool $verbose = true;

	public function __construct(Database $db, ?RecastStatus $status = null, string $initiator = 'user')
	{
		$this->db = $db;
		$this->parser = new Parser();
		$this->status = $status;
		$this->initiator = $initiator;
	}

	private function out(string $message): void
	{
		if ($this->verbose) {
			echo $message;
		}
	}

	/**
	 * @param callable|null $onProgress function(int $done, int $total): void
	 *        Called as files are parsed, so callers can report recast progress.
	 */
	public function indexSource(array $sourceConfig, ?callable $onProgress = null): int
	{
		$name = (string)($sourceConfig['name'] ?? 'source');

		// Publish to the shared status the moment work starts, tagged with who
		// triggered it, so every browser tab's global toast can follow along.
		$this->status?->begin($name, $this->initiator);

		try {
			$sourceId = $this->db->createOrGetSource($sourceConfig);

			// Clear existing hooks and caller registrations for this source.
			$this->db->deleteSourceHooks($sourceId);
			$this->db->deleteSourceCallers($sourceId);

			$this->out("Casting into {$name}...\n");

			// A file belongs to the source with the most specific (longest) root.
			// Core's path is the whole WP install, which physically contains theme
			// and plugin sources; without this, their hooks would be misattributed
			// to Core. Exclude any subtree owned by another registered source that
			// lives inside this one.
			$excludeRoots = $this->childSourceRoots($sourceConfig['path']);

			$files = $this->findPhpFiles($sourceConfig['path'], $excludeRoots);
			$total = count($files);
			$this->out($total . " files found.\n");

			$report = function (int $done, int $total) use ($onProgress): void {
				$this->status?->progress($done, $total);
				if ($onProgress) {
					$onProgress($done, $total);
				}
			};
			$report(0, $total);

			$hooks = [];
			$callers = [];
			$done = 0;
			foreach ($files as $filePath) {
				// Store paths relative to the source root (forward slashes, leading
				// slash) so results are portable and clickable, and never leak the
				// absolute server path or depend on the source row's stored path.
				$relative = $this->relativePath($filePath, $sourceConfig['path']);

				foreach ($this->parser->parseFile($filePath) as $hook) {
					$hook['file_path'] = $relative;
					$hooks[] = $hook;
				}
				foreach ($this->parser->parseCallers($filePath) as $caller) {
					$caller['file_path'] = $relative;
					$callers[] = $caller;
				}
				$done++;
				// Report periodically to keep the stream light on large packages.
				if ($done === $total || $done % 10 === 0) {
					$report($done, $total);
				}
			}

			if (!empty($hooks)) {
				$this->db->insertHooks($sourceId, $hooks);
			}
			if (!empty($callers)) {
				$this->db->insertCallers($sourceId, $callers);
			}

			$hookCount = count($hooks);
			$this->db->updateSourceMetadata($sourceId, $hookCount, date('Y-m-d H:i:s'));
			$this->db->rebuildFts();

			$this->out(number_format($hookCount) . " hooks and " . number_format(count($callers)) . " callers found.\n");

			$this->status?->finish(
				"Re-cast {$name} - " . number_format($hookCount) . ' hooks.',
				true
			);

			return $hookCount;
		} catch (\Throwable $e) {
			$this->status?->finish("Recast of {$name} failed: " . $e->getMessage(), false);
			throw $e;
		}
	}

	public function indexAllSources(array $sourcesConfig): void
	{
		foreach ($sourcesConfig as $source) {
			$this->indexSource($source);
		}
	}

	/**
	 * Make an absolute file path relative to its source root: forward slashes,
	 * leading slash, e.g. "/wp-includes/post.php". Drive-letter case is ignored
	 * on Windows. Files unexpectedly outside the root keep a forward-slash
	 * absolute path rather than leaking mixed separators.
	 */
	private function relativePath(string $absolute, string $base): string
	{
		$file = str_replace('\\', '/', $absolute);
		$root = rtrim(str_replace('\\', '/', $base), '/');

		$matches = DIRECTORY_SEPARATOR === '\\'
			? stripos($file, $root) === 0
			: str_starts_with($file, $root);

		if ($root !== '' && $matches) {
			return '/' . ltrim(substr($file, strlen($root)), '/');
		}

		return $file;
	}

	/**
	 * Roots of other registered sources that live strictly inside $basePath, so
	 * the caller can skip files they own. Each is normalised (forward slashes,
	 * no trailing slash, case-folded on Windows) and suffixed with a slash so a
	 * prefix test matches whole path segments only.
	 *
	 * @return array<int, string>
	 */
	private function childSourceRoots(string $basePath): array
	{
		$base = $this->normalizePath($basePath);
		if ($base === '') {
			return [];
		}

		$roots = [];
		foreach ($this->db->getAllSources() as $source) {
			if (empty($source['path'])) {
				continue;
			}
			$other = $this->normalizePath($source['path']);
			// Strictly inside this source (not the source itself).
			if ($other !== $base && str_starts_with($other, $base . '/')) {
				$roots[] = $other . '/';
			}
		}

		return $roots;
	}

	/**
	 * Canonical form for comparing filesystem paths: forward slashes, no
	 * trailing slash, case-folded on Windows where paths are case-insensitive.
	 */
	private function normalizePath(string $path): string
	{
		$p = rtrim(str_replace('\\', '/', $path), '/');
		return DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p;
	}

	/**
	 * @param array<int, string> $excludeRoots Normalised roots (trailing slash)
	 *        whose files belong to a more specific source and must be skipped.
	 */
	private function findPhpFiles(string $basePath, array $excludeRoots = []): array
	{
		$files = [];
		$skipDirs = ['vendor', 'node_modules', 'tests', '.git', '.github'];

		$iterator = new \RecursiveDirectoryIterator($basePath);
		$iterator = new \RecursiveIteratorIterator($iterator);
		$iterator = new \RegexIterator($iterator, '/\.php$/');

		foreach ($iterator as $file) {
			$path = $file->getPathname();

			// Skip files in excluded directories
			$skip = false;
			foreach ($skipDirs as $skipDir) {
				if (str_contains($path, DIRECTORY_SEPARATOR . $skipDir . DIRECTORY_SEPARATOR)) {
					$skip = true;
					break;
				}
			}

			// Skip files owned by a more specific source nested inside this one.
			if (!$skip && $excludeRoots) {
				$normalized = $this->normalizePath($path);
				foreach ($excludeRoots as $root) {
					if (str_starts_with($normalized, $root)) {
						$skip = true;
						break;
					}
				}
			}

			if (!$skip) {
				$files[] = $path;
			}
		}

		return $files;
	}
}
