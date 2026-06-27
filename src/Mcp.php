<?php

declare(strict_types=1);

/**
 * A minimal, dependency-free Model Context Protocol (MCP) server for Tackle.
 *
 * Speaks JSON-RPC 2.0 over stdio (newline-delimited messages), the standard
 * transport used by Claude Code, Cursor, and other MCP clients. It exposes the
 * hook index as read-only tools so an agent can look up real WordPress hooks
 * instead of guessing names.
 *
 * Protocol: https://modelcontextprotocol.io/
 */
class Mcp
{
	private const PROTOCOL_VERSION = '2025-06-18';
	private const SERVER_NAME = 'tackle';
	private const SERVER_VERSION = '1.0.0';

	private Database $db;
	private Search $search;
	private ?Indexer $indexer;
	private ?SourceManager $sources;
	/** @var array<string, mixed> */
	private array $config;
	/** @var resource */
	private $in;
	/** @var resource */
	private $out;

	/**
	 * @param array<string, mixed> $config The loaded config.php (for cast_source).
	 */
	public function __construct(
		Database $db,
		Search $search,
		?Indexer $indexer = null,
		?SourceManager $sources = null,
		array $config = []
	) {
		$this->db = $db;
		$this->search = $search;
		$this->indexer = $indexer;
		$this->sources = $sources;
		$this->config = $config;
		// The indexer echoes progress to stdout; that would corrupt the JSON-RPC
		// stream, so force it quiet for the duration of the MCP session.
		if ($this->indexer !== null) {
			$this->indexer->verbose = false;
		}
		$this->in = fopen('php://stdin', 'r');
		$this->out = fopen('php://stdout', 'w');
	}

	public function run(): void
	{
		while (($line = fgets($this->in)) !== false) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}

			$decoded = json_decode($line, true);
			if (!is_array($decoded)) {
				$this->send($this->error(null, -32700, 'Parse error'));
				continue;
			}

			// Support both single messages and JSON-RPC batches.
			$isBatch = array_is_list($decoded) && isset($decoded[0]);
			$messages = $isBatch ? $decoded : [$decoded];

