<?php

declare(strict_types=1);

// This page is only served when management is enabled (see the /browse route),
// so every action here is always available - no enable/disable gating needed.
?>

<div class="browse" x-data="browser()" x-init="search()">
	<header class="page-header">
		<h1>Add plugins &amp; themes</h1>
		<p class="page-sub">Search the WordPress.org directory, or upload a zip for anything that isn't there (premium plugins, custom themes). Tackle downloads, extracts, and indexes it for you.</p>
	</header>

	<div class="browse-controls">
		<div class="segmented" role="group" aria-label="Source">
			<button type="button" :class="{ 'is-active': tab === 'plugin' }" @click="setTab('plugin')">Plugins</button>
			<button type="button" :class="{ 'is-active': tab === 'theme' }" @click="setTab('theme')">Themes</button>
			<button type="button" :class="{ 'is-active': tab === 'upload' }" @click="setTab('upload')">Upload a zip</button>
		</div>
		<div class="search-box browse-search" x-show="tab !== 'upload'">
			<svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
				stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<circle cx="11" cy="11" r="7" />
				<path d="m21 21-4.3-4.3" />
			</svg>
			<input type="search" x-model="query" @input.debounce.300ms="search()"
				:placeholder="'Search ' + tab + 's…'" class="search-input" aria-label="Search the directory">
		</div>
	</div>

	<!-- Directory search results -->
	<template x-if="tab !== 'upload'">
		<div>
			<div x-show="loading" class="results-count">Searching the directory…</div>

			<div class="repo-grid" x-show="!loading && results.length">
				<template x-for="item in results" :key="item.slug">
					<div class="repo-card">
						<div class="repo-card-head">
							<template x-if="item.icon">
								<img :src="item.icon" :alt="item.name" class="repo-icon" loading="lazy">
							</template>
							<template x-if="!item.icon">
								<div class="repo-icon repo-icon-fallback" x-text="item.name.charAt(0)"></div>
							</template>
							<div class="repo-card-title">
								<h3 x-text="item.name"></h3>
								<span class="repo-meta" x-text="'v' + item.version + (item.author ? ' · ' + item.author : '')"></span>
							</div>
						</div>
						<p class="repo-desc" x-text="item.short_description"></p>
						<div class="repo-card-foot">
							<span class="repo-installs" x-show="item.active_installs > 0"
								x-text="formatInstalls(item.active_installs) + ' active'"></span>
							<button class="btn btn-sm"
								@click="install(item)"
								x-text="state[item.slug] || 'Add to Tackle'"></button>
						</div>
					</div>
				</template>
			</div>

			<div x-show="!loading && !results.length" class="empty-state">
				<p class="empty-title">No matches.</p>
				<p>Nothing in the directory matched that search.</p>
			</div>
		</div>
	</template>

	<!-- Upload a zip -->
	<template x-if="tab === 'upload'">
		<div class="upload-panel">
			<label class="dropzone" :class="{ 'is-dragging': dragging, 'has-file': file }"
				@dragover.prevent="dragging = true" @dragleave.prevent="dragging = false"
				@drop.prevent="dropFile($event)">
				<input type="file" accept=".zip,application/zip" class="dropzone-input"
					x-ref="fileInput" @change="file = $event.target.files[0] || null">
				<svg class="dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
					stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M12 16V4" />
					<path d="m6 10 6-6 6 6" />
					<path d="M4 20h16" />
				</svg>
				<template x-if="!file">
					<span class="dropzone-text">Drop a <code>.zip</code> here, or <span class="dropzone-link">choose a file</span></span>
				</template>
				<template x-if="file">
					<span class="dropzone-text" x-text="file.name"></span>
				</template>
			</label>

			<div class="upload-type">
				<span class="upload-label">This zip is a</span>
				<div class="segmented" role="group" aria-label="Package type">
					<button type="button" :class="{ 'is-active': uploadType === 'plugin' }" @click="uploadType = 'plugin'">Plugin</button>
					<button type="button" :class="{ 'is-active': uploadType === 'theme' }" @click="uploadType = 'theme'">Theme</button>
				</div>
			</div>

			<div class="upload-actions">
				<button class="btn" :disabled="!file || uploading" @click="upload()"
					x-text="uploading ? 'Working…' : 'Upload &amp; index'"></button>
				<a href="/sources" class="link-btn" x-show="lastAddedOk">View in the Tackle Box →</a>
			</div>

			<!-- Live progress: uploading → unzipping → recasting -->
			<div class="upload-progress" x-show="uploading" x-transition>
				<div class="upload-progress-head">
					<span class="upload-stage" x-text="stageLabel()"></span>
					<span class="upload-stage-pct" x-show="!barIndeterminate()"
						x-text="progress.pct + '%'"></span>
				</div>
				<div class="progress-track">
					<div class="progress-bar" :class="{ 'is-indeterminate': barIndeterminate() }"
						:style="{ width: barIndeterminate() ? null : progress.pct + '%' }"></div>
				</div>
				<span class="upload-stage-detail" x-show="progress.total > 0 && !barIndeterminate()"
					x-text="progress.done.toLocaleString() + ' / ' + progress.total.toLocaleString() + ' files'"></span>
			</div>

			<p class="upload-msg" x-show="uploadMsg && !uploading" x-text="uploadMsg"></p>
		</div>
	</template>

	<div class="toast" x-show="toast" x-transition x-text="toast" @click="toast = ''"></div>
