<?php

declare(strict_types=1);

class Parser
{
	private string $filePath;
	private string $content;
	private array $lines;

	public function parseFile(string $filePath): array
	{
		if (!file_exists($filePath)) {
			return [];
		}

		$this->filePath = $filePath;
		
		// Skip very large files (> 10 MB) to prevent memory exhaustion
		$fileSize = filesize($filePath);
		if ($fileSize > 10 * 1024 * 1024) {
			return [];
		}

		$this->content = file_get_contents($filePath);
		if ($this->content === false) {
			return [];
		}

		$this->lines = explode("\n", $this->content);

		$hooks = [];

		// Pattern to match do_action, apply_filters, and their *_ref_array variants
		$pattern = '/(?:do_action|apply_filters)(?:_ref_array)?\s*\(\s*(["\']?)([^"\'(),]*?)\1(?:\s|,|$|\))/i';

		if (preg_match_all($pattern, $this->content, $matches, PREG_OFFSET_CAPTURE)) {
			foreach ($matches[0] as $index => $match) {
				$hookName = $matches[2][$index][0] ?? '';
				$position = $match[1];
				$lineNumber = $this->getLineNumber($position);

				if ($hookName === '') {
					// Try to parse dynamic hook names
					$dynamicHook = $this->parseDynamicHook($position);
					if ($dynamicHook) {
						$hooks[] = $dynamicHook;
					}
					continue;
				}

				$hookType = strpos($match[0], 'apply_filters') !== false ? 'filter' : 'action';

				// A quoted name can still be dynamic: "save_post_{$post->post_type}"
				// or "{$prefix}_loaded" interpolate a variable at runtime. Anything
				// containing an unresolved variable ($) or brace expression ({...})
				// is a pattern, not a fixed hook name.
				$isDynamic = strpos($hookName, '$') !== false || strpos($hookName, '{') !== false;
				$quote = $matches[1][$index][0] ?? '';

				$hook = [
					'name' => $hookName,
					'name_normalized' => $this->normalizeHookName($hookName),
					'type' => $hookType,
					'is_dynamic' => $isDynamic ? 1 : 0,
					'raw_expression' => $isDynamic ? ($quote . $hookName . $quote) : null,
					'file_path' => $this->filePath,
					'line_number' => $lineNumber,
					'docblock' => $this->extractDocblock($lineNumber),
					'parameters' => [],
					'since_version' => null,
					'deprecated' => 0,
				];

				$hooks[] = $hook;
			}
		}

		// Free memory
		unset($this->content);
		unset($this->lines);

		return $hooks;
	}

