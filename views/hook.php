<?php

declare(strict_types=1);

// $hook is set by the router. Parse the stored raw docblock into a clean
// description and a structured @param list for display.
$doc = DocBlock::parse($hook['docblock'] ?? null);

// Parameters extracted from the docblock take precedence; fall back to any
// pre-parsed JSON stored on the hook row.
$parameters = $doc['params'];
if (!$parameters && !empty($hook['parameters'])) {
	$parameters = json_decode($hook['parameters'], true) ?? [];
}

// Prefer an @since tag from the docblock over the stored column.
$since = $hook['since_version'] ?: $doc['since'];
?>

<div class="hook-detail">
	<a href="/" class="back-link">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
		     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<path d="m15 18-6-6 6-6"/>
		</svg>
		Back to search
	</a>

	<div class="hook-detail-card">
		<header class="hook-detail-header">
			<div class="hook-meta-grid">
				<div>
					<span class="meta-label">Source</span>
					<span class="meta-value"><?= htmlspecialchars($hook['source_name']) ?></span>
				</div>
				<?php if ($since): ?>
					<div>
						<span class="meta-label">Since</span>
						<span class="meta-value"><?= htmlspecialchars($since) ?></span>
					</div>
				<?php endif; ?>
			</div>

			<div class="hook-name-row">
				<code class="hook-name-big"><?= htmlspecialchars($hook['name']) ?></code>
				<span class="hook-type <?= htmlspecialchars($hook['type']) ?>"><?= htmlspecialchars($hook['type']) ?></span>
				<?php if ($hook['is_dynamic']): ?>
					<span class="hook-dynamic" title="This hook's name is built at runtime and may vary">dynamic</span>
				<?php endif; ?>
				<?php if ($hook['deprecated'] || $doc['deprecated']): ?>
					<span class="hook-deprecated">deprecated</span>
				<?php endif; ?>
			</div>

			<?php if ($doc['description'] !== ''): ?>
				<p class="hook-description"><?= nl2br(htmlspecialchars($doc['description'])) ?></p>
			<?php endif; ?>
		</header>

		<div class="hook-detail-body">
			<section x-data="{ copied: false }">
				<h2>Usage</h2>
				<div class="code-block-wrap">
					<button class="btn-copy" @click="navigator.clipboard.writeText($refs.snippet.textContent); copied = true; setTimeout(() => copied = false, 1500)">
						<span x-text="copied ? 'Copied' : 'Copy'"></span>
					</button>
					<pre class="code-block"><code x-ref="snippet"><?php
						$fn = $hook['type'] === 'action' ? 'add_action' : 'add_filter';

						// Build the callback signature from the documented parameters,
						// so the snippet is ready to fill in rather than a bare stub.
						$argNames = [];
						foreach ($parameters as $param) {
							$name = trim($param['name'] ?? '');
							if ($name === '') {
								continue;
							}
							$argNames[] = str_starts_with($name, '$') ? $name : '$' . $name;
						}
						$argCount = count($argNames);
						$signature = $argCount ? ' ' . implode(', ', $argNames) . ' ' : '';

						// add_action()/add_filter() only need the priority + arg count
						// when the callback actually receives arguments.
						$register = $argCount
							? "$fn( '{$hook['name']}', 'your_function', 10, $argCount );"
							: "$fn( '{$hook['name']}', 'your_function' );";

						$snippet = $register . "\n\n";
						$snippet .= "function your_function($signature) {\n";
						$snippet .= "\t// your code here\n";
						if ($hook['type'] === 'filter') {
							// A filter must return a value; default to the first argument.
							$returnVar = $argNames[0] ?? '$value';
							$snippet .= "\n\treturn $returnVar;\n";
						}
						$snippet .= "}";

						echo htmlspecialchars($snippet);
					?></code></pre>
				</div>
			</section>

			<?php if (!empty($parameters)): ?>
				<section>
					<h2>Parameters</h2>
					<table class="data-table">
						<thead>
							<tr><th>Name</th><th>Type</th><th>Description</th></tr>
						</thead>
						<tbody>
							<?php foreach ($parameters as $param): ?>
								<tr>
									<td><code><?= htmlspecialchars($param['name'] ?? '') ?></code></td>
									<td><code><?= htmlspecialchars($param['type'] ?? '') ?></code></td>
									<td><?= htmlspecialchars($param['description'] ?? '') ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</section>
			<?php endif; ?>

			<section>
				<h2>Defined in</h2>
				<div class="hook-defined-row">
					<p class="hook-defined mono"><?= htmlspecialchars($hook['file_path']) ?>:<?= (int)$hook['line_number'] ?></p>
					<a class="btn-view-definition" href="/source?id=<?= (int)$hook['id'] ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
						     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
							<circle cx="12" cy="12" r="3"/>
						</svg>
						View definition
					</a>
				</div>
			</section>

			<?php if ($hook['raw_expression']): ?>
				<section>
					<h2>Raw expression</h2>
					<pre class="code-block"><code><?= htmlspecialchars($hook['raw_expression']) ?></code></pre>
				</section>
			<?php endif; ?>
		</div>
	</div>
</div>
