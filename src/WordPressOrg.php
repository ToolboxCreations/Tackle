<?php

declare(strict_types=1);

/**
 * Thin client for the public WordPress.org Plugins/Themes API.
 *
 * No authentication, no WordPress install required - this is the same data
 * that powers the "Add Plugins" screen in wp-admin. We use it to browse the
 * repository and to resolve download links and latest versions.
 *
 * @see https://codex.wordpress.org/WordPress.org_API
 */
class WordPressOrg
{
	private const PLUGINS_API = 'https://api.wordpress.org/plugins/info/1.2/';
	private const THEMES_API  = 'https://api.wordpress.org/themes/info/1.2/';

	/**
	 * Search the plugin or theme directory.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int}
	 */
	public function search(string $type, string $query, int $page = 1, int $perPage = 24): array
	{
		$query = trim($query);

		if ($type === 'theme') {
			$params = [
				'action'              => 'query_themes',
				'request[search]'     => $query,
				'request[page]'       => $page,
				'request[per_page]'   => $perPage,
			];
			if ($query === '') {
				unset($params['request[search]']);
				$params['request[browse]'] = 'popular';
			}
			$data = $this->get(self::THEMES_API, $params);
			$items = $data['themes'] ?? [];
			$total = (int)($data['info']['results'] ?? count($items));
			return [
				'results' => array_map([$this, 'normalizeTheme'], $items),
				'total'   => $total,
			];
		}

		// Default: plugins
		$params = [
			'action'                       => 'query_plugins',
			'request[search]'              => $query,
			'request[page]'                => $page,
			'request[per_page]'            => $perPage,
			'request[fields][short_description]' => 1,
			'request[fields][icons]'       => 1,
			'request[fields][active_installs]' => 1,
		];
		if ($query === '') {
			unset($params['request[search]']);
			$params['request[browse]'] = 'popular';
		}
		$data = $this->get(self::PLUGINS_API, $params);
		$items = $data['plugins'] ?? [];
		$total = (int)($data['info']['results'] ?? count($items));
		return [
			'results' => array_map([$this, 'normalizePlugin'], $items),
			'total'   => $total,
		];
	}

	/**
	 * Fetch full information for a single plugin/theme slug, including the
	 * download link and latest version.
	 *
	 * @return array<string, mixed>|null
	 */
	public function info(string $type, string $slug): ?array
	{
		if ($type === 'theme') {
			$data = $this->get(self::THEMES_API, [
				'action'           => 'theme_information',
				'request[slug]'    => $slug,
			]);
			if (empty($data) || isset($data['error'])) {
				return null;
			}
			return $this->normalizeTheme($data);
		}

		$data = $this->get(self::PLUGINS_API, [
			'action'           => 'plugin_information',
			'request[slug]'    => $slug,
		]);
		if (empty($data) || isset($data['error'])) {
			return null;
		}
		return $this->normalizePlugin($data);
	}

	private function normalizePlugin(array $p): array
	{
		$icon = '';
		if (!empty($p['icons']) && is_array($p['icons'])) {
			$icon = $p['icons']['1x'] ?? $p['icons']['2x'] ?? $p['icons']['default'] ?? '';
		}
		return [
			'slug'             => $p['slug'] ?? '',
			'name'             => $this->clean($p['name'] ?? ''),
			'type'             => 'plugin',
			'version'          => $p['version'] ?? '',
			'author'           => $this->clean($p['author'] ?? ''),
			'short_description' => $this->clean($p['short_description'] ?? ''),
			'download_link'    => $p['download_link'] ?? '',
			'icon'             => $icon,
			'active_installs'  => (int)($p['active_installs'] ?? 0),
			'rating'           => (float)($p['rating'] ?? 0),
		];
	}

	private function normalizeTheme(array $t): array
	{
		return [
			'slug'             => $t['slug'] ?? '',
			'name'             => $this->clean($t['name'] ?? ''),
			'type'             => 'theme',
			'version'          => $t['version'] ?? '',
			'author'           => $this->clean(is_array($t['author'] ?? null) ? ($t['author']['display_name'] ?? '') : ($t['author'] ?? '')),
			'short_description' => $this->clean($t['description'] ?? ''),
			'download_link'    => $t['download_link'] ?? '',
			'icon'             => $t['screenshot_url'] ?? '',
			'active_installs'  => (int)($t['active_installs'] ?? 0),
			'rating'           => (float)($t['rating'] ?? 0),
		];
	}

	private function clean(string $value): string
	{
		return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	/**
	 * Perform a GET request and decode JSON. Returns [] on failure.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	private function get(string $url, array $params): array
	{
		$full = $url . '?' . http_build_query($params);

		$ch = curl_init($full);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Tackle/1.0 (+https://github.com/)');
		Http::secure($ch);

		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($code !== 200 || !is_string($body)) {
			return [];
		}

		$decoded = json_decode($body, true);
		return is_array($decoded) ? $decoded : [];
	}
}
