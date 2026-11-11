<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk\Tests;

use CommerceIntelligence\Sdk\CurlTransport;
use PHPUnit\Framework\TestCase;

final class CurlTransportTest extends TestCase {
	public function testZeroTimeoutIsClampedToMinimum(): void {
		$this->assertSame([100, 100], CurlTransport::timeoutsMs(0.0));
		$this->assertSame([100, 100], CurlTransport::timeoutsMs(-5.0));
	}

	public function testConnectTimeoutNeverExceedsTotal(): void {
		$this->assertSame([500, 500], CurlTransport::timeoutsMs(0.5));
		$this->assertSame([1000, 2000], CurlTransport::timeoutsMs(2.0));
	}
}