</div>

<script>
	function browser() {
		return {
			tab: 'plugin',
			query: '',
			results: [],
			loading: false,
			busy: {},
			state: {},
			toast: '',

			// upload state
			uploadType: 'plugin',
			file: null,
			uploading: false,
			uploadMsg: '',
			dragging: false,
			lastAddedOk: false,
			progress: {
				stage: '',
				pct: 0,
				done: 0,
				total: 0
			},

			setTab(t) {
				this.tab = t;
				if (t !== 'upload') {
					this.results = [];
					this.search();
				}
			},

			formatInstalls(n) {
				if (n >= 1000000) return (n / 1000000).toFixed(n % 1000000 ? 1 : 0) + 'M+';
				if (n >= 1000) return Math.floor(n / 1000) + 'k+';
				return n + '+';
			},

			search() {
				if (this.tab === 'upload') return;
				this.loading = true;
				const params = new URLSearchParams({
					type: this.tab,
					q: this.query
				});
				fetch('/api/repo/search?' + params)
					.then(r => r.json())
					.then(data => {
						this.results = data.results || [];
						this.loading = false;
					})
					.catch(() => {
						this.loading = false;
					});
			},

			install(item) {
				this.busy[item.slug] = true;
				this.state[item.slug] = 'Adding…';
				fetch('/api/sources/install', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json'
						},
						body: JSON.stringify({
							slug: item.slug,
							type: this.tab
						}),
					})
					.then(r => r.json())
					.then(data => {
						this.busy[item.slug] = false;
						this.state[item.slug] = data.ok ? 'Added' : 'Failed';
						this.flash(data.message);
					})
					.catch(() => {
						this.busy[item.slug] = false;
						this.state[item.slug] = 'Failed';
						this.flash('Something went wrong.');
					});
			},

			dropFile(e) {
				this.dragging = false;
				const f = e.dataTransfer.files[0];
				if (f) {
					this.file = f;
					this.$refs.fileInput.files = e.dataTransfer.files;
				}
			},

			upload() {
				if (!this.file) return;
				this.uploading = true;
				this.uploadMsg = '';
				this.lastAddedOk = false;
				this.progress = {
					stage: 'uploading',
					pct: 0,
					done: 0,
					total: 0
				};

				const fd = new FormData();
				fd.append('type', this.uploadType);
				fd.append('file', this.file);

				const xhr = new XMLHttpRequest();
				xhr.open('POST', '/api/sources/upload');

				// Real progress for the network transfer.
				xhr.upload.onprogress = (e) => {
					if (e.lengthComputable && this.progress.stage === 'uploading') {
						this.progress.pct = Math.round((e.loaded / e.total) * 100);
					}
				};
				// Transfer done; the server is now extracting/indexing. Hold on an
				// indeterminate bar until the first streamed stage event arrives.
				xhr.upload.onload = () => {
					if (this.progress.stage === 'uploading') {
						this.progress = {
							stage: 'processing',
							pct: 100,
							done: 0,
							total: 0
						};
					}
				};

				// The server streams newline-delimited JSON progress events.
				let seen = 0,
					last = null;
				const drain = (toEnd) => {
					const lines = xhr.responseText.split('\n');
					const stop = toEnd ? lines.length : lines.length - 1;
					for (; seen < stop; seen++) {
						const line = lines[seen].trim();
						if (!line) continue;
						try {
							last = JSON.parse(line);
						} catch (_) {
							continue;
						}
						// Progress events carry a stage; the final result does not.
						if (last.stage) this.applyEvent(last);
					}
				};
				xhr.onprogress = () => drain(false);
				xhr.onload = () => {
					drain(true);
					this.finishUpload(last);
				};
				xhr.onerror = () => {
					this.uploading = false;
					this.progress.stage = '';
					this.uploadMsg = 'Upload failed.';
				};

				xhr.send(fd);
			},

			applyEvent(evt) {
				this.progress.stage = evt.stage;
				this.progress.done = evt.done || 0;
				this.progress.total = evt.total || 0;
				this.progress.pct = evt.total > 0 ? Math.round((evt.done / evt.total) * 100) : 0;
			},

			finishUpload(data) {
				this.uploading = false;
				this.progress.stage = '';
				if (!data) {
					this.uploadMsg = 'Upload failed.';
					return;
				}
				this.uploadMsg = data.message;
				this.lastAddedOk = !!data.ok;
				this.flash(data.message);
				if (data.ok) {
					this.file = null;
					this.$refs.fileInput.value = '';
				}
			},

			// Once the last file is parsed, the DB write + full-text rebuild run
			// with no further events. Show motion rather than a frozen 100% bar.
			finalizing() {
				return this.progress.stage === 'indexing' &&
					this.progress.total > 0 &&
					this.progress.done >= this.progress.total;
			},

			barIndeterminate() {
				return this.progress.stage === 'processing' || this.finalizing();
			},

			stageLabel() {
				if (this.finalizing()) return 'Finalizing…';
				return {
					uploading: 'Uploading…',
					processing: 'Processing…',
					extracting: 'Unzipping…',
					indexing: 'Recasting…',
				} [this.progress.stage] || '';
			},

			flash(msg) {
				this.toast = msg;
				clearTimeout(this._t);
				this._t = setTimeout(() => this.toast = '', 4000);
			},
		}
	}
</script>
