<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk\Tests;

use CommerceIntelligence\Sdk\PdoOutbox;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension pdo_sqlite
 */
final class PdoOutboxTest extends TestCase {
	private \PDO $pdo;

	protected function setUp(): void {
		$this->pdo = new \PDO('sqlite::memory:');
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	}

	private function outbox(?callable $onError = null): PdoOutbox {
		$outbox = new PdoOutbox($this->pdo, 'ci_outbox', $onError);
		$outbox->createTable();

		return $outbox;
	}

	public function testPushPeekRemove(): void {
		$outbox = $this->outbox();
		$outbox->push('a', ['n' => 1]);
		$outbox->push('b', ['n' => 2]);
		$outbox->push('c', ['n' => 3]);

		$first = $outbox->peek(2);
		$this->assertSame(['a', 'b'], array_keys($first));
		$this->assertSame(['n' => 1], $first['a']);

		$outbox->remove(['a', 'b']);
		$this->assertSame(['c'], array_keys($outbox->peek(10)));
	}

	public function testDuplicateEventIdIsIgnored(): void {
		$outbox = $this->outbox();
		$outbox->push('a', ['n' => 1]);
		$outbox->push('a', ['n' => 2]);

		$this->assertSame(['a' => ['n' => 1]], $outbox->peek(10));
	}

	public function testCorruptRowIsReportedAndRemoved(): void {
		$errors = [];
		$outbox = $this->outbox(function (\Throwable $e) use (&$errors): void {
			$errors[] = $e->getMessage();
		});
		$outbox->push('good', ['n' => 1]);
		$this->pdo->exec("INSERT INTO ci_outbox (event_id, payload, created_at) VALUES ('bad', 'not json', '2026-01-01 00:00:00')");

		$this->assertSame(['good'], array_keys($outbox->peek(10)));
		$this->assertCount(1, $errors);
		$this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM ci_outbox')->fetchColumn());
	}

	public function testRemoveManyIdsInChunks(): void {
		$outbox = $this->outbox();
		$ids = [];
		for ($i = 0; $i < 1200; $i++) {
			$ids[] = 'id' . $i;
			$outbox->push('id' . $i, ['n' => $i]);
		}
		$outbox->remove($ids);

		$this->assertSame([], $outbox->peek(10));
	}

	public function testZeroLimitReturnsNothing(): void {
		$outbox = $this->outbox();
		$outbox->push('a', ['n' => 1]);

		$this->assertSame([], $outbox->peek(0));
	}

	public function testInvalidTableNameIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		new PdoOutbox($this->pdo, 'ci_outbox; DROP TABLE x');
	}

	public function testMissingTableFailsWithRuntimeException(): void {
		$outbox = new PdoOutbox($this->pdo, 'missing_table');

		$this->expectException(\RuntimeException::class);
		$outbox->push('a', ['n' => 1]);
	}
}
