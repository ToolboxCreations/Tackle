<?php

declare(strict_types=1);

/**
 * Resolves a hook's stored location back to an on-disk source file so the web
 * UI can show the code where the hook is defined.
 *
 * Hooks store a source-relative path ("/wp-includes/post.php") plus the source
 * row's absolute root. That absolute root may not be valid on the machine
 * serving the UI - most notably when the index was seeded inside Docker
 * (/app/wordpress) but is being browsed from the host. So resolution prefers a
 * path rebuilt from the *live* WordPress root and the source's folder name, and
 * only falls back to the stored absolute path for custom sources that live
 * outside the bundled install.
 */
class SourceViewer
{
	public function __construct(private string $wpPath)
	{
	}

	/**
	 * Read the source file a hook (or caller) row points at.
	 *
	 * @param array $row A hook row carrying source_path, source_type, file_path.
	 * @return array{lines: string[], path: string}|null Null when the file can't
	 *         be located or read on this machine.
	 */
	public function read(array $row): ?array
	{
		$file = $this->resolve(
			(string)($row['source_path'] ?? ''),
			(string)($row['source_type'] ?? ''),
			(string)($row['file_path'] ?? '')
		);
		if ($file === null) {
			return null;
		}

		$contents = @file_get_contents($file);
		if ($contents === false) {
			return null;
		}

		// Normalise line endings so line numbers line up with the indexer's.
		$contents = str_replace(["\r\n", "\r"], "\n", $contents);

		return [
			'lines' => explode("\n", $contents),
			'path'  => $file,
		];
	}

	/**
	 * Turn a stored source root + relative file path into a real, readable file
	 * on this machine, or null if none of the candidates exist or any attempt
	 * escapes its source root.
	 */
	private function resolve(string $sourcePath, string $sourceType, string $relative): ?string
	{
		$relative = str_replace('\\', '/', $relative);
		if ($relative === '') {
			return null;
		}
		$relative = '/' . ltrim($relative, '/');

		foreach ($this->candidateRoots($sourcePath, $sourceType) as $root) {
			$root = rtrim(str_replace('\\', '/', $root), '/');
			if ($root === '') {
				continue;
			}
			$candidate = $root . $relative;
			$real = realpath($candidate);
			if ($real === false || !is_file($real)) {
				continue;
			}
			// Defence in depth: the resolved file must stay inside its source
			// root, so a crafted relative path can't read arbitrary files.
			if (!$this->contains($root, $real)) {
				continue;
			}
			return $real;
		}

		return null;
	}

	/**
	 * Possible source roots, most-portable first.
	 *
	 * The rebuilt root uses only the live WordPress path and the source folder
	 * name, so it survives the index being moved between machines (e.g. Docker
	 * to host). The stored path is the fallback for sources installed outside
	 * the bundled wp-content.
	 *
	 * @return string[]
	 */
	private function candidateRoots(string $sourcePath, string $sourceType): array
	{
		$roots = [];

		$wp = rtrim(str_replace('\\', '/', $this->wpPath), '/');
		if ($wp !== '') {
			if ($sourceType === 'core') {
				$roots[] = $wp;
			} elseif ($sourceType === 'plugin' || $sourceType === 'theme') {
				$slug = basename(str_replace('\\', '/', rtrim($sourcePath, '/\\')));
				$folder = $sourceType === 'plugin' ? 'plugins' : 'themes';
				if ($slug !== '') {
					$roots[] = $wp . '/wp-content/' . $folder . '/' . $slug;
				}
			}
		}

		if ($sourcePath !== '') {
			$roots[] = $sourcePath;
		}

		return $roots;
	}

	/** True when $real sits inside $root (both already forward-slashed). */
	private function contains(string $root, string $real): bool
	{
		$real = str_replace('\\', '/', $real);
		$rootReal = realpath($root);
		if ($rootReal !== false) {
			$root = str_replace('\\', '/', $rootReal);
		}
		$root = rtrim($root, '/');
		$cmpReal = $real;
		$cmpRoot = $root;
		// Windows paths are case-insensitive.
		if (DIRECTORY_SEPARATOR === '\\') {
			$cmpReal = strtolower($cmpReal);
			$cmpRoot = strtolower($cmpRoot);
		}
		return $cmpRoot !== '' && ($cmpReal === $cmpRoot || str_starts_with($cmpReal, $cmpRoot . '/'));
	}
}
