<?php

declare(strict_types=1);

/**
 * Tackle Setup Script
 *
 * Downloads and extracts WordPress core to the local wordpress/ directory.
 * Run this once: php setup.php
 */

$wpDir = __DIR__ . '/wordpress';
$version = '6.5';

echo "Tackle Setup\n";
echo "============\n\n";

// Check if WordPress already exists
if (is_dir($wpDir) && file_exists($wpDir . '/wp-load.php')) {
	echo "✓ WordPress $version is already installed.\n";
	echo "  Location: $wpDir\n";
	exit(0);
}

// Check for curl extension
if (!extension_loaded('curl')) {
	echo "✗ The curl PHP extension is required for automatic setup.\n";
	echo "  Please install it or download WordPress manually to wordpress/\n";
	exit(1);
}

// Download WordPress
echo "⏳ Downloading WordPress $version...\n";
$downloadUrl = "https://wordpress.org/wordpress-$version.tar.gz";
$tarFile = __DIR__ . '/wordpress.tar.gz';

require __DIR__ . '/src/Http.php';

$ch = curl_init($downloadUrl);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 600);
Http::secure($ch);

$data = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || !$data) {
	echo "✗ Failed to download WordPress (HTTP $httpCode)\n";
	if ($error) {
		echo "  Error: $error\n";
	}
	exit(1);
}

file_put_contents($tarFile, $data);
echo "✓ Downloaded WordPress (" . round(filesize($tarFile) / 1024 / 1024, 1) . " MB)\n";

// Extract
echo "⏳ Extracting...\n";
try {
	// Extract WordPress core, merging it over whatever is already in the
	// directory. We deliberately do NOT wipe wordpress/ first: plugins and
	// themes added via the Browse tab (or uploaded) live under wp-content, and
	// wiping would destroy them. With overwrite enabled, core files are written
	// in place while user-added content under wp-content is left untouched.
	// (This also handles wordpress/ being a Docker bind-mount that always
	// exists and can't be removed.)
	$phar = new PharData($tarFile);
	$phar->extractTo(__DIR__, null, true); // overwrite = true (merge, never wipe)

	echo "✓ Extracted\n";
} catch (Exception $e) {
	echo "✗ Extraction failed: " . $e->getMessage() . "\n";
	@unlink($tarFile);
	exit(1);
}

// Cleanup tar file
@unlink($tarFile);

// Verify
if (file_exists($wpDir . '/wp-load.php')) {
	echo "\n✓ WordPress $version installed successfully!\n";
	echo "  Location: $wpDir\n";
	echo "\nNext steps:\n";
	echo "  1. php bin/index.php      # Cast WordPress hooks\n";
	echo "  2. php -S localhost:8000  # Start the web server\n";
	echo "  3. Open http://localhost:8000 in your browser\n";
	exit(0);
} else {
	echo "✗ Installation failed - wp-load.php not found\n";
	exit(1);
}
