<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk\Tests;

use CommerceIntelligence\Sdk\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase {
	public function testCreateShape(): void {
		$event = Event::create('order_completed');
		$data = $event->toArray();

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event->id());
		$this->assertSame($event->id(), $data['event_id']);
		$this->assertSame('order_completed', $data['event']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $data['timestamp']);
		$this->assertSame('{}', json_encode($data['properties']));
	}

	public function testOptionalFieldsAreCarried(): void {
		$data = Event::create('x', ['a' => 1], 'anon', 'u1', 's1', 'https://e.test/', 'UA')->toArray();

		$this->assertSame('anon', $data['anonymous_id']);
		$this->assertSame('u1', $data['user_id']);
		$this->assertSame('s1', $data['session_id']);
		$this->assertSame('https://e.test/', $data['url']);
		$this->assertSame('UA', $data['user_agent']);
		$this->assertSame(['a' => 1], $data['properties']);
	}
}
