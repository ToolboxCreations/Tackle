# Tackle

A local-first WordPress hooks and filters explorer. A replacement for the defunct hookr.io.

**Tackle** is a tackle box where a developer keeps all their hooks. Index WordPress core, plugins, and themes, then search and explore all `do_action` and `apply_filters` calls in a fast, modern web UI.

## Features

- **Local WordPress bundled** - one command to set up: `php setup.php`
- Index WordPress core, plugins, and themes from the command line
- Fast full-text search with instant results
- Filter by hook type (actions/filters) and source
- Identifies dynamic hooks (names built at runtime)
- View hook documentation, parameters, and usage snippets
- SQLite database - no external dependencies
- Zero build toolchain - just PHP and a browser
- Parses complex PHP - handles nested calls, class constants, string interpolation

## Prerequisites

- **PHP 8.1+**
- PHP extensions: **pdo_sqlite** / **sqlite3** (storage), **curl** (downloads),
  **zip** (installing plugins/themes from the directory)
- Internet connection (for `php setup.php` and for the in-app directory browser)

That's it - WordPress is bundled automatically, and Tackle ships its own CA
bundle (`certs/cacert.pem`) so HTTPS verification works even on PHP installs
that lack a configured certificate store.

To check your extensions:

```bash
php -m | grep -iE 'pdo_sqlite|sqlite3|curl|zip'
```

If any are missing, enable them in `php.ini` (e.g. uncomment `extension=zip`) -
or skip the hassle entirely and run with **Docker** (see *Self-Hosting with
Docker* below), where every extension is pre-installed.

## Setup

1. **Clone or download Tackle** to your machine:
   ```bash
   cd ~/projects
   git clone <repo-url> tackle
   cd tackle
   ```

2. **Download and install WordPress** (bundled locally):
   ```bash
   php setup.php
   ```

   This downloads WordPress 6.5 to the `wordpress/` directory. The setup is automatic - just wait for it to complete.

   **Alternative:** If you want to use an existing WordPress installation, edit `config.php` and change the `'path'` to point to it.

3. **Cast the index** (extract all hooks from WordPress):
   ```bash
   php bin/index.php
   ```

   You should see output like:
   ```
   Casting into WordPress Core...
   12345 files found.
   54321 hooks found.
   ```

4. **Start the web server**:
   ```bash
   php -S localhost:8000
   ```

5. **Open your browser** to `http://localhost:8000` and search the tackle box!

## Self-Hosting with Docker

The Docker image bakes in every extension Tackle needs (`pdo_sqlite`, `zip`,
`curl`, `openssl`) - no php.ini setup, nothing to install but Docker itself.

```bash
docker compose up -d --build         # start Tackle on http://localhost:8000
```

The `data/` (hook database) and `wordpress/` (downloaded plugins/themes)
directories are mounted as volumes, so your index survives rebuilds.

**Seed WordPress core** (optional - you can also add plugins/themes from the
Browse tab without it):

```bash
docker compose exec tackle php setup.php       # download WordPress core
docker compose exec tackle php bin/index.php   # cast it
```

### Keeping sources fresh (the "Auto" toggle)

Flag any managed source as **Auto** in the Tackle Box, then run the scheduler
profile to refresh those sources on an interval (default: daily):

```bash
docker compose --profile scheduler up -d
# Override the cadence (seconds):
TACKLE_RECAST_INTERVAL=3600 docker compose --profile scheduler up -d
```

You can also run a single pass by hand, or wire it into host cron:

```bash
docker compose exec tackle php bin/auto-recast.php
```

### Exposing it with a Cloudflare Tunnel

A tunnel gives you a public HTTPS URL with no open ports or firewall changes.

**Quick, no account** - get a temporary `*.trycloudflare.com` URL:

```bash
cloudflared tunnel --url http://localhost:8000
```

**Named tunnel (persistent)** - create a tunnel in the Cloudflare Zero Trust
dashboard, copy its token, then use the bundled compose profile:

```bash
TUNNEL_TOKEN=eyJ... TACKLE_ALLOW_MANAGEMENT=false \
  docker compose --profile cloudflare up -d
```

> **Make public instances read-only.** Set `TACKLE_ALLOW_MANAGEMENT=false` (env)
> or `'allow_management' => false` (config.php) so visitors can search and browse
> but cannot install, update, or remove sources. The management endpoints return
> `403` when disabled.

## Adding Plugins & Themes from the Browser

The **Browse** tab has two ways to add sources, neither of which needs the
WordPress admin, a database, or WP-CLI:

- **From the directory** — search the WordPress.org plugin/theme repository and
  hit **Add to Tackle**. Tackle downloads the files into `wp-content`, indexes
  the hooks, and adds it to your tackle box.