	/**
	 * Extract hook *consumers* - add_action() / add_filter() registrations - from
	 * a file. This is the mirror image of parseFile(), which finds where hooks
	 * fire; here we find what is already hooked into them, and at what priority.
	 *
	 * Uses PHP's tokenizer rather than a regex because we need to split the call's
	 * arguments correctly (the priority is the 3rd argument, the accepted-arg count
	 * the 4th) even when earlier arguments contain commas - closures, arrays, or
	 * nested calls like array( $this, 'method' ).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function parseCallers(string $filePath): array
	{
		if (!file_exists($filePath)) {
			return [];
		}

		$fileSize = filesize($filePath);
		if ($fileSize > 10 * 1024 * 1024) {
			return [];
		}

		$content = file_get_contents($filePath);
		if ($content === false) {
			return [];
		}

		$tokens = token_get_all($content);
		$count = count($tokens);
		$callers = [];

		for ($i = 0; $i < $count; $i++) {
			$tok = $tokens[$i];
			if (!is_array($tok) || $tok[0] !== T_STRING) {
				continue;
			}

			$fn = strtolower($tok[1]);
			if ($fn !== 'add_action' && $fn !== 'add_filter') {
				continue;
			}

			// Skip method calls/definitions: $obj->add_action(), Foo::add_action(),
			// function add_action(). We only want the global function call.
			$prev = $this->prevSignificant($tokens, $i);
			if ($prev !== null && is_array($prev)) {
				$prevText = $prev[1];
				if ($prevText === '->' || $prevText === '?->' || $prevText === '::' || $prev[0] === T_FUNCTION) {
					continue;
				}
			}

			$open = $this->nextSignificantIndex($tokens, $i);
			if ($open === null || $tokens[$open] !== '(') {
				continue;
			}

			$args = $this->collectArgs($tokens, $open);
			if ($args === null || count($args) < 2) {
				// A real registration needs at least a hook name and a callback.
				continue;
			}

			$nameExpr = trim($args[0]);
			$literal = $this->stringLiteral($nameExpr);

			if ($literal !== null) {
				$name = $literal;
				$normalized = $this->normalizeHookName($literal);
				$isDynamic = 0;
				$rawExpression = null;
			} else {
				$normalized = $this->normalizeDynamicName($nameExpr);
				if (strlen($normalized) < 3) {
					continue;
				}
				$name = $normalized;
				$isDynamic = 1;
				$rawExpression = $nameExpr;
			}

			$callers[] = [
				'hook_name'            => $name,
				'hook_name_normalized' => $normalized,
				'type'                 => $fn === 'add_filter' ? 'filter' : 'action',
				'is_dynamic'           => $isDynamic,
				'raw_expression'       => $rawExpression,
				'callback'             => $this->tidy($args[1] ?? '') ?: null,
				'priority'             => isset($args[2]) ? $this->tidy($args[2]) : null,
				'accepted_args'        => isset($args[3]) ? $this->tidy($args[3]) : null,
				'file_path'            => $filePath,
				'line_number'          => $tok[2],
			];
		}

		return $callers;
	}

	/**
	 * Return the previous semantically significant token (skipping whitespace and
	 * comments), or null at the start of the stream.
	 *
	 * @param array<int, mixed> $tokens
	 * @return array{0:int,1:string,2:int}|string|null
	 */
	private function prevSignificant(array $tokens, int $index)
	{
		for ($k = $index - 1; $k >= 0; $k--) {
			$t = $tokens[$k];
			if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			return $t;
		}
		return null;
	}

	/**
	 * Index of the next significant token after $index, or null.
	 *
	 * @param array<int, mixed> $tokens
	 */
	private function nextSignificantIndex(array $tokens, int $index): ?int
	{
		$count = count($tokens);
		for ($k = $index + 1; $k < $count; $k++) {
			$t = $tokens[$k];
			if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			return $k;
		}
		return null;
	}

	/**
	 * Given the index of a call's opening "(", reconstruct each top-level argument
	 * as raw source text, splitting only on commas that sit directly inside the
	 * call (depth 1). Returns null if the parentheses never balance.
	 *
	 * @param array<int, mixed> $tokens
	 * @return array<int, string>|null
	 */
	private function collectArgs(array $tokens, int $openIndex): ?array
	{
		$count = count($tokens);
		$depth = 0;
		$args = [];
		$current = '';

		for ($k = $openIndex; $k < $count; $k++) {
			$t = $tokens[$k];
			$text = is_array($t) ? $t[1] : $t;

			// Opening delimiters increase nesting depth.
			if ($text === '(' || $text === '[') {
				$depth++;
				// The call's own opening paren is structural, not part of an argument.
				if (!($depth === 1 && $text === '(')) {
					$current .= $text;
				}
				continue;
			}
			if ($text === '{' || (is_array($t) && in_array($t[0], $this->curlyOpenTokens(), true))) {
				$depth++;
				$current .= $text;
				continue;
			}

			// Closing delimiters decrease it; reaching 0 ends the argument list.
			if ($text === ')' || $text === ']' || $text === '}') {
				$depth--;
				if ($depth === 0) {
					$args[] = trim($current);
					return $args;
				}
				$current .= $text;
				continue;
			}

			if ($text === ',' && $depth === 1) {
				$args[] = trim($current);
				$current = '';
				continue;
			}

			if ($depth >= 1) {
				$current .= $text;
			}
		}

		return null;
	}

	/**
	 * Curly-open token ids that exist on the running PHP version (string
	 * interpolation: "{$x}" / "${x}"). Resolved defensively so the parser keeps
	 * working across versions.
	 *
	 * @return array<int, int>
	 */
	private function curlyOpenTokens(): array
	{
		$ids = [];
		if (defined('T_CURLY_OPEN')) {
			$ids[] = T_CURLY_OPEN;
		}
		if (defined('T_DOLLAR_OPEN_CURLY_BRACES')) {
			$ids[] = T_DOLLAR_OPEN_CURLY_BRACES;
		}
		return $ids;
	}

