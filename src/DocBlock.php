<?php

declare(strict_types=1);

/**
 * Turns a raw PHPDoc block - the comment that sits directly above a do_action()
 * or apply_filters() call - into structured pieces the UI can render cleanly:
 * a plain description, the @param list, and a handful of common tags.
 *
 * The stored docblock keeps its comment furniture ("/**", leading " * ", "*\/").
 * Everything here strips that furniture so callers never have to.
 */
class DocBlock
{
	/**
	 * Parse a raw docblock into its parts.
	 *
	 * @return array{description: string, params: array<int, array{type: string, name: string, description: string}>, since: ?string, deprecated: bool}
	 */
	public static function parse(?string $raw): array
	{
		$result = [
			'description' => '',
			'params'      => [],
			'since'       => null,
			'deprecated'  => false,
		];

		if ($raw === null || trim($raw) === '') {
			return $result;
		}

		$descriptionLines = [];
		$params = [];
		$paramIndex = -1; // so continuation lines can append to the last @param

		foreach (self::cleanLines($raw) as $line) {
			if (preg_match('/^@param\s+(.*)$/i', $line, $m)) {
				$param = self::parseParam($m[1]);
				if ($param !== null) {
					$params[] = $param;
					$paramIndex = count($params) - 1;
				}
				continue;
			}

			if (preg_match('/^@since\s+(.*)$/i', $line, $m)) {
				$result['since'] = trim($m[1]) ?: null;
				$paramIndex = -1;
				continue;
			}

			if (preg_match('/^@deprecated\b/i', $line)) {
				$result['deprecated'] = true;
				$paramIndex = -1;
				continue;
			}

			// Any other tag ends a @param's continuation and is not description text.
			if (str_starts_with($line, '@')) {
				$paramIndex = -1;
				continue;
			}

			// A non-tag line continues either the running @param description or,
			// before any tags are seen, the hook's own description.
			if ($paramIndex >= 0) {
				if ($line !== '') {
					$params[$paramIndex]['description'] = trim($params[$paramIndex]['description'] . ' ' . $line);
				}
				continue;
			}

			$descriptionLines[] = $line;
		}

		$result['description'] = trim(implode("\n", $descriptionLines));
		$result['params'] = $params;

		return $result;
	}

	/**
	 * The single-line summary used in search results: the first non-empty
	 * sentence of the description, with all comment furniture stripped.
	 */
	public static function summary(?string $raw): ?string
	{
		foreach (self::cleanLines($raw ?? '') as $line) {
			if ($line === '' || str_starts_with($line, '@')) {
				continue;
			}
			return $line;
		}
		return null;
	}

	/**
	 * Strip the comment furniture from a docblock and return its inner lines,
	 * trailing blank lines removed but internal structure (paragraph breaks)
	 * preserved.
	 *
	 * @return array<int, string>
	 */
	private static function cleanLines(string $raw): array
	{
		$lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
		$out = [];

		foreach ($lines as $line) {
			$line = trim($line);

			// Drop the opening "/**" and closing "*/" markers entirely.
			if ($line === '/**' || $line === '*/' || $line === '/*') {
				continue;
			}

			// Strip a leading "* " (or a bare "*") from the body lines. Also
			// handle the one-liner "/** text */" form.
			if (str_starts_with($line, '/**')) {
				$line = substr($line, 3);
			}
			if (str_ends_with($line, '*/')) {
				$line = substr($line, 0, -2);
			}
			$line = preg_replace('/^\*\s?/', '', trim($line)) ?? $line;

			$out[] = trim($line);
		}

		// Trim leading/trailing blank lines.
		while ($out !== [] && $out[0] === '') {
			array_shift($out);
		}
		while ($out !== [] && end($out) === '') {
			array_pop($out);
		}

		return $out;
	}

	/**
	 * Parse the body of a "@param" line ("int $post_ID Post ID.") into its
	 * type, name and description. Returns null if there is nothing usable.
	 *
	 * @return array{type: string, name: string, description: string}|null
	 */
	private static function parseParam(string $body): ?array
	{
		$body = trim($body);
		if ($body === '') {
			return null;
		}

		$type = '';
		$name = '';

		// Type comes first when the token isn't already the variable.
		if (!str_starts_with($body, '$')) {
			$parts = preg_split('/\s+/', $body, 2);
			$type = $parts[0] ?? '';
			$body = trim($parts[1] ?? '');
		}

		if (str_starts_with($body, '$')) {
			$parts = preg_split('/\s+/', $body, 2);
			$name = $parts[0] ?? '';
			$body = trim($parts[1] ?? '');
		}

		if ($type === '' && $name === '') {
			return null;
		}

		return [
			'type'        => $type,
			'name'        => $name,
			'description' => $body,
		];
	}
}
