<?php

declare(strict_types=1);

// Active nav state - the router sets $content; $path may be passed through.
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

/** Minimal inline hook mark - drawn, not an emoji. Uses currentColor. */
function tackle_logo_mark(): string
{
	return <<<'SVG'
<svg class="logo-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <circle cx="7" cy="4.5" r="2.2"/>
  <path d="M7 6.7 V13 a6 6 0 0 0 12 0 v-2.3"/>
  <path d="M16.4 12.4 19 10.7 21.4 12.7"/>
</svg>
SVG;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Tackle - WordPress hook index</title>
	<meta name="description" content="Search every action and filter in WordPress core, plugins, and themes.">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/public/style.css">
	<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>

<body>
	<header class="navbar">
		<div class="navbar-container">
			<a class="brand" href="/">
				<?= tackle_logo_mark() ?>
				<span class="brand-name">Tackle</span>
				<span class="brand-tag">WordPress hook index</span>
			</a>
			<nav>
				<a href="/" class="<?= $currentPath === '/' ? 'is-active' : '' ?>">Search</a>
				<a href="/browse" class="<?= $currentPath === '/browse' ? 'is-active' : '' ?>">Browse</a>
				<a href="/sources" class="<?= $currentPath === '/sources' ? 'is-active' : '' ?>">Tackle Box</a>
			</nav>
		</div>
	</header>

	<main>
		<?php
		if (isset($content)) {
			echo $content;
		}
		?>
	</main>

	<footer>
		<div class="footer-inner">
			<span>Tackle - A local-first index for WordPress actions and filters.</span>
		</div>
	</footer>

	<!-- Global recast progress. Every tab polls the shared status file, so a
	     recast started anywhere is visible everywhere until it finishes. -->
	<div class="recast-monitor" x-data="recastMonitor()" x-init="start()" x-show="visible" x-cloak
		x-transition :class="{ 'is-done': !active }">
		<div class="recast-monitor-row">
			<span class="recast-spinner" x-show="active"></span>
			<strong x-text="title"></strong>
			<span class="recast-origin" x-show="origin" x-text="origin"></span>
		</div>
		<div class="recast-bar" x-show="active">
			<div class="recast-bar-fill" :class="{ 'is-indeterminate': total === 0 }"
				:style="total ? ('width:' + pct + '%') : ''"></div>
		</div>
		<span class="recast-sub" x-text="sub"></span>
	</div>

	<script>
		function recastMonitor() {
			return {
				visible: false,
				active: false,
				title: '',
				sub: '',
				origin: '',
				total: 0,
				done: 0,
				pct: 0,
				_lastFinish: 0,
				_hideTimer: null,

				// Friendly label for who kicked the recast off.
				originLabel(initiator) {
					return {
						user: 'from User',
						mcp: 'via MCP',
						scheduler: 'scheduled',
						cli: 'from CLI',
					} [initiator] || '';
				},

				start() {
					this.poll();
					setInterval(() => this.poll(), 1500);
				},

				poll() {
					fetch('/api/recast/status')
						.then(r => r.json())
						.then(s => this.apply(s))
						.catch(() => {});
				},

				apply(s) {
					if (s && s.active) {
						clearTimeout(this._hideTimer);
						this.active = true;
						this.visible = true;
						this.total = s.total || 0;
						this.done = s.done || 0;
						this.pct = this.total ? Math.round(this.done / this.total * 100) : 0;
						this.title = 'Recasting ' + (s.source || '…');
						this.origin = this.originLabel(s.initiator);
						this.sub = this.buildSub(s);
						return;
					}

					// A fresh completion (within the last 10s, not one we've already
					// shown) gets a brief confirmation; stale ones never resurface on
					// a page load.
					const fresh = s && s.finished_at &&
						s.finished_at !== this._lastFinish &&
						(Date.now() / 1000 - s.finished_at) < 10;

					if (fresh) {
						this._lastFinish = s.finished_at;
						this.active = false;
						this.visible = true;
						this.total = 0;
						this.title = s.ok === false ? 'Recast failed' : 'Recast complete';
						this.origin = this.originLabel(s.initiator);
						this.sub = s.message || '';
						clearTimeout(this._hideTimer);
						this._hideTimer = setTimeout(() => {
							this.visible = false;
						}, 5000);
					} else if (!this.active && !this._hideTimer) {
						this.visible = false;
					}
				},

				buildSub(s) {
					if (!s.total) {
						return 'Starting…';
					}
					const counts = this.done.toLocaleString() + ' / ' +
						this.total.toLocaleString() + ' files (' + this.pct + '%)';
					const elapsed = (s.updated_at || 0) - (s.started_at || 0);
					if (this.done > 0 && elapsed > 0) {
						const left = Math.round(elapsed * (this.total - this.done) / this.done);
						return counts + ' · ' + (left > 0 ? '~' + left + 's left' : 'almost done');
					}
					return counts;
				},
			};
		}
	</script>
</body>

</html>
