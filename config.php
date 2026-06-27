<?php

declare(strict_types=1);

// Base path to WordPress.
// By default this is the bundled installation created by `php setup.php`.
// To index a separate install, change $wpPath or add absolute paths below.
$wpPath = __DIR__ . '/wordpress';

return [
	// Path to the WordPress install Tackle installs plugins/themes into.
	'wp_path' => $wpPath,

	// When true, the web UI may install, update, and remove sources (downloads
	// files and runs the indexer). Set to false for a read-only public deploy,
	// e.g. behind a Cloudflare tunnel, so visitors can only search.
	'allow_management' => true,

	'sources' => [
		[
			'name'    => 'WordPress Core',
			'type'    => 'core',
			'version' => '6.5',
			'path'    => $wpPath,
		],

		// Add plugins and themes here. A plugin or theme can live inside the
		// bundled WordPress install (use the $wpPath helper) or anywhere on disk
		// (use an absolute path). Examples:
		//
		// [
		//     'name'    => 'WooCommerce',
		//     'type'    => 'plugin',
		//     'version' => '',
		//     'path'    => $wpPath . '/wp-content/plugins/woocommerce',
		// ],
		// [
		//     'name'    => 'My Theme',
		//     'type'    => 'theme',
		//     'version' => '1.0.0',
		//     'path'    => '/absolute/path/to/wp-content/themes/my-theme',
		// ],
	],
];