			foreach ($messages as $message) {
				if (!is_array($message)) {
					continue;
				}
				$response = $this->handle($message);
				if ($response !== null) {
					$this->send($response);
				}
			}
		}
	}

	/**
	 * Route a single JSON-RPC message. Returns a response array, or null for
	 * notifications (messages without an id).
	 *
	 * @param array<string, mixed> $msg
	 * @return array<string, mixed>|null
	 */
	private function handle(array $msg): ?array
	{
		$id = $msg['id'] ?? null;
		$method = (string)($msg['method'] ?? '');
		$params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
		$isNotification = !array_key_exists('id', $msg);

		switch ($method) {
			case 'initialize':
				return $this->result($id, [
					'protocolVersion' => is_string($params['protocolVersion'] ?? null)
						? $params['protocolVersion'] : self::PROTOCOL_VERSION,
					'capabilities'    => ['tools' => (object)[]],
					'serverInfo'      => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
				]);

			case 'ping':
				return $this->result($id, (object)[]);

			case 'tools/list':
				return $this->result($id, ['tools' => $this->toolDefinitions()]);

			case 'tools/call':
				return $this->callTool($id, $params);

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return null; // notifications expect no response

			default:
				if ($isNotification) {
					return null;
				}
				return $this->error($id, -32601, "Method not found: {$method}");
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function toolDefinitions(): array
	{
		return [
			[
				'name' => 'search_hooks',
				'description' => 'Search indexed WordPress hooks (do_action / apply_filters) by name or keyword. '
					. 'Use this to find the exact name of an action or filter before writing add_action()/add_filter(), '
					. 'instead of guessing. Returns matching hooks with their source, file location, and a doc summary.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'query'           => ['type' => 'string', 'description' => 'Hook name or keyword to search for.'],
						'type'            => ['type' => 'string', 'enum' => ['action', 'filter'], 'description' => 'Limit to actions or filters.'],
						'source'          => ['type' => 'string', 'description' => 'Limit to a source by name (e.g. "WordPress Core"). See list_sources.'],
						'include_dynamic' => ['type' => 'boolean', 'description' => 'Include dynamic hooks whose names are built at runtime. Default true.'],
						'limit'           => ['type' => 'integer', 'description' => 'Max results to return (default 20, max 100).'],
						'page'            => ['type' => 'integer', 'description' => 'Page number for pagination (default 1).'],
					],
				],
			],
			[
				'name' => 'get_hook',
				'description' => 'Get full detail for one hook by its id (from search_hooks results): documentation, parameters, '
					. 'exact file path and line number, the raw expression for dynamic hooks, and a ready-to-paste usage snippet.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'id' => ['type' => 'integer', 'description' => 'The hook id returned by search_hooks.'],
					],
					'required' => ['id'],
				],
			],
			[
				'name' => 'get_callers',
				'description' => 'Find what is already hooked into a given hook: every add_action()/add_filter() '
					. 'registration across the indexed sources, with the callback, the priority it runs at, and its '
					. 'accepted-arg count. Results are ordered by priority (execution order), so this is the tool for '
					. 'debugging order-of-execution problems - "what else touches this hook, and does my callback run '
					. 'before or after it?". Complements search_hooks, which finds where a hook fires.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'hook'   => ['type' => 'string', 'description' => 'The exact hook name to find consumers of (e.g. "init", "save_post").'],
						'type'   => ['type' => 'string', 'enum' => ['action', 'filter'], 'description' => 'Limit to add_action or add_filter registrations.'],
						'source' => ['type' => 'string', 'description' => 'Limit to a source by name (see list_sources).'],
						'limit'  => ['type' => 'integer', 'description' => 'Max results to return (default 50, max 200).'],
						'page'   => ['type' => 'integer', 'description' => 'Page number for pagination (default 1).'],
					],
					'required' => ['hook'],
				],
			],
			[
				'name' => 'list_sources',
				'description' => 'List the indexed sources (WordPress core, plugins, themes) and how many hooks each contains. '
					. 'Use this to discover valid values for the "source" argument of search_hooks.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => (object)[],
				],
			],
			[
				'name' => 'cast_source',
				'description' => 'Trigger a (re)scan of indexed sources from disk, so the index can be refreshed without '
					. 'leaving the client. Pass "source" (a source name or id) to re-cast one source, or omit it / pass '
					. '"all": true to re-cast every source configured in config.php plus any added through the web UI. '
					. 'Returns how many hooks and callers each source yielded. Use get_index_status afterwards to confirm.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'source' => ['type' => 'string', 'description' => 'Name or numeric id of a single source to re-cast. Omit to cast everything.'],
						'all'    => ['type' => 'boolean', 'description' => 'Re-cast all known sources. Implied when "source" is omitted.'],
					],
				],
			],
			[
				'name' => 'get_index_status',
				'description' => 'Report the state of the hook index: total sources, hooks, and callers, when each source was '
					. 'last cast, and whether its files are still present on disk. Use this to check whether the index is '
					. 'populated and current before relying on search results, and to confirm a cast_source run.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => (object)[],
				],
			],
		];
	}

	/**
	 * @param mixed $id
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	private function callTool($id, array $params): array
	{
		$name = (string)($params['name'] ?? '');
		$args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

		try {
			$payload = match ($name) {
				'search_hooks'     => $this->toolSearchHooks($args),
				'list_sources'     => $this->toolListSources(),
				'get_hook'         => $this->toolGetHook($args),
				'get_callers'      => $this->toolGetCallers($args),
				'cast_source'      => $this->toolCastSource($args),
				'get_index_status' => $this->toolGetIndexStatus(),
				default            => throw new \InvalidArgumentException("Unknown tool: {$name}"),
			};
			return $this->result($id, $this->toolText($payload, false));
		} catch (\Throwable $e) {
			return $this->result($id, $this->toolText(['error' => $e->getMessage()], true));
		}
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function toolSearchHooks(array $args): array
	{
		$query = trim((string)($args['query'] ?? ''));
		$type = ($args['type'] ?? '') === 'action' || ($args['type'] ?? '') === 'filter' ? (string)$args['type'] : '';
		$hideDynamic = array_key_exists('include_dynamic', $args) ? !$args['include_dynamic'] : false;
		$limit = min(100, max(1, (int)($args['limit'] ?? 20)));
		$page = max(1, (int)($args['page'] ?? 1));

		$sourceId = null;
		if (!empty($args['source'])) {
			$sourceId = $this->resolveSourceId((string)$args['source']);
			if ($sourceId === null) {
				$names = array_column($this->db->getAllSources(), 'name');
				throw new \InvalidArgumentException(
					"No source named \"{$args['source']}\". Available: " . implode(', ', $names)
				);
			}
		}

		$res = $this->search->search($query, $type, $sourceId, $hideDynamic, $page, $limit);

		$hooks = array_map(static function (array $r): array {
			return [
				'id'          => (int)$r['id'],
				'name'        => $r['name'],
				'type'        => $r['type'],
				'is_dynamic'  => (bool)$r['is_dynamic'],
				'source'      => $r['source_name'],
				'file'        => $r['file_path'],
				'line'        => (int)$r['line_number'],
				'summary'     => $r['docblock'] ?: null,
			];
		}, $res['results']);

		return [
			'total'    => $res['total'],
			'page'     => $res['page'],
			'per_page' => $res['per_page'],
			'count'    => count($hooks),
			'hooks'    => $hooks,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toolListSources(): array
	{
		$sources = array_map(static function (array $s): array {
			return [
				'id'         => (int)$s['id'],
				'name'       => $s['name'],
				'type'       => $s['type'],
				'version'    => $s['version'] ?: null,
				'hook_count' => (int)$s['hook_count'],
				'last_cast'  => $s['last_indexed_at'] ?: null,
			];
		}, $this->db->getAllSources());

		return ['count' => count($sources), 'sources' => $sources];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function toolGetHook(array $args): array
	{
		$hookId = (int)($args['id'] ?? 0);
		if ($hookId <= 0) {
			throw new \InvalidArgumentException('An integer "id" is required.');
		}

		$hook = $this->db->getHookById($hookId);
		if (!$hook) {
			throw new \InvalidArgumentException("No hook with id {$hookId}.");
		}

		$parameters = $hook['parameters'] ? (json_decode($hook['parameters'], true) ?: []) : [];
		$fn = $hook['type'] === 'action' ? 'add_action' : 'add_filter';

		return [
			'id'             => (int)$hook['id'],
			'name'           => $hook['name'],
			'type'           => $hook['type'],
			'is_dynamic'     => (bool)$hook['is_dynamic'],
			'deprecated'     => (bool)$hook['deprecated'],
			'source'         => $hook['source_name'],
			'since'          => $hook['since_version'] ?: null,
			'file'           => $hook['file_path'],
			'line'           => (int)$hook['line_number'],
			'documentation'  => $hook['docblock'] ?: null,
			'parameters'     => $parameters,
			'raw_expression' => $hook['raw_expression'] ?: null,
			'callers'        => $this->db->countCallersForHook($hook['name']),
			'usage'          => "{$fn}( '{$hook['name']}', 'your_function' );",
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function toolGetCallers(array $args): array
	{
		$hook = trim((string)($args['hook'] ?? ''));
		if ($hook === '') {
			throw new \InvalidArgumentException('A "hook" name is required.');
		}

		$type = ($args['type'] ?? '') === 'action' || ($args['type'] ?? '') === 'filter' ? (string)$args['type'] : '';
		$limit = min(200, max(1, (int)($args['limit'] ?? 50)));
		$page = max(1, (int)($args['page'] ?? 1));

		$sourceId = null;
		if (!empty($args['source'])) {
			$sourceId = $this->resolveSourceId((string)$args['source']);
			if ($sourceId === null) {
				$names = array_column($this->db->getAllSources(), 'name');
				throw new \InvalidArgumentException(
					"No source named \"{$args['source']}\". Available: " . implode(', ', $names)
				);
			}
		}

		$rows = $this->db->getCallers($hook, $type, $sourceId);

		// Order by priority - the order WordPress will actually run these in.
		// Numeric priorities sort ascending; non-numeric ones (constants, vars)
		// fall to the end since their value isn't known statically.
		usort($rows, static function (array $a, array $b): int {
			$pa = $a['priority'];
			$pb = $b['priority'];
			$na = is_numeric($pa);
			$nb = is_numeric($pb);
			if ($na && $nb) {
				return (float)$pa <=> (float)$pb;
			}
			if ($na !== $nb) {
				return $na ? -1 : 1; // numeric first
			}
			return strcmp((string)($a['source_name'] ?? ''), (string)($b['source_name'] ?? ''));
		});

		$total = count($rows);
		$offset = ($page - 1) * $limit;
		$slice = array_slice($rows, $offset, $limit);

		$callers = array_map(static function (array $r): array {
			return [
				'hook'          => $r['hook_name'],
				'type'          => $r['type'],
				'callback'      => $r['callback'] ?: null,
				// WordPress defaults: priority 10, one accepted argument.
				'priority'      => $r['priority'] !== null && $r['priority'] !== '' ? $r['priority'] : '10',
				'accepted_args' => $r['accepted_args'] !== null && $r['accepted_args'] !== '' ? $r['accepted_args'] : '1',
				'is_dynamic'    => (bool)$r['is_dynamic'],
				'source'        => $r['source_name'],
				'file'          => $r['file_path'],
				'line'          => (int)$r['line_number'],
				'raw_hook'      => $r['raw_expression'] ?: null,
			];
		}, $slice);

		return [
			'hook'     => $hook,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $limit,
			'count'    => count($callers),
			'callers'  => $callers,
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function toolCastSource(array $args): array
	{
		if ($this->indexer === null) {
			throw new \RuntimeException('Casting is not available: the MCP server was started without an indexer.');
		}

		$target = isset($args['source']) ? trim((string)$args['source']) : '';
		$all = !empty($args['all']) || $target === '';

		if (!$all) {
			return $this->castOne($target);
		}

		// Cast everything: sources declared in config.php plus any added through
		// the web UI (origin wporg/upload), as long as their files are on disk.
		$results = [];
		$seen = [];
		foreach (($this->config['sources'] ?? []) as $source) {
			if (empty($source['path']) || !is_dir($source['path'])) {
				$results[] = ['name' => $source['name'] ?? '(unknown)', 'ok' => false, 'message' => 'Files not found on disk.'];
				continue;
			}
			$hooks = $this->indexer->indexSource($source);
			$results[] = ['name' => $source['name'], 'ok' => true, 'hooks' => $hooks];
			$seen[strtolower((string)$source['name'])] = true;
		}

		foreach ($this->db->getAllSources() as $source) {
			$uiManaged = in_array($source['origin'] ?? 'path', ['wporg', 'upload'], true);
			if (!$uiManaged || isset($seen[strtolower($source['name'])])) {
				continue;
			}
			$results[] = $this->recastById((int)$source['id'], $source['name']);
		}

		$ok = array_filter($results, static fn($r) => !empty($r['ok']));
		return [
			'ok'      => count($ok) === count($results),
			'message' => count($ok) . ' of ' . count($results) . ' source(s) cast.',
			'results' => array_values($results),
		];
	}

	/**
	 * Cast a single source identified by numeric id or name. Falls back to
	 * config.php for sources that exist on disk but haven't been indexed yet.
	 *
	 * @return array<string, mixed>
	 */
	private function castOne(string $target): array
	{
		// Prefer config.php as the authoritative location for a named source: its
		// path is what the operator configured, whereas a source row's stored path
		// can be stale (e.g. seeded under a different layout). This mirrors how an
		// "all" cast indexes config sources.
		if (!ctype_digit($target)) {
			foreach (($this->config['sources'] ?? []) as $candidate) {
				if (strcasecmp((string)($candidate['name'] ?? ''), $target) === 0) {
					if (empty($candidate['path']) || !is_dir($candidate['path'])) {
						throw new \RuntimeException("Source \"{$target}\" is configured but its files are missing on disk.");
					}
					$hooks = $this->indexer->indexSource($candidate);
					$row = ['name' => $candidate['name'], 'ok' => true, 'hooks' => $hooks];
					return ['ok' => true, 'message' => "Cast {$candidate['name']} - {$hooks} hooks.", 'results' => [$row]];
				}
			}
		}

		// Otherwise re-cast an indexed source by numeric id or name (this is the
		// path for sources added through the web UI, which aren't in config.php).
		$source = ctype_digit($target) ? $this->db->getSourceById((int)$target) : null;
		if ($source === null) {
			foreach ($this->db->getAllSources() as $candidate) {
				if (strcasecmp($candidate['name'], $target) === 0) {
					$source = $candidate;
					break;
				}
			}
		}

		if ($source !== null) {
			$result = $this->recastById((int)$source['id'], $source['name']);
			return ['ok' => !empty($result['ok']), 'message' => $result['message'] ?? '', 'results' => [$result]];
		}

		throw new \InvalidArgumentException("No source matching \"{$target}\". Use list_sources or get_index_status to see what's available.");
	}

	/**
	 * Re-cast an indexed source from its files on disk. Prefers SourceManager
	 * (which guards against missing paths) when available.
	 *
	 * @return array<string, mixed>
	 */
	private function recastById(int $id, string $name): array
	{
		if ($this->sources !== null) {
			$res = $this->sources->recast($id);
			return ['name' => $name, 'ok' => !empty($res['ok']), 'message' => $res['message'] ?? '', 'hooks' => $res['hooks'] ?? null];
		}

		$source = $this->db->getSourceById($id);
		if (!$source || empty($source['path']) || !is_dir($source['path'])) {
			return ['name' => $name, 'ok' => false, 'message' => 'Files are missing on disk.'];
		}
		$hooks = $this->indexer->indexSource([
			'name'    => $source['name'],
			'type'    => $source['type'],
			'version' => $source['version'] ?? '',
			'path'    => $source['path'],
		]);
		return ['name' => $name, 'ok' => true, 'hooks' => $hooks];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toolGetIndexStatus(): array
	{
		$sources = $this->db->getAllSources();
		$callerCounts = $this->db->getCallerCounts();

		$totalHooks = 0;
		$totalCallers = 0;
		$lastCast = null;

		$rows = array_map(static function (array $s) use ($callerCounts, &$totalHooks, &$totalCallers, &$lastCast): array {
			$id = (int)$s['id'];
			$hookCount = (int)$s['hook_count'];
			$callerCount = $callerCounts[$id] ?? 0;
			$totalHooks += $hookCount;
			$totalCallers += $callerCount;

			$last = $s['last_indexed_at'] ?: null;
			if ($last !== null && ($lastCast === null || $last > $lastCast)) {
				$lastCast = $last;
			}

			return [
				'id'           => $id,
				'name'         => $s['name'],
				'type'         => $s['type'],
				'version'      => $s['version'] ?: null,
				'hook_count'   => $hookCount,
				'caller_count' => $callerCount,
				'last_cast'    => $last,
				'files_exist'  => !empty($s['path']) && is_dir($s['path']),
			];
		}, $sources);

		return [
			'source_count'  => count($rows),
			'total_hooks'   => $totalHooks,
			'total_callers' => $totalCallers,
			'last_cast'     => $lastCast,
			'can_cast'      => $this->indexer !== null,
			'sources'       => $rows,
		];
	}

	private function resolveSourceId(string $name): ?int
	{
		foreach ($this->db->getAllSources() as $source) {
			if (strcasecmp($source['name'], $name) === 0) {
				return (int)$source['id'];
			}
		}
		return null;
	}

	// ----------------------------------------------------- JSON-RPC plumbing

	/**
	 * Wrap tool output as MCP content. The JSON text block is the portable
	 * format every client understands; we also include structuredContent.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function toolText(array $payload, bool $isError): array
	{
		$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		return [
			'content'           => [['type' => 'text', 'text' => $json]],
			'structuredContent' => $payload,
			'isError'           => $isError,
		];
	}

	/**
	 * @param mixed $id
	 * @param mixed $result
	 * @return array<string, mixed>
	 */
	private function result($id, $result): array
	{
		return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
	}

	/**
	 * @param mixed $id
	 * @return array<string, mixed>
	 */
	private function error($id, int $code, string $message): array
	{
		return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
	}

	/**
	 * @param array<string, mixed> $msg
	 */
	private function send(array $msg): void
	{
		fwrite($this->out, json_encode($msg, JSON_UNESCAPED_SLASHES) . "\n");
		fflush($this->out);
	}
}
