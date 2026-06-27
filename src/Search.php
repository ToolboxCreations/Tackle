<?php

declare(strict_types=1);

require_once __DIR__ . '/DocBlock.php';

class Search
{
	private Database $db;

	public function __construct(Database $db)
	{
		$this->db = $db;
	}

	/**
	 * Search for hooks.
	 *
	 * @param string $query
	 * @param string $type
	 * @param int|null $sourceId
	 * @param bool $hideDynamic
	 * @param int $page
	 * @param int $perPage
	 * @param callable|null $weightCallback Optional callback: function(array $result, int $weight): int
	 * @return array
	 */
	public function search(
		string $query = '',
		string $type = '',
		?int $sourceId = null,
		bool $hideDynamic = false,
		int $page = 1,
		int $perPage = 20,
		?callable $weightCallback = null
	): array {
		$pdo = $this->db->getPdo();
		$query = trim($query);

		// Build the WHERE clause
		$conditions = [];
		$params = [];

		if ($query !== '') {
			// Always match the hook name as a substring. This is what users
			// expect when they type a partial word like "ord" or "order" --
			// FTS alone only matches whole tokens, so a partial token would
			// otherwise return nothing even though "or" (which falls back to
			// LIKE) returns plenty.
			$nameCondition = 'h.name LIKE ?';
			$nameParams = ["%$query%"];

			// For queries long enough to tokenise, fold in full-text matches so
			// docblock hits and multi-word relevance are surfaced too. FTS is an
			// enhancement here, not the sole gate -- if it finds nothing (or is
			// unavailable) we still return the name substring matches.
			$hookIds = [];
			if (strlen($query) >= 3) {
				$hookIds = array_column($this->searchFts($query, $pdo), 'id');
			}

			if (!empty($hookIds)) {
				$placeholders = implode(',', array_fill(0, count($hookIds), '?'));
				$conditions[] = "($nameCondition OR h.id IN ($placeholders))";
				$params = array_merge($params, $nameParams, $hookIds);
			} else {
				$conditions[] = $nameCondition;
				$params = array_merge($params, $nameParams);
			}
		}

		// Filter by type
		if ($type && in_array($type, ['action', 'filter'])) {
			$conditions[] = "h.type = ?";
			$params[] = $type;
		}

		// Filter by source
		if ($sourceId) {
			$conditions[] = "h.source_id = ?";
			$params[] = $sourceId;
		}

		// Hide dynamic hooks
		if ($hideDynamic) {
			$conditions[] = "h.is_dynamic = 0";
		}

		// Build the query
		$whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

		// Get total count
		$countStmt = $pdo->prepare(<<<SQL
SELECT COUNT(*) as count FROM hooks h
JOIN sources s ON h.source_id = s.id
$whereClause
SQL);
		$countStmt->execute($params);
		$total = (int)$countStmt->fetch()['count'];

		// Get paginated results
		$offset = ($page - 1) * $perPage;
		$sql = <<<SQL
			SELECT h.id, h.name, h.type, s.name as source_name, s.path as source_path,
			       h.file_path, h.line_number, h.docblock, h.is_dynamic
			FROM hooks h
			JOIN sources s ON h.source_id = s.id
			$whereClause
			LIMIT ? OFFSET ?
			SQL;

		$stmt = $pdo->prepare($sql);
		$executeParams = array_merge($params, [$perPage, $offset]);
		$stmt->execute($executeParams);
		$results = $stmt->fetchAll();

		// Extract meaningful snippet for docblock and compute a weight for each result.
		foreach ($results as &$result) {
			if ($result['docblock']) {
				// Reduce the raw docblock to its first plain sentence, with the
				// comment furniture ("* ", "/**") stripped, for the result card.
				$result['docblock'] = DocBlock::summary($result['docblock']);
			}

			// Normalise the file path (relative, forward slashes) for display.
			$result['file_path'] = $this->db->shortenPath($result['file_path'], $result['source_path'] ?? null);

			// Base weight
			$weight = 0;

			// Prefer filters over actions
			if (isset($result['type']) && $result['type'] === 'filter') {
				$weight += 50;
			}

			// name-based boosts
			if ($query !== '' && isset($result['name'])) {
				$nameLower = strtolower($result['name']);
				$qLower = strtolower($query);

				// exact name match gets a very strong boost so it always beats
				// hooks that only contain the term as a substring
				if ($nameLower === $qLower) {
					$weight += 200;
				} elseif (strpos($nameLower, $qLower) !== false) {
					// substring occurrences should still outrank any result without the term
					$occurrences = substr_count($nameLower, $qLower);
					// base boost of 60 plus a small extra per additional occurrence
					$weight += 60 + max(0, $occurrences - 1) * 15;
				}
			}

			// Non-dynamic hooks slightly preferred
			if (isset($result['is_dynamic']) && (int)$result['is_dynamic'] === 0) {
				$weight += 5;
			}

			// Allow caller to modify the computed weight
			if ($weightCallback !== null && is_callable($weightCallback)) {
				try {
					$newWeight = $weightCallback($result, $weight);
					if (is_int($newWeight) || is_float($newWeight)) {
						$weight = (int)$newWeight;
					}
				} catch (\Throwable $e) {
					// Ignore callback errors and keep the original weight
				}
			}

			$result['_weight'] = $weight;
		}

		// Sort by weight desc, then name asc
		usort($results, function ($a, $b) {
			$wa = $a['_weight'] ?? 0;
			$wb = $b['_weight'] ?? 0;
			if ($wa === $wb) {
				return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
			}
			return $wb <=> $wa;
		});

		return [
			'results' => $results,
			'total' => $total,
			'page' => $page,
			'per_page' => $perPage,
		];
	}

	private function searchFts(string $query, \PDO $pdo): array
	{
		// Escape FTS special characters
		$query = str_replace(['"', '*', '\\'], '', $query);

		try {
			$stmt = $pdo->prepare(<<<'SQL'
SELECT DISTINCT h.id FROM hooks h
JOIN hooks_fts ON h.id = hooks_fts.rowid
WHERE hooks_fts MATCH ?
LIMIT 1000
SQL);

			$stmt->execute([$query]);
			return $stmt->fetchAll();
		} catch (\Throwable $e) {
			// FTS5 may be unavailable or the index out of sync; degrade to the
			// name-substring match handled by the caller rather than failing the
			// whole search.
			return [];
		}
	}
}
