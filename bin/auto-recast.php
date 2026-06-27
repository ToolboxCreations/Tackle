#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Refresh every source flagged "Auto" in the Tackle Box.
 *
 * For WordPress.org-managed sources this checks for a newer release and, when
 * one exists, downloads it and re-indexes. For path-based sources it re-casts
 * from the files on disk (which may have changed). Safe to run on a schedule
 * (cron, the docker-compose "scheduler" profile, or a Cloudflare cron trigger).
 *
 *   php bin/auto-recast.php
 */

ini_set('memory_limit', '512M');

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Indexer.php';
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/WordPressOrg.php';
require __DIR__ . '/../src/SourceManager.php';
require __DIR__ . '/../src/RecastStatus.php';

$config = require __DIR__ . '/../config.php';
$wpPath = $config['wp_path'] ?? (__DIR__ . '/../wordpress');

$db = new Database(__DIR__ . '/../data/hooks.db');
// Scheduled refreshes are tagged 'scheduler' on the shared status.
$indexer = new Indexer($db, new RecastStatus(__DIR__ . '/../data'), 'scheduler');
$manager = new SourceManager($db, $indexer, new WordPressOrg(), $wpPath . '/wp-content');

$stamp = date('Y-m-d H:i:s');
$flagged = array_filter($db->getAllSources(), static fn($s) => (int)($s['auto_recast'] ?? 0) === 1);

if (empty($flagged)) {
	echo "[{$stamp}] Auto re-cast: no sources flagged. Nothing to do.\n";
	exit(0);
}

echo "[{$stamp}] Auto re-cast: " . count($flagged) . " source(s) flagged.\n";

foreach ($flagged as $source) {
	$id = (int)$source['id'];
	$name = $source['name'];
	$managed = ($source['origin'] ?? 'path') === 'wporg';

	try {
		if ($managed) {
			$check = $manager->checkUpdate($id);
			if (!empty($check['has_update'])) {
				$res = $manager->update($id);
				echo "  - {$name}: " . ($res['message'] ?? 'updated') . "\n";
			} else {
				echo "  - {$name}: up to date ({$source['version']}).\n";
			}
		} else {
			$res = $manager->recast($id);
			echo "  - {$name}: " . ($res['message'] ?? 're-cast') . "\n";
		}
	} catch (\Throwable $e) {
		echo "  - {$name}: ERROR - {$e->getMessage()}\n";
	}
}

echo "[" . date('Y-m-d H:i:s') . "] Auto re-cast complete.\n";