	/**
	 * If an expression is a single plain string literal (no interpolation /
	 * concatenation), return its contents; otherwise null.
	 */
	private function stringLiteral(string $expr): ?string
	{
		if (preg_match("/^'([^']*)'$/", $expr, $m)) {
			return $m[1];
		}
		// Double-quoted is only "static" when it has no interpolation.
		if (preg_match('/^"([^"\\\\$]*)"$/', $expr, $m)) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Collapse runtime-built hook names ("et_pb_{$module}_shortcode") to a stable
	 * pattern with "*" standing in for the variable parts.
	 */
	private function normalizeDynamicName(string $expr): string
	{
		$s = preg_replace('/\{[^}]*\}/', '*', $expr) ?? $expr;
		$s = preg_replace('/\$\w+(?:->\w+|\[[^\]]*\])*/', '*', $s) ?? $s;
		$s = str_replace(['"', "'", '.'], '', $s);
		$s = preg_replace('/\s+/', '', $s) ?? $s;
		$s = preg_replace('/\*+/', '*', $s) ?? $s;
		return strtolower($s);
	}

	/**
	 * Normalise a raw argument for storage/display: collapse whitespace to single
	 * spaces and cap the length so a giant inline closure can't bloat the index.
	 */
	private function tidy(string $value): string
	{
		$value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
		if (strlen($value) > 300) {
			$value = substr($value, 0, 297) . '...';
		}
		return $value;
	}

	private function getLineNumber(int $bytePosition): int
	{
		return substr_count($this->content, "\n", 0, $bytePosition) + 1;
	}

	private function normalizeHookName(string $name): string
	{
		// Replace any dynamic parts with *
		$normalized = preg_replace('/\$\w+|{[^}]*}/', '*', $name);
		return strtolower($normalized ?? $name);
	}

	private function parseDynamicHook(int $position): ?array
	{
		// Extract context around the position
		$start = max(0, $position - 200);
		$end = min(strlen($this->content), $position + 500);
		$context = substr($this->content, $start, $end - $start);

		// Try to match the full function call including dynamic hook names
		if (preg_match('/(?:do_action|apply_filters)(?:_ref_array)?\s*\(\s*([^,)]+)/i', $context, $match)) {
			$expr = trim($match[1]);

			// Check if it's actually dynamic
			if (strpos($expr, '$') !== false || strpos($expr, '.') !== false || strpos($expr, '"') !== false) {
				// Extract a normalized version
				$normalized = preg_replace('/[\$\.\s"\'{}]/', '', $expr);
				$normalized = strtolower(preg_replace('/[^a-z0-9_]/', '*', $normalized));

				if (strlen($normalized) < 3) {
					return null;
				}

				$lineNumber = $this->getLineNumber($position);
				$hookType = strpos($context, 'apply_filters') !== false ? 'filter' : 'action';

				return [
					'name' => $normalized,
					'name_normalized' => $normalized,
					'type' => $hookType,
					'is_dynamic' => 1,
					'raw_expression' => $expr,
					'file_path' => $this->filePath,
					'line_number' => $lineNumber,
					'docblock' => $this->extractDocblock($lineNumber),
					'parameters' => [],
					'since_version' => null,
					'deprecated' => 0,
				];
			}
		}

		return null;
	}

	private function extractDocblock(int $lineNumber): ?string
	{
		$docblock = '';
		$lineIndex = $lineNumber - 1;

		// Look back up to 5 lines for PHPDoc comment
		for ($i = $lineIndex - 1; $i >= max(0, $lineIndex - 5); $i--) {
			$line = trim($this->lines[$i] ?? '');

			if (str_starts_with($line, '*/')) {
				// Found the end of a docblock, collect from here upwards
				$docblock = '';
				for ($j = $i; $j >= max(0, $i - 20); $j--) {
					$docLine = $this->lines[$j] ?? '';
					$docblock = $docLine . "\n" . $docblock;
					if (str_contains($docLine, '/*')) {
						break;
					}
				}
				return trim($docblock) ?: null;
			}

			if ($line === '' || str_starts_with($line, '*')) {
				continue;
			}

			// Non-comment line found before docblock
			break;
		}

		return null;
	}
}

