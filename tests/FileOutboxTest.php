<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk\Tests;

use CommerceIntelligence\Sdk\FileOutbox;
use PHPUnit\Framework\TestCase;

final class FileOutboxTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ci-outbox-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testPushPeekRemove(): void
    {
        $outbox = new FileOutbox($this->dir);
        $outbox->push('a', ['n' => 1]);
        $outbox->push('b', ['n' => 2]);
        $outbox->push('c', ['n' => 3]);

        $first = $outbox->peek(2);
        $this->assertSame(['a', 'b'], array_keys($first));
        $this->assertSame(['n' => 1], $first['a']);

        $outbox->remove(['a', 'b']);
        $this->assertSame(['c'], array_keys($outbox->peek(10)));
    }
}
