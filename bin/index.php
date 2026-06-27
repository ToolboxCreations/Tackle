#!/usr/bin/env php
<?php

declare(strict_types=1);

// Increase memory limit for large indexing tasks
ini_set('memory_limit', '512M');

// Set up autoloading
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Indexer.php';
require __DIR__ . '/../src/RecastStatus.php';

// Load config
$config = require __DIR__ . '/../config.php';

$dbPath = __DIR__ . '/../data/hooks.db';
$db = new Database($dbPath);
// Command-line casts are tagged 'cli' on the shared status.
$indexer = new Indexer($db, new RecastStatus(__DIR__ . '/../data'), 'cli');

// Parse CLI arguments
$sourceName = null;
$clear = false;

foreach ($argv as $arg) {
	if (str_starts_with($arg, '--source=')) {
		$sourceName = substr($arg, 9);
	} elseif ($arg === '--clear') {
		$clear = true;
	}
}

// Validate that WordPress is installed if doing default indexing
if (!$sourceName && !$clear) {
	$wpPath = $config['sources'][0]['path'] ?? null;
	if ($wpPath && !is_dir($wpPath)) {
		echo "✗ WordPress not found at: $wpPath\n";
		echo "\nRun setup first:\n";
		echo "  php setup.php\n";
		exit(1);
	}
}

// Handle --clear flag
if ($clear) {
	echo "Clearing the tackle box...\n";

	// Sources added through the web UI (origin wporg/upload) are not listed in
	// config.php. Preserve their rows - so origin/slug/auto-recast survive - and
	// re-cast them, as long as their files are still on disk. Everything else is
	// dropped and rebuilt from config.php.
	$uiSources = [];
	foreach ($db->getAllSources() as $source) {
		$isUiManaged = in_array($source['origin'] ?? 'path', ['wporg', 'upload'], true);

		if ($isUiManaged && is_dir($source['path'])) {
			$uiSources[] = [
				'name'    => $source['name'],
				'type'    => $source['type'],
				'version' => $source['version'] ?? '',
				'path'    => $source['path'],
			];
		} else {
			if ($isUiManaged) {
				echo "Dropping {$source['name']} (files missing at {$source['path']}).\n";
			}
			$db->deleteSource((int)$source['id']);
		}
	}

	echo "Re-casting...\n";
	foreach (array_merge($config['sources'], $uiSources) as $source) {
		if (!is_dir($source['path'])) {
			echo "Skipping {$source['name']} (path not found: {$source['path']}).\n";
			continue;
		}
		$indexer->indexSource($source);
	}

	echo "\nAll sources cast successfully!\n";
	exit(0);
}

// Index specific source or all sources
if ($sourceName) {
	$found = false;
	foreach ($config['sources'] as $source) {
		if ($source['name'] === $sourceName) {
			if (!is_dir($source['path'])) {
				echo "✗ Source path not found: {$source['path']}\n";
				exit(1);
			}
			$indexer->indexSource($source);
			$found = true;
			break;
		}
	}

	if (!$found) {
		echo "Source '$sourceName' not found in config.php\n";
		exit(1);
	}
} else {
	$indexer->indexAllSources($config['sources']);
	echo "\nAll sources cast successfully!\n";
}
