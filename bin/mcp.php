#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Tackle MCP server (stdio).
 *
 * Exposes the WordPress hook index to MCP clients (Claude Code, Cursor, …) so
 * an agent can look up real hook names instead of guessing. Reads JSON-RPC 2.0
 * messages on stdin and writes responses on stdout - never write anything else
 * to stdout, or it will corrupt the protocol stream.
 *
 * Run via an MCP client (see .mcp.json), or test directly:
 *   echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | php bin/mcp.php
 */

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Search.php';
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Indexer.php';
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/WordPressOrg.php';
require __DIR__ . '/../src/SourceManager.php';
require __DIR__ . '/../src/RecastStatus.php';
require __DIR__ . '/../src/Mcp.php';

// Diagnostics must go to stderr; stdout is reserved for the protocol.
ini_set('display_errors', 'stderr');
// Re-casting a large source (e.g. WordPress core) can be memory-hungry.
ini_set('memory_limit', '512M');

$dbPath = __DIR__ . '/../data/hooks.db';
$config = require __DIR__ . '/../config.php';
$wpPath = $config['wp_path'] ?? (__DIR__ . '/../wordpress');

try {
	$db = new Database($dbPath);
	$search = new Search($db);
	// Casts driven by an MCP client are tagged 'mcp' on the shared status, so
	// web tabs can see a recast was kicked off by an agent rather than a person.
	$indexer = new Indexer($db, new RecastStatus(__DIR__ . '/../data'), 'mcp');
	$sourceManager = new SourceManager($db, $indexer, new WordPressOrg(), $wpPath . '/wp-content');
} catch (\Throwable $e) {
	fwrite(STDERR, "Tackle MCP: failed to open the hook database: {$e->getMessage()}\n");
	fwrite(STDERR, "Ensure the pdo_sqlite extension is enabled and you've run: php bin/index.php\n");
	exit(1);
}

(new Mcp($db, $search, $indexer, $sourceManager, $config))->run();
