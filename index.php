<?php

declare(strict_types=1);

// Set up autoloading
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/DocBlock.php';
require __DIR__ . '/src/Parser.php';
require __DIR__ . '/src/Indexer.php';
require __DIR__ . '/src/Search.php';
require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/WordPressOrg.php';
require __DIR__ . '/src/SourceManager.php';
require __DIR__ . '/src/SourceViewer.php';
require __DIR__ . '/src/RecastStatus.php';

$config = require __DIR__ . '/config.php';
$allowManagement = (bool)($config['allow_management'] ?? false);
$wpPath = $config['wp_path'] ?? (__DIR__ . '/wordpress');

// Environment override (e.g. for a read-only public Docker deploy). Takes
// precedence over config.php when set.
$envManage = getenv('TACKLE_ALLOW_MANAGEMENT');
if ($envManage !== false && $envManage !== '') {
    $allowManagement = !in_array(strtolower($envManage), ['0', 'false', 'no', 'off'], true);
}

// Initialize database
$dbPath = __DIR__ . '/data/hooks.db';
$db = new Database($dbPath);
$search = new Search($db);
$org = new WordPressOrg();
$recastStatus = new RecastStatus(__DIR__ . '/data');
// Casts triggered from the web UI are tagged 'user' on the shared status.
$sourceManager = new SourceManager($db, new Indexer($db, $recastStatus, 'user'), $org, $wpPath . '/wp-content');

// Simple routing
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Send a JSON response and exit. */
function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Begin a streamed newline-delimited JSON (NDJSON) response. */
function stream_start(): void
{
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    header('Content-Type: application/x-ndjson');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // discourage proxy buffering, if any
}

/** Emit one NDJSON event on a streamed response. */
function stream_event($data): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
    flush();
}

/** Decode a JSON request body into an array. */
function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// Simple render helper that captures view output and injects into layout
function render(string $viewPath, array $data = []): void
{
    // make data available to view
    extract($data);
    ob_start();
    require $viewPath;
    $content = ob_get_clean();

    // layout expects $content variable
    require __DIR__ . '/views/layout.php';
}

// Route: GET /
if ($path === '/' || $path === '') {
    // prepare any data
    $allSources = $db->getAllSources();
    $totalHooks = array_sum(array_map(static fn($s) => (int)$s['hook_count'], $allSources));
    render(__DIR__ . '/views/home.php', ['allSources' => $allSources, 'totalHooks' => $totalHooks]);
    exit;
}

// Route: GET /hook
if ($path === '/hook') {
    parse_str($query, $params);
    $hookId = (int)($params['id'] ?? 0);

    if (!$hookId) {
        http_response_code(404);
        echo 'Hook not found';
        exit;
    }

    $hook = $db->getHookById($hookId);
    if (!$hook) {
        http_response_code(404);
        echo 'Hook not found';
        exit;
    }

    render(__DIR__ . '/views/hook.php', ['hook' => $hook]);
    exit;
}

// Route: GET /source - view the source file a hook is defined in, scrolled to
// and highlighting the defining line.
if ($path === '/source') {
    parse_str($query, $params);
    $hookId = (int)($params['id'] ?? 0);

    $hook = $hookId ? $db->getHookById($hookId) : null;
    if (!$hook) {
        http_response_code(404);
        echo 'Hook not found';
        exit;
    }

    $file = (new SourceViewer($wpPath))->read($hook);
    if ($file === null) {
        http_response_code(404);
    }
    render(__DIR__ . '/views/source.php', ['hook' => $hook, 'file' => $file]);
    exit;
}

// Route: GET /sources
if ($path === '/sources') {
    render(__DIR__ . '/views/sources.php', ['allowManagement' => $allowManagement]);
    exit;
}

// Route: GET /api/search
if ($path === '/api/search') {
    header('Content-Type: application/json');

    parse_str($query, $params);
    $q = (string)($params['q'] ?? '');
    $type = (string)($params['type'] ?? '');
    $source = (int)($params['source'] ?? 0);
    $hideDynamic = isset($params['hideDynamic']) && $params['hideDynamic'] !== 'false';
    $page = max(1, (int)($params['page'] ?? 1));

    $results = $search->search($q, $type, $source ?: null, $hideDynamic, $page);

    echo json_encode($results, JSON_UNESCAPED_SLASHES);
    exit;
}

// Route: GET /browse - the WordPress.org repository browser. Browsing exists
// only to add sources, so when management is disabled the page is not served.
if ($path === '/browse') {
    if (!$allowManagement) {
        http_response_code(404);
        echo '404 Not Found';
        exit;
    }
    render(__DIR__ . '/views/browse.php');
    exit;
}

