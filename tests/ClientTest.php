<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk\Tests;

use CommerceIntelligence\Sdk\Client;
use CommerceIntelligence\Sdk\Config;
use CommerceIntelligence\Sdk\Event;
use CommerceIntelligence\Sdk\FileOutbox;
use CommerceIntelligence\Sdk\Signer;
use CommerceIntelligence\Sdk\Transport;
use PHPUnit\Framework\TestCase;

final class FakeTransport implements Transport
{
    public int $status;
    public ?\Throwable $throw = null;
    public array $calls = [];

    public function __construct(int $status = 200)
    {
        $this->status = $status;
    }

    public function post(string $url, array $headers, string $body, float $timeoutSeconds): int
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

        return $this->status;
    }
}

final class ClientTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ci-client-test-' . bin2hex(random_bytes(6));
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

    private function config(): Config
    {
        return new Config('http://collector.test:8080', 'sk_test_1', 'secret', 2.0);
    }

    private function headerMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $map[$name] = $value;
        }

        return $map;
    }

    public function testFlushSendsSignedBatchAndRemoves(): void
    {
        $outbox = new FileOutbox($this->dir);
        $transport = new FakeTransport(200);
        $client = new Client($this->config(), $outbox, $transport);
        $client->track(Event::create('order_completed', ['order_id' => 'T1']));

        $this->assertSame(1, $client->flush());
        $this->assertSame([], $outbox->peek(10));

        $call = $transport->calls[0];
        $this->assertSame('http://collector.test:8080/v1/s', $call['url']);
        $h = $this->headerMap($call['headers']);
        $this->assertSame('application/json', $h['Content-Type']);
        $this->assertSame('sk_test_1', $h['X-CI-Key-Id']);
        $this->assertMatchesRegularExpression('/^\d+$/', $h['X-CI-Timestamp']);
        $this->assertSame(Signer::sign('secret', $h['X-CI-Timestamp'], $call['body']), $h['X-CI-Signature']);
        $decoded = json_decode($call['body'], true);
        $this->assertCount(1, $decoded['e']);
        $this->assertSame('order_completed', $decoded['e'][0]['event']);
    }

    public function testFlushKeepsEventsOnNetworkError(): void
    {
        $outbox = new FileOutbox($this->dir);
        $client = new Client($this->config(), $outbox, new FakeTransport(0));
        $client->track(Event::create('x'));

        $this->assertSame(0, $client->flush());
        $this->assertCount(1, $outbox->peek(10));
    }

    public function testFlushKeepsEventsOn401(): void
    {
        $outbox = new FileOutbox($this->dir);
        $client = new Client($this->config(), $outbox, new FakeTransport(401));
        $client->track(Event::create('x'));

        $this->assertSame(0, $client->flush());
        $this->assertCount(1, $outbox->peek(10));
    }

    public function testTrackNeverThrowsWhenOutboxUnwritable(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ci-file');
        $client = new Client($this->config(), new FileOutbox($file), new FakeTransport(200));

        $client->track(Event::create('x'));
        $this->assertTrue(true);
        unlink($file);
    }

    public function testFlushNeverThrows(): void
    {
        $outbox = new FileOutbox($this->dir);
        $transport = new FakeTransport(200);
        $transport->throw = new \RuntimeException('boom');
        $client = new Client($this->config(), $outbox, $transport);
        $client->track(Event::create('x'));

        $this->assertSame(0, $client->flush());
        $this->assertCount(1, $outbox->peek(10));
    }

    public function testFlushRespectsMax(): void
    {
        $outbox = new FileOutbox($this->dir);
        $transport = new FakeTransport(200);
        $client = new Client($this->config(), $outbox, $transport);
        for ($i = 0; $i < 3; $i++) {
            $client->track(Event::create('x'));
        }

        $this->assertSame(2, $client->flush(2));
        $this->assertCount(1, $outbox->peek(10));
    }

    public function testFlushCapsBodyAtOneMebibyte(): void
    {
        $outbox = new FileOutbox($this->dir);
        $transport = new FakeTransport(200);
        $client = new Client($this->config(), $outbox, $transport);
        for ($i = 0; $i < 3; $i++) {
            $client->track(Event::create('big', ['blob' => str_repeat('a', 400000)]));
        }

        $this->assertSame(2, $client->flush());
        $this->assertLessThanOrEqual(1048576, strlen($transport->calls[0]['body']));
        $this->assertCount(1, $outbox->peek(10));
    }

    public function testOversizedSingleEventIsDropped(): void
    {
        $outbox = new FileOutbox($this->dir);
        $transport = new FakeTransport(200);
        $client = new Client($this->config(), $outbox, $transport);
        $client->track(Event::create('huge', ['blob' => str_repeat('a', 1100000)]));
        $client->track(Event::create('ok'));

        $this->assertSame(1, $client->flush());
        $this->assertSame([], $outbox->peek(10));
        $this->assertCount(1, json_decode($transport->calls[0]['body'], true)['e']);
    }

    public function testFlushSkipsWhenLockHeld(): void
    {
        $outbox = new FileOutbox($this->dir);
        $transport = new FakeTransport(200);
        $client = new Client($this->config(), $outbox, $transport);
        $client->track(Event::create('x'));

        $path = sys_get_temp_dir() . '/ci-sdk-' . md5('http://collector.test:8080' . 'sk_test_1') . '.lock';
        $handle = fopen($path, 'c');
        flock($handle, LOCK_EX | LOCK_NB);

        $this->assertSame(0, $client->flush());
        $this->assertSame([], $transport->calls);

        flock($handle, LOCK_UN);
        fclose($handle);
        $this->assertSame(1, $client->flush());
    }
}
