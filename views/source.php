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
			<div class="source-code" data-target="<?= $target ?>">
				<code class="source-raw <?= $lang ? 'language-' . htmlspecialchars($lang) : '' ?>"><?= htmlspecialchars(implode("\n", $file['lines'])) ?></code>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php if ($file !== null): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>
	// Highlight the whole file in one pass (so cross-line PHP state, strings and
	// comments colour correctly), then lay it out as a table with one row per
	// source line. Building the gutter ourselves - rather than via a plugin -
	// keeps the line numbers exact and lets the code column wrap cleanly.
	(function () {
		var container = document.querySelector('.source-code');
		if (!container) return;
		var raw = container.querySelector('.source-raw');
		var target = parseInt(container.getAttribute('data-target'), 10) || 0;

		if (window.hljs) {
			hljs.highlightElement(raw);
		}

		// Split the highlighted markup into per-line fragments, reopening any
		// <span> that straddles a newline so every line is valid on its own.
		var html = raw.innerHTML;
		var lines = [''];
		var open = [];
		var re = /<\/span>|<span\b[^>]*>|[^<]+/g, m;
		while ((m = re.exec(html))) {
			var tok = m[0];
			if (tok === '</span>') {
				open.pop();
				lines[lines.length - 1] += tok;
			} else if (tok.charAt(0) === '<') {
				open.push(tok);
				lines[lines.length - 1] += tok;
			} else {
				var parts = tok.split('\n');
				for (var p = 0; p < parts.length; p++) {
					if (p > 0) {
						for (var c = open.length - 1; c >= 0; c--) lines[lines.length - 1] += '</span>';
						lines.push('');
						for (var o = 0; o < open.length; o++) lines[lines.length - 1] += open[o];
					}
					lines[lines.length - 1] += parts[p];
				}
			}
		}
		// highlight.js may leave a trailing empty line from the final newline.
		if (lines.length > 1 && lines[lines.length - 1] === '') lines.pop();

		var rows = '';
		for (var i = 0; i < lines.length; i++) {
			var n = i + 1;
			var isTarget = n === target;
			var code = lines[i] === '' ? ' ' : lines[i];
			rows += '<tr class="src-row' + (isTarget ? ' is-target' : '') + '"' +
				(isTarget ? ' id="L' + n + '"' : '') + '>' +
				'<td class="src-num">' + n + '</td>' +
				'<td class="src-line hljs">' + code + '</td></tr>';
		}
		// A colgroup fixes the gutter/code column widths so table-layout: fixed
		// doesn't infer them from the first row (which may be a colspan expander).
		container.innerHTML = '<table class="src-tbl">' +
			'<colgroup><col class="src-col-num"><col></colgroup>' +
			'<tbody>' + rows + '</tbody></table>';

		// Collapse to a window around the hook, GitHub-style: hide everything
		// outside target ± CONTEXT and offer expanders to reveal more in chunks.
		var CONTEXT = 20, CHUNK = 20;
		var tbody = container.querySelector('tbody');
		var rowEls = Array.prototype.slice.call(tbody.querySelectorAll('tr.src-row'));

		if (target && rowEls.length > CONTEXT * 2 + 1) {
			rowEls.forEach(function (tr, idx) {
				var n = idx + 1;
				if (n < target - CONTEXT || n > target + CONTEXT) {
					tr.style.display = 'none';
				}
			});

			var addExpander = function (where) {
				var hidden = function () {
					return rowEls.filter(function (tr) {
						var n = rowEls.indexOf(tr) + 1;
						var inSegment = where === 'top' ? n < target - CONTEXT : n > target + CONTEXT;
						return inSegment && tr.style.display === 'none';
					});
				};
				if (!hidden().length) return;

				var tr = document.createElement('tr');
				tr.className = 'src-expander';
				var td = document.createElement('td');
				td.colSpan = 2;
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'src-expand-btn';
				td.appendChild(btn);
				tr.appendChild(td);

				var refresh = function () {
					var h = hidden();
					if (!h.length) { tr.remove(); return; }
					btn.textContent = '↕  Show ' + Math.min(CHUNK, h.length) + ' more lines';
				};
				btn.addEventListener('click', function () {
					var h = hidden();
					var take = where === 'top' ? h.slice(-CHUNK) : h.slice(0, CHUNK);
					take.forEach(function (t) { t.style.display = ''; });
					refresh();
				});
				refresh();

				if (where === 'top') {
					tbody.insertBefore(tr, tbody.firstChild);
				} else {
					tbody.appendChild(tr);
				}
			};

			addExpander('top');
			addExpander('bottom');
		}

		var hit = document.getElementById('L' + target);
		if (hit) hit.scrollIntoView({ block: 'center' });
	})();
</script>
<?php endif; ?>