// Route: GET /api/sources - current tackle box as JSON (for the interactive UI)
if ($path === '/api/sources' && $method === 'GET') {
    json_out(['sources' => $sourceManager->listSources(), 'allowManagement' => $allowManagement]);
}

// Route: GET /api/recast/status - the recast currently in flight, shared across
// every tab/session so the global progress toast can poll it. Read-only, so it
// stays available even when management is disabled.
if ($path === '/api/recast/status' && $method === 'GET') {
    json_out($recastStatus->current());
}

// Route: GET /api/discover - plugins/themes on disk that aren't in the box yet
if ($path === '/api/discover' && $method === 'GET') {
    if (!$allowManagement) {
        json_out(['ok' => false, 'message' => 'Management is disabled on this instance.'], 403);
    }
    json_out(['items' => $sourceManager->discoverOnDisk()]);
}

// Route: GET /api/repo/search - search the WordPress.org directory
if ($path === '/api/repo/search' && $method === 'GET') {
    parse_str((string)$query, $params);
    $type = ($params['type'] ?? 'plugin') === 'theme' ? 'theme' : 'plugin';
    $q = (string)($params['q'] ?? '');
    $page = max(1, (int)($params['page'] ?? 1));
    json_out($org->search($type, $q, $page));
}

// --- Management endpoints (POST, JSON). Gated by allow_management. ---
if (str_starts_with($path, '/api/sources/') && $method === 'POST') {
    if (!$allowManagement) {
        json_out(['ok' => false, 'message' => 'Management is disabled on this instance.'], 403);
    }

    $action = substr($path, strlen('/api/sources/'));
    $body = json_body();

    switch ($action) {
        case 'install':
            json_out($sourceManager->installFromRepo(
                (string)($body['type'] ?? 'plugin'),
                (string)($body['slug'] ?? '')
            ));
            // no break - json_out exits
        case 'upload':
            $uploadType = ($_POST['type'] ?? 'plugin') === 'theme' ? 'theme' : 'plugin';
            $file = $_FILES['file'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
                if ($contentLen > 0 && empty($_POST) && empty($_FILES)) {
                    json_out(['ok' => false, 'message' => 'Upload is larger than the server allows (post_max_size). Increase it in php.ini, or use the Docker image which is preconfigured for large packages.']);
                }
                json_out(['ok' => false, 'message' => 'No file received, or the upload failed.']);
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                json_out(['ok' => false, 'message' => 'Invalid upload.'], 400);
            }
            // Stream progress as newline-delimited JSON. By the time PHP runs,
            // the upload is already buffered to disk, so the client tracks the
            // transfer itself; here we report the server-side extract + index.
            stream_start();
            $result = $sourceManager->installFromZip(
                $uploadType,
                $file['tmp_name'],
                (string)($file['name'] ?? ''),
                function (string $stage, ?int $done, ?int $total): void {
                    stream_event(['stage' => $stage, 'done' => $done, 'total' => $total]);
                }
            );
            $result['done'] = true;
            stream_event($result);
            exit;
        case 'cast-disk':
            json_out($sourceManager->castFromDisk(
                (string)($body['type'] ?? 'plugin'),
                (string)($body['folder'] ?? '')
            ));
        case 'delete-disk':
            json_out($sourceManager->deleteFromDisk(
                (string)($body['type'] ?? 'plugin'),
                (string)($body['folder'] ?? '')
            ));
        case 'recast':
            json_out($sourceManager->recast((int)($body['id'] ?? 0)));
        case 'update':
            json_out($sourceManager->update((int)($body['id'] ?? 0)));
        case 'check':
            json_out($sourceManager->checkUpdate((int)($body['id'] ?? 0)));
        case 'remove':
            json_out($sourceManager->remove((int)($body['id'] ?? 0), (bool)($body['deleteFiles'] ?? false)));
        case 'auto':
            json_out($sourceManager->setAutoRecast((int)($body['id'] ?? 0), (bool)($body['enabled'] ?? false)));
        default:
            json_out(['ok' => false, 'message' => 'Unknown action.'], 404);
    }
}

// Serve static files
if (str_starts_with($path, '/public/')) {
    $filePath = __DIR__ . $path;
    if (file_exists($filePath) && is_file($filePath)) {
        if (str_ends_with($filePath, '.css')) {
            header('Content-Type: text/css');
        } elseif (str_ends_with($filePath, '.js')) {
            header('Content-Type: application/javascript');
        }
        readfile($filePath);
        exit;
    }
}

// 404
http_response_code(404);
echo '404 Not Found';
