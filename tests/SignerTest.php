<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk\Tests;

use CommerceIntelligence\Sdk\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase {
	public function testKnownVector(): void {
		$expected = hash_hmac('sha256', '1700000000.{"e":[]}', 'dev_secret_test_change_me');
		$this->assertSame($expected, Signer::sign('dev_secret_test_change_me', '1700000000', '{"e":[]}'));
	}
}
