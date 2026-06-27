<?php

declare(strict_types=1);

// $hook is the hook row; $file is ['lines' => string[], 'path' => string] or
// null when the file couldn't be located on this machine.
$target = (int)$hook['line_number'];
$hookId = (int)$hook['id'];

// Map the file extension to a highlight.js language so the whole file is
// tokenised in one pass (PHP state, multi-line strings and comments all span
// lines, so per-line highlighting would mis-colour). Unknown extensions fall
// back to highlight.js auto-detection.
$ext = strtolower(pathinfo($hook['file_path'] ?? '', PATHINFO_EXTENSION));
$langMap = [
	'php' => 'php', 'inc' => 'php',
	'js' => 'javascript', 'mjs' => 'javascript', 'jsx' => 'javascript',
	'ts' => 'typescript', 'tsx' => 'typescript',
	'css' => 'css', 'scss' => 'scss', 'less' => 'less',
	'json' => 'json', 'html' => 'xml', 'xml' => 'xml',
	'sql' => 'sql', 'sh' => 'bash', 'yml' => 'yaml', 'yaml' => 'yaml',
];
$lang = $langMap[$ext] ?? '';
?>

<div class="source-view">
	<a href="/hook?id=<?= $hookId ?>" class="back-link">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
		     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<path d="m15 18-6-6 6-6"/>
		</svg>
		Back to <?= htmlspecialchars($hook['name']) ?>
	</a>

	<div class="hook-detail-card">
		<header class="source-view-header">
			<div class="hook-name-row">
				<code class="hook-name-big"><?= htmlspecialchars($hook['name']) ?></code>
				<span class="hook-type <?= htmlspecialchars($hook['type']) ?>"><?= htmlspecialchars($hook['type']) ?></span>
			</div>
			<p class="source-view-path mono">
				<?= htmlspecialchars($hook['file_path']) ?><span class="source-view-lineno">:<?= $target ?></span>
			</p>
		</header>

		<?php if ($file === null): ?>
			<div class="source-view-missing">
				<p>The source file for this hook isn't available on this machine.</p>
				<p class="mono"><?= htmlspecialchars($hook['file_path']) ?>:<?= $target ?></p>
				<p>This usually means the index was built somewhere else (for example
					inside Docker) and the files aren't present where the site is
					running. Re-cast the source here to view its code.</p>
			</div>
		<?php else: ?>
			<pre class="source-code"><code class="<?= $lang ? 'language-' . htmlspecialchars($lang) : '' ?>"><?= htmlspecialchars(implode("\n", $file['lines'])) ?></code></pre>
		<?php endif; ?>
	</div>
</div>

<?php if ($file !== null): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlightjs-line-numbers.js/2.8.0/highlightjs-line-numbers.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlightjs-line-numbers.js/2.8.0/highlightjs-line-numbers.min.js"></script>
<script>
	// Highlight the whole file in one pass, add a line-number gutter, then mark
	// and centre the line the hook is defined on.
	(function () {
		var target = <?= $target ?>;
		var block = document.querySelector('.source-code code');
		if (!block) return;

		function scrollToTarget() {
			var cell = block.closest('.source-code')
				.querySelector('.hljs-ln-line[data-line-number="' + target + '"]');
			if (!cell) return;
			var row = cell.parentElement;
			row.classList.add('is-target');
			row.scrollIntoView({ block: 'center' });
		}

		if (window.hljs) {
			hljs.highlightElement(block);
			if (typeof hljs.lineNumbersBlock === 'function') {
				// The plugin builds the gutter asynchronously; mark the target
				// once it has finished writing the rows.
				hljs.lineNumbersBlock(block, { singleLine: true });
				var tries = 0;
				var timer = setInterval(function () {
					if (block.querySelector('.hljs-ln-numbers') || tries++ > 40) {
						clearInterval(timer);
						scrollToTarget();
					}
				}, 25);
			}
		}
	})();
</script>
<?php endif; ?>
