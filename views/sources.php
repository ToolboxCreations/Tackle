<?php

declare(strict_types=1);

// $allowManagement passed by the router
$allowManagement = $allowManagement ?? false;
?>

<div class="sources-page" x-data="tackleBox(<?= $allowManagement ? 'true' : 'false' ?>)" x-init="load()">
	<header class="page-header">
		<div>
			<h1>The Tackle Box</h1>
			<p class="page-sub">Every WordPress install, plugin, and theme currently indexed.</p>
		</div>
		<div class="header-actions">
			<a href="/browse" class="btn btn-sm" x-show="canManage">Add from directory</a>
			<button class="btn btn-sm" @click="discover()" x-show="canManage" :disabled="discovering"
				x-text="discovering ? 'Scanning…' : 'Scan disk'"></button>
			<button class="btn btn-sm" @click="checkAll()" x-show="canManage && hasManaged" :disabled="checkingAll"
				x-text="checkingAll ? 'Checking…' : 'Check for updates'"></button>
		</div>
	</header>

	<section class="discover-panel" x-show="canManage && discovered.length" x-transition>
		<h2>Found on disk</h2>
		<p class="page-sub">These plugins and themes are in <code>wp-content</code> but not yet in the box. Add one to cast it - no upload needed.</p>
		<div class="table-wrap">
			<table class="data-table">
				<tbody>
					<template x-for="d in discovered" :key="d.type + '/' + d.folder">
						<tr>
							<td>
								<strong x-text="d.name"></strong>
								<span class="path-hint" x-text="d.folder"></span>
							</td>
							<td><span class="tag" :class="'tag-' + d.type" x-text="d.type"></span></td>
							<td><span x-text="d.version || '-'"></span></td>
							<td>
								<div class="row-actions">
									<button class="link-btn link-accent" @click="add(d)" :disabled="d._busy"
										x-text="d._busy ? 'Adding…' : 'Add'"></button>
									<button class="link-btn link-danger" @click="deleteDisk(d)" :disabled="d._busy">Delete</button>
									<span class="row-msg" x-show="d._msg" x-text="d._msg"></span>
								</div>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
	</section>

	<div x-show="loading" class="results-count">Opening the tackle box…</div>

	<template x-if="!loading && !sources.length">
		<div class="empty-state">
			<p class="empty-title">The box is empty.</p>
			<p x-show="canManage">Head to <a href="/browse">Browse</a> to add a plugin or theme from WordPress.org.</p>
			<p x-show="!canManage">Add a source in <code>config.php</code>, then run <code>php bin/index.php</code>.</p>
		</div>
	</template>

	<div class="table-wrap" x-show="!loading && sources.length">
		<table class="data-table">
			<thead>
				<tr>
					<th>Name</th>
					<th>Type</th>
					<th>Version</th>
					<th class="num">Hooks</th>
					<th>Last cast</th>
					<th x-show="canManage">Manage</th>
				</tr>
			</thead>
			<tbody>
				<template x-for="s in sources" :key="s.id">
					<tr>
						<td>
							<strong x-text="s.name"></strong>
							<span class="path-hint" x-text="s.path"></span>
							<span class="warn-hint" x-show="!s.exists">Files missing on disk</span>
						</td>
						<td><span class="tag" :class="'tag-' + s.type" x-text="s.type"></span></td>
						<td>
							<span x-text="s.version || '-'"></span>
							<span class="update-badge" x-show="s.has_update"
								x-text="'→ ' + s.latest_version"></span>
						</td>
						<td class="num" x-text="Number(s.hook_count).toLocaleString()"></td>
						<td x-text="s.last_indexed_at ? formatDate(s.last_indexed_at) : '-'"></td>
						<td x-show="canManage">
							<div class="row-actions">
								<button class="link-btn" @click="act('recast', s)" :disabled="s._busy">Re-cast</button>

								<template x-if="s.managed">
									<button class="link-btn" @click="act('check', s)" :disabled="s._busy">Check</button>
								</template>
								<template x-if="s.managed && s.has_update">
									<button class="link-btn link-accent" @click="act('update', s)" :disabled="s._busy">Update</button>
								</template>

								<template x-if="s.managed">
									<label class="toggle toggle-sm">
										<input type="checkbox" :checked="s.auto_recast"
											@change="toggleAuto(s, $event.target.checked)">
										<span>Auto</span>
									</label>
								</template>

								<button class="link-btn link-danger" @click="remove(s)" :disabled="s._busy">Remove</button>
								<span class="row-msg" x-show="s._msg" x-text="s._msg"></span>
							</div>
						</td>
					</tr>
				</template>
			</tbody>
		</table>
	</div>

	<section class="help-panel">
		<h2>How sources work</h2>
		<p>Anything you add from <a href="/browse">Browse</a> is downloaded into <code>wp-content</code> and indexed automatically - no WordPress admin needed. <strong>Re-cast</strong> re-reads the files on disk; <strong>Update</strong> pulls the latest release from WordPress.org and re-indexes. Turn on <strong>Auto</strong> to keep a source flagged for refresh.</p>
		<p>You can still manage sources from the command line:</p>
		<pre class="code-block"><code>php bin/index.php                       # index everything in config.php
