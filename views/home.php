<?php

declare(strict_types=1);

// Passed from the router: $allSources, $totalHooks
$totalHooks = $totalHooks ?? 0;
$sourceCount = count($allSources);
?>

<div class="home" x-data="searchApp()"
	@keydown.window.slash.prevent="focusSearch()"
	@keydown.window.meta-k.prevent="focusSearch()"
	@keydown.window.ctrl-k.prevent="focusSearch()">

	<section class="hero">
		<h1 class="hero-title">Find any WordPress hook.</h1>
		<p class="hero-sub">
			Search every <code>do_action</code> and <code>apply_filters</code> across
			<strong><?= number_format($totalHooks) ?></strong> indexed hooks
			in <strong><?= $sourceCount ?></strong> <?= $sourceCount === 1 ? 'source' : 'sources' ?>.
		</p>

		<div class="search-box">
			<svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
				stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<circle cx="11" cy="11" r="7" />
				<path d="m21 21-4.3-4.3" />
			</svg>
			<input
				type="search"
				x-ref="search"
				x-model="query"
				@input="performSearch()"
				placeholder="Search by hook name or keyword…"
				autofocus
				class="search-input"
				aria-label="Search hooks">
			<kbd class="search-hint">/</kbd>
		</div>

		<div class="filters">
			<div class="segmented" role="group" aria-label="Hook type">
				<button type="button" :class="{ 'is-active': filterType === '' }" @click="setType('')">All</button>
				<button type="button" :class="{ 'is-active': filterType === 'action' }" @click="setType('action')">Actions</button>
				<button type="button" :class="{ 'is-active': filterType === 'filter' }" @click="setType('filter')">Filters</button>
			</div>

			<?php if ($sourceCount > 0): ?>
				<label class="select-field">
					<span class="visually-hidden">Source</span>
					<select x-model.number="filterSource" @change="performSearch()">
						<option value="">All sources</option>
						<?php foreach ($allSources as $source): ?>
							<option value="<?= (int)$source['id'] ?>"><?= htmlspecialchars($source['name']) ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>

			<label class="toggle">
				<input type="checkbox" x-model="hideDynamic" @change="performSearch()">
				<span>Hide dynamic hooks</span>
			</label>
		</div>
	</section>

	<section class="results-section">
		<template x-if="results.length > 0">
			<div>
				<p class="results-count">
					Showing <span x-text="results.length"></span> of <span x-text="total.toLocaleString()"></span>
				</p>
				<div class="hooks-list">
					<template x-for="hook in results" :key="hook.id">
						<a :href="'/hook?id=' + hook.id" class="hook-card">
							<div class="hook-card-top">
								<code class="hook-name" x-text="hook.name"></code>
								<span class="hook-type" :class="hook.type" x-text="hook.type"></span>
								<template x-if="hook.is_dynamic == 1">
									<span class="hook-dynamic" title="This hook's name is built at runtime and may vary">dynamic</span>
								</template>
							</div>
							<template x-if="hook.docblock">
								<p class="hook-docblock" x-text="hook.docblock"></p>
							</template>
							<div class="hook-meta">
								<span class="source-name" x-text="hook.source_name"></span>
								<span class="file-path" x-text="hook.file_path + ':' + hook.line_number"></span>
							</div>
						</a>
					</template>
				</div>

				<div class="pagination" x-show="results.length < total">
					<button @click="loadMore()" class="btn" :disabled="loading">
						<span x-show="!loading">Load more</span>
						<span x-show="loading">Loading…</span>
					</button>
				</div>
			</div>
		</template>

		<template x-if="results.length === 0 && searched && !loading">
			<div class="empty-state">
				<p class="empty-title">Nothing in the water.</p>
				<p>No hooks match that search. Try a different term, or clear the filters.</p>
			</div>
		</template>

		<template x-if="results.length === 0 && !searched">
			<div class="empty-state">
				<p class="empty-title">Cast a line.</p>
				<p>Start typing to search the tackle box - or press <kbd>/</kbd> to focus.</p>
			</div>
		</template>
	</section>
</div>

<script>
	function searchApp() {
		return {
			query: '',
			filterType: '',
			filterSource: '',
			hideDynamic: false,
			results: [],
			total: 0,
			page: 1,
			searched: false,
			loading: false,
			searchTimeout: null,

			focusSearch() {
				this.$refs.search.focus();
				this.$refs.search.select();
			},

			setType(type) {
				this.filterType = type;
				this.performSearch();
			},

			performSearch() {
				clearTimeout(this.searchTimeout);
				this.page = 1;
				this.searchTimeout = setTimeout(() => this.fetch(), 180);
			},

			loadMore() {
				this.page++;
				this.fetch();
			},

			fetch() {
				this.loading = true;
				const params = new URLSearchParams({
					q: this.query,
					type: this.filterType,
					source: this.filterSource || '',
					hideDynamic: this.hideDynamic,
					page: this.page,
				});

				fetch('/api/search?' + params)
					.then(r => r.json())
					.then(data => {
						if (this.page === 1) {
							this.results = data.results;
						} else {
							this.results = [...this.results, ...data.results];
						}
						this.total = data.total;
						this.searched = true;
						this.loading = false;
					})
					.catch(() => {
						this.loading = false;
					});
			}
		}
	}
</script>