- **Upload a zip** — for anything not on WordPress.org (premium plugins, custom
  themes), switch to the **Upload a zip** tab, pick Plugin or Theme, and drop in
  a `.zip`. Tackle extracts it, reads the package's WordPress header for its name
  and version, and indexes it. (The Docker image is preconfigured for large
  uploads; for a manual install, raise `upload_max_filesize` / `post_max_size` in
  `php.ini` if your packages are big.)

- **Scan disk** — already have plugins or themes sitting in `wp-content` (copied
  in by hand, installed by WordPress, or left behind after you removed a source
  but kept its files)? Hit **Scan disk** in The Tackle Box. Tackle lists every
  package on disk that isn't in the box yet — read straight from each package's
  WordPress header — and **Add** casts it in place, no re-upload needed.

From **The Tackle Box** you can then:

- **Re-cast** - re-read the files on disk and refresh the index
- **Check** / **Update** - compare against WordPress.org and pull the latest release
- **Auto** - flag a source to be kept fresh
- **Remove** - drop it from the index (and optionally delete the downloaded files)

> **Public / read-only deploys:** the management actions download files and run
> the indexer. If you expose Tackle publicly (e.g. behind a Cloudflare tunnel),
> set `'allow_management' => false` in `config.php`. Visitors can still browse
> and search; install/update/remove are disabled and return `403`.

Commercial plugins and themes (Divi, LearnDash, etc.) work great via **Upload a
zip** above. You can also point `config.php` at a path on disk — see below.

## How to Add Sources Manually (config.php)

By default, Tackle indexes WordPress Core from the bundled installation. You can add plugins and themes to the index:

**Option 1: Plugins/themes in the bundled WordPress**

Edit `config.php`:
```php
[
    'name'    => 'WooCommerce',
    'type'    => 'plugin',
    'version' => '',
    'path'    => $wpPath . '/wp-content/plugins/woocommerce',
],
```

**Option 2: External WordPress installation or custom source**

Edit `config.php` and set an absolute path:
```php
'path' => 'C:\\Users\\you\\another-wordpress',
// or
'path' => '/home/you/another-wordpress',
```

Then re-cast:
```bash
php bin/index.php
```

## How to Re-cast After Updates

After updating WordPress or plugins, re-cast them:

```bash
# Re-cast just WordPress Core
php bin/index.php --source="WordPress Core"

# Or re-cast a specific plugin
php bin/index.php --source="WooCommerce"

# Or clear and re-index everything
php bin/index.php --clear
```

If WordPress itself has a new version available, download it again:
```bash
rm -rf wordpress/
php setup.php
php bin/index.php --clear
```

## Command Line Usage

```bash
# Index all sources in config.php
php bin/index.php

# Index a specific source by name
php bin/index.php --source="WordPress Core"

# Clear the database and re-index everything
php bin/index.php --clear
```

`--clear` rebuilds the index from scratch: sources defined in `config.php` are
re-cast, and sources you added through the **Browse**/upload UI are preserved
and re-cast too (their origin, slug, and auto-recast flag are kept) as long as
their files still exist on disk. Any source whose files are gone is dropped.

## Use It From an AI Agent (MCP)

