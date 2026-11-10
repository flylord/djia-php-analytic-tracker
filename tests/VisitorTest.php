<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk\Tests;

use CommerceIntelligence\Sdk\Visitor;
use PHPUnit\Framework\TestCase;

final class VisitorTest extends TestCase
{
    public function testAnonymousId(): void
    {
        $this->assertSame('abc', Visitor::anonymousId(['ci_aid' => 'abc']));
        $this->assertNull(Visitor::anonymousId([]));
        $this->assertNull(Visitor::anonymousId(['ci_aid' => str_repeat('a', 129)]));
        $this->assertNull(Visitor::anonymousId(['ci_aid' => "a\nb"]));
        $this->assertNull(Visitor::anonymousId(['ci_aid' => '']));
        $this->assertNull(Visitor::anonymousId(['ci_aid' => ['x']]));
    }
}
