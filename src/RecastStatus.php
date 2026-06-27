<?php

declare(strict_types=1);

/**
 * Shared, cross-process record of the recast currently in flight.
 *
 * A recast (or update) walks thousands of files in a single request, and PHP's
 * built-in server hands each request to its own worker. So progress can't live
 * in the requesting process alone - it's written to a small JSON file under
 * data/ that any worker can read. Every browser tab polls /api/recast/status
 * and shows the same global toast, so anyone can see whether a recast is in
 * flight and roughly how long is left.
 */
class RecastStatus
{
	private string $file;

	public function __construct(string $dataDir)
	{
		$this->file = rtrim($dataDir, '/\\') . '/recast-status.json';
	}

	/**
	 * Mark a recast as started. Resets counters so a stale total never shows.
	 *
	 * @param string $initiator Who kicked it off - 'user' (web UI), 'mcp',
	 *        'scheduler', or 'cli' - so every tab can show where it came from.
	 */
	public function begin(string $source, string $initiator = 'user'): void
	{
		$now = time();
		$this->write([
			'active'      => true,
			'source'      => $source,
			'initiator'   => $initiator,
			'done'        => 0,
			'total'       => 0,
			'started_at'  => $now,
			'updated_at'  => $now,
			'finished_at' => null,
			'ok'          => null,
			'message'     => null,
		]);
	}

	/** Record how many files have been parsed so far. */
	public function progress(int $done, int $total): void
	{
		$cur = $this->read();
		if (!$cur) {
			return;
		}
		$cur['active']     = true;
		$cur['done']       = $done;
		$cur['total']      = $total;
		$cur['updated_at'] = time();
		$this->write($cur);
	}

	/** Mark the recast finished, keeping the outcome around for late pollers. */
	public function finish(string $message, bool $ok): void
	{
		$cur = $this->read() ?: [];
		$now = time();
		$cur['active']      = false;
		$cur['finished_at'] = $now;
		$cur['updated_at']  = $now;
		$cur['ok']          = $ok;
		$cur['message']     = $message;
		$this->write($cur);
	}

	/** Current status, or an inert record when nothing has ever run. */
	public function current(): array
	{
		return $this->read() ?: ['active' => false];
	}

	/** @return array<string, mixed>|null */
	private function read(): ?array
	{
		if (!is_file($this->file)) {
			return null;
		}
		$raw = @file_get_contents($this->file);
		if ($raw === false || $raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	/** @param array<string, mixed> $data */
	private function write(array $data): void
	{
		@file_put_contents(
			$this->file,
			json_encode($data, JSON_UNESCAPED_SLASHES),
			LOCK_EX
		);
	}
}