Tackle ships a built-in [Model Context Protocol](https://modelcontextprotocol.io/)
server so an AI agent can look up real WordPress hooks instead of guessing their
names. It's pure PHP over stdio - no extra dependencies.

It exposes these tools:

| Tool | What it does |
|------|--------------|
| `search_hooks` | Search hooks by name/keyword, filter by type or source, paginate |
| `get_hook` | Full detail for one hook: docs, parameters, file/line, usage snippet, caller count |
| `get_callers` | Find what's already hooked into a hook - every `add_action`/`add_filter`, with its callback and **priority**, ordered by execution order. The tool for debugging order-of-execution bugs |
| `list_sources` | List indexed sources and their hook counts |
| `cast_source` | Trigger a (re)scan of one source (by name/id) or all sources, from the client - no out-of-band `php bin/index.php` step |
| `get_index_status` | Index health: total sources/hooks/callers, when each source was last cast, and whether its files still exist |

`search_hooks`, `get_hook`, `get_callers`, `list_sources`, and `get_index_status`
are read-only. `cast_source` writes to the index (it runs the parser), so it's the
one tool that changes state.

**Claude Code** picks up the bundled [`.mcp.json`](.mcp.json) automatically when
you run it from the project directory. For other clients, point them at the
command `php bin/mcp.php` (working directory = the Tackle folder), e.g.:

```jsonc
{
  "mcpServers": {
    "tackle": { "command": "php", "args": ["bin/mcp.php"] }
  }
}
```

The server reads the same `data/hooks.db`, so make sure you've cast at least one
source (`php bin/index.php`) and that `pdo_sqlite` is enabled. Quick smoke test:

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | php bin/mcp.php
```

### When Tackle runs in Docker

If you run Tackle via Docker, the MCP server should run *inside* the container
(that's where the database and PHP extensions live). Point your client at the
running container with `docker exec` instead of a local `php`:

```jsonc
{
  "mcpServers": {
    "tackle": {
      "command": "docker",
      "args": ["exec", "-i", "tackle", "php", "/app/bin/mcp.php"]
    }
  }
}
```

Or, with the Claude Code CLI (registers it without editing the committed
`.mcp.json`):

```bash
claude mcp add tackle -- docker exec -i tackle php /app/bin/mcp.php
```

Notes:

- The compose service is named `tackle` (via `container_name`), so
  `docker exec -i tackle …` is stable. Use `-i` (not `-t`) — the JSON-RPC stream
  is piped, and a TTY would corrupt it.
- The container must be **running** before the client launches the server
  (`docker compose up -d`), and the index must be seeded
  (`docker compose exec tackle php bin/index.php`, or add sources via Browse).
- It reads the same volume-mounted database as the web app, so anything you add
  in the UI is immediately queryable by the agent.

## Project Structure

```
tackle/
├── setup.php                  # One-command WordPress installer (run once: php setup.php)
├── index.php                  # Web router (serves all routes)
├── config.php                 # Source configuration
├── Dockerfile                 # Image with all PHP extensions baked in
├── docker-compose.yml         # Web app + optional scheduler/cloudflare profiles
├── .mcp.json                  # MCP server registration (auto-detected by Claude Code)
├── bin/
│   ├── index.php              # CLI indexer
│   ├── auto-recast.php        # Refresh "Auto"-flagged sources (scheduler)
│   └── mcp.php                # MCP server (stdio) for AI agents
├── src/
│   ├── Database.php           # SQLite wrapper + migrations
│   ├── Parser.php             # PHP file parser (extracts hooks)
│   ├── Indexer.php            # Orchestrates parsing + DB writes
│   ├── Search.php             # Search query logic
│   ├── WordPressOrg.php       # WordPress.org plugin/theme API client
│   ├── SourceManager.php      # Install / update / remove / re-cast sources
│   ├── Http.php               # Verified-TLS curl helper (uses bundled CA)
│   └── Mcp.php                # MCP server implementation (JSON-RPC over stdio)
├── views/
│   ├── layout.php             # HTML shell, nav, scripts
│   ├── home.php               # Search UI
│   ├── hook.php               # Hook detail page
│   ├── browse.php             # WordPress.org directory browser
│   └── sources.php            # Tackle box (manage sources)
├── public/
│   └── style.css              # Styles
├── certs/
│   └── cacert.pem             # Bundled Mozilla CA list (for HTTPS)
├── wordpress/                 # WordPress core (downloaded by setup.php)
│   ├── wp-content/
│   ├── wp-includes/
│   ├── wp-load.php
│   └── ...
├── data/
│   └── hooks.db               # SQLite database (auto-created)
└── README.md                  # This file
```

## How It Works

1. **Parser** (`src/Parser.php`) uses regex + tokenizer logic to find all `do_action` and `apply_filters` calls in PHP files
2. **Indexer** (`src/Indexer.php`) walks the filesystem, parses each file, and bulk-inserts hooks into SQLite
3. **Database** (`src/Database.php`) manages the SQLite schema and queries
4. **Search** (`src/Search.php`) uses FTS5 for fast full-text search
5. **Web UI** uses Alpine.js for instant, debounced search on the frontend

## UI Terminology

Tackle leans lightly on the fishing metaphor - only where it reads as intentional.

| Generic | Tackle |
|---------|--------|
| Index / Re-index | Cast / Re-cast |
| Sources list page | The Tackle Box |
| Last indexed | Last cast |

## Colors & Style

The UI uses a restrained dark theme. All values live as CSS custom properties at the
top of `public/style.css`, so the palette can be retuned in one place.

- **Background**: `#0b0d12`
- **Surface**: `#13161d`
- **Brand / interaction (teal)**: `#5ed3c4`
- **Action hooks (amber)**: `#f6a13c`
- **Filter hooks (blue)**: `#6aa9ff`
- **Dynamic hooks (violet)**: `#c08cf0`

Typography: Inter for UI text, JetBrains Mono for code (both loaded via Google Fonts CDN).

## Database Schema

Hooks are stored in SQLite with full-text search (FTS5) for rapid lookups:

```sql
CREATE TABLE sources (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT CHECK(type IN ('core', 'plugin', 'theme')),
    version TEXT,
    path TEXT NOT NULL,
    last_indexed_at DATETIME,
    hook_count INTEGER DEFAULT 0
);

CREATE TABLE hooks (
    id INTEGER PRIMARY KEY,
    source_id INTEGER NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    name_normalized TEXT NOT NULL,
    type TEXT CHECK(type IN ('action', 'filter')),
    is_dynamic INTEGER DEFAULT 0,
    raw_expression TEXT,
    file_path TEXT NOT NULL,
    line_number INTEGER NOT NULL,
    docblock TEXT,
    parameters TEXT,  -- JSON
    since_version TEXT,
    deprecated INTEGER DEFAULT 0,
    UNIQUE(source_id, file_path, line_number)
);

CREATE VIRTUAL TABLE hooks_fts USING fts5(
    name, docblock, content='hooks', content_rowid='id'
);
```

## Search Weighting and Hooks

Search results are now scored with a lightweight weighting system inside `src/Search.php`.

- **Built-in weights**: `filter` hooks receive a priority boost, exact name matches receive a very strong boost (so a hook literally named `shortcode` will always appear before anything that merely mentions the term in its name or documentation), docblock presence and non-dynamic hooks get smaller boosts.
- **Substring boost**: if the hook name contains the query string (case‑insensitive) it receives a base bonus - larger for multiple occurrences - ensuring any hook with the term in the title sorts above hooks that only match via documentation or other metadata.
- **Docblock snippets**: the UI shows the first meaningful line of the hook’s PHPDoc comment. Since the opening line of a docblock is always `/**`, the search API skips it and displays the second line when available, ensuring a description is actually shown.
- **Shortened file paths**: file locations are returned relative to the source base path (e.g. `/wp-admin/includes/media.php` on Unix or `\wp-admin\includes\media.php` on Windows, instead of the full `C:\node\Tackle\wordpress\wp-admin\includes\media.php`). The path is normalized to use the current OS’s directory separator, keeping results compact now that the source name already identifies the project.
- **Custom weight callback**: Callers can pass an optional `callable $weightCallback` to `Search::search()` with the signature `function(array $result, int $weight): int`. The callback receives the computed base weight and may return a new integer weight to adjust ranking.

Example usage:

```php
$search = new Search($db);
$res = $search->search('the_hook_name', '', null, false, 1, 20, function($row, $weight) {
    // Boost hooks from a specific source
    if ($row['source_name'] === 'WooCommerce') {
        return $weight + 30;
    }
    return $weight;
});
```

The PHP-side sorting uses the `_weight` key and then falls back to alphabetical ordering.


## Known Limitations

- The parser is regex-based and not 100% accurate on edge cases (e.g., hooks in string interpolation within conditionals). It catches the vast majority of real-world hooks.
- Dynamic hook names are normalized to `prefix_*` for searchability; the raw expression is stored for context.
- The web UI does not support editing or creating hooks; it is read-only.

## Troubleshooting

### Setup issues

**"The curl PHP extension is required"**

Ensure PHP has curl enabled. Check:
```bash
php -m | grep -i curl
```

If missing, install it via your package manager:
- **Ubuntu/Debian**: `sudo apt-get install php-curl`
- **macOS**: `brew install php` (includes curl)
- **Windows**: Enable curl in `php.ini` or reinstall PHP with curl support

**"Failed to download WordPress"**

Check your internet connection. If the download keeps failing, you can manually download WordPress:
1. Visit https://wordpress.org/download/
2. Extract to a `wordpress/` folder in the Tackle directory
3. Run `php bin/index.php`

### Indexing issues

**"SQLite extension is not loaded"**

Ensure PHP is compiled with the SQLite3 extension. Check:
```bash
php -m | grep -i sqlite
```

If missing, install the extension via your package manager or PHP installer.

### Running issues

The `data/` directory must be writable by the PHP process. On Linux/Mac:
```bash
chmod 755 data/
```

### Hooks not appearing in the UI after running the indexer

1. Verify the path in `config.php` is correct
2. Check the indexer output for errors
3. Ensure the path contains `.php` files
4. The database file is at `data/hooks.db` - you can inspect it with any SQLite viewer

### "404 Not Found" or blank page when visiting the UI

Ensure you're running:
```bash
php -S localhost:8000
```

And visiting `http://localhost:8000` (not `127.0.0.1:8000`).

## License

GPL-3.0. See [LICENSE](LICENSE) for the full text.

## Credits

Built as a replacement for the excellent but defunct [hookr.io](https://hookr.io/). Thanks to all the WordPress developers who've shared their knowledge of the hook system.
