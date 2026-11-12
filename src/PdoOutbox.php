<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

final class PdoOutbox implements Outbox {
	private const DELETE_CHUNK = 500;

	private \PDO $pdo;
	private string $table;
	private string $driver;
	private $onError;

	public function __construct(\PDO $pdo, string $table = 'ci_outbox', ?callable $onError = null) {
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table) !== 1) {
			throw new \InvalidArgumentException('Outbox table name is not valid');
		}
		$driver = (string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
		if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
			throw new \InvalidArgumentException('Outbox PDO driver is not supported: ' . $driver);
		}
		$this->pdo = $pdo;
		$this->table = $table;
		$this->driver = $driver;
		$this->onError = $onError;
	}

	public function createTable(): void {
		switch ($this->driver) {
			case 'mysql':
				$sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					event_id VARCHAR(36) NOT NULL,
					payload LONGTEXT NOT NULL,
					created_at DATETIME NOT NULL,
					PRIMARY KEY (id),
					UNIQUE KEY uniq_{$this->table}_event_id (event_id)
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB";
				break;
			case 'pgsql':
				$sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
					id BIGSERIAL PRIMARY KEY,
					event_id VARCHAR(36) NOT NULL UNIQUE,
					payload TEXT NOT NULL,
					created_at TIMESTAMP NOT NULL
				)";
				break;
			default:
				$sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					event_id VARCHAR(36) NOT NULL UNIQUE,
					payload TEXT NOT NULL,
					created_at TEXT NOT NULL
				)";
		}
		$this->run($sql, []);
	}

	public function push(string $eventId, array $payload): void {
		$json = json_encode($payload);
		if ($json === false) {
			throw new \RuntimeException('Outbox payload is not encodable');
		}
		$columns = "{$this->table} (event_id, payload, created_at) VALUES (?, ?, ?)";
		switch ($this->driver) {
			case 'mysql':
				$sql = "INSERT IGNORE INTO {$columns}";
				break;
			case 'pgsql':
				$sql = "INSERT INTO {$columns} ON CONFLICT (event_id) DO NOTHING";
				break;
			default:
				$sql = "INSERT OR IGNORE INTO {$columns}";
		}
		$this->run($sql, [$eventId, $json, gmdate('Y-m-d H:i:s')]);
	}

	public function peek(int $limit): array {
		if ($limit <= 0) {
			return [];
		}
		$statement = $this->run("SELECT event_id, payload FROM {$this->table} ORDER BY id ASC LIMIT " . min($limit, 10000), []);

		$result = [];
		$corrupt = [];
		foreach ($statement->fetchAll(\PDO::FETCH_NUM) as [$eventId, $raw]) {
			$payload = is_string($raw) ? json_decode($raw, true) : null;
			if (is_array($payload)) {
				$result[(string)$eventId] = $payload;
			} else {
				$corrupt[] = (string)$eventId;
			}
		}
		if ($corrupt !== []) {
			$this->report(new \RuntimeException('Corrupt outbox rows removed: ' . implode(',', $corrupt)));
			$this->remove($corrupt);
		}

		return $result;
	}

	public function remove(array $eventIds): void {
		$eventIds = array_values(array_map('strval', $eventIds));
		foreach (array_chunk($eventIds, self::DELETE_CHUNK) as $chunk) {
			$placeholders = implode(',', array_fill(0, count($chunk), '?'));
			$this->run("DELETE FROM {$this->table} WHERE event_id IN ({$placeholders})", $chunk);
		}
	}

	private function run(string $sql, array $params): \PDOStatement {
		try {
			$statement = $this->pdo->prepare($sql);
			if ($statement === false || !$statement->execute($params)) {
				throw new \RuntimeException('Outbox query failed: ' . implode(' ', $this->pdo->errorInfo()));
			}
		} catch (\PDOException $e) {
			throw new \RuntimeException('Outbox query failed: ' . $e->getMessage(), 0, $e);
		}

		return $statement;
	}

	private function report(\Throwable $error): void {
		if ($this->onError === null) {
			return;
		}
		try {
			($this->onError)($error);
		} catch (\Throwable $ignored) {
			return;
		}
	}
}