php bin/index.php --source="WordPress Core"
php bin/index.php --clear               # rebuild the whole box</code></pre>
	</section>

	<div class="toast" x-show="toast" x-transition x-text="toast" @click="toast = ''"></div>
</div>

<script>
	function tackleBox(canManage) {
		return {
			canManage,
			sources: [],
			discovered: [],
			loading: true,
			checkingAll: false,
			discovering: false,
			toast: '',

			get hasManaged() {
				return this.sources.some(s => s.managed);
			},

			load() {
				fetch('/api/sources')
					.then(r => r.json())
					.then(data => {
						this.sources = (data.sources || []).map(s => ({
							...s,
							_busy: false,
							_msg: ''
						}));
						this.loading = false;
					})
					.catch(() => {
						this.loading = false;
					});
			},

			formatDate(s) {
				const d = new Date(s.replace(' ', 'T'));
				return isNaN(d) ? s : d.toLocaleString(undefined, {
					month: 'short',
					day: 'numeric',
					hour: '2-digit',
					minute: '2-digit'
				});
			},

			post(action, payload) {
				return fetch('/api/sources/' + action, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify(payload),
				}).then(r => r.json());
			},

			act(action, s) {
				s._busy = true;
				s._msg = '…';
				this.post(action, {
					id: s.id
				}).then(data => {
					s._busy = false;
					s._msg = '';
					this.flash(data.message);
					this.load();
				}).catch(() => {
					s._busy = false;
					s._msg = '';
				});
			},

			toggleAuto(s, enabled) {
				this.post('auto', {
					id: s.id,
					enabled
				}).then(data => {
					s.auto_recast = enabled;
					this.flash(data.message);
				});
			},

			remove(s) {
				const installed = s.origin === 'wporg' || s.origin === 'upload';
				if (!confirm('Remove "' + s.name + '" from the tackle box?' + (installed ? '\n\nClick OK to also delete the downloaded files.' : ''))) return;
				s._busy = true;
				this.post('remove', {
					id: s.id,
					deleteFiles: installed
				}).then(data => {
					this.flash(data.message);
					this.load();
				});
			},

			discover() {
				this.discovering = true;
				fetch('/api/discover')
					.then(r => r.json())
					.then(data => {
						this.discovered = (data.items || []).map(d => ({
							...d,
							_busy: false,
							_msg: ''
						}));
						this.discovering = false;
						this.flash(this.discovered.length
							? this.discovered.length + ' found on disk.'
							: 'Nothing new on disk - everything is in the box.');
					})
					.catch(() => {
						this.discovering = false;
					});
			},

			add(d) {
				d._busy = true;
				this.post('cast-disk', {
					type: d.type,
					folder: d.folder
				}).then(data => {
					d._busy = false;
					this.flash(data.message);
					if (data.ok) {
						this.discovered = this.discovered.filter(x => x !== d);
						this.load();
					} else {
						d._msg = data.message;
					}
				}).catch(() => {
					d._busy = false;
				});
			},

			deleteDisk(d) {
				if (!confirm('Permanently delete "' + d.name + '" from disk?\n\nThis removes wp-content/' + (d.type === 'theme' ? 'themes' : 'plugins') + '/' + d.folder + ' and cannot be undone.')) return;
				d._busy = true;
				this.post('delete-disk', {
					type: d.type,
					folder: d.folder
				}).then(data => {
					d._busy = false;
					this.flash(data.message);
					if (data.ok) {
						this.discovered = this.discovered.filter(x => x !== d);
					} else {
						d._msg = data.message;
					}
				}).catch(() => {
					d._busy = false;
				});
			},

			checkAll() {
				this.checkingAll = true;
				const managed = this.sources.filter(s => s.managed);
				Promise.all(managed.map(s => this.post('check', {
						id: s.id
					})))
					.then(() => {
						this.checkingAll = false;
						this.load();
						this.flash('Checked for updates.');
					})
					.catch(() => {
						this.checkingAll = false;
					});
			},

			flash(msg) {
				this.toast = msg;
				clearTimeout(this._t);
				this._t = setTimeout(() => this.toast = '', 4000);
			},
		}
	}
</script>
