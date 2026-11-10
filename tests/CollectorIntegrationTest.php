<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk\Tests;

use CommerceIntelligence\Sdk\Client;
use CommerceIntelligence\Sdk\Config;
use CommerceIntelligence\Sdk\Event;
use CommerceIntelligence\Sdk\FileOutbox;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
final class CollectorIntegrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ci-int-test-' . bin2hex(random_bytes(6));
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

    private function endpoint(): string
    {
        $url = getenv('CI_COLLECTOR_URL');

        return $url !== false && $url !== '' ? $url : 'http://localhost:11405';
    }

    private function clickhouseCount(string $eventId): int
    {
        $sql = "SELECT count() FROM ci_db.events FINAL WHERE event_id = '" . $eventId . "' AND source = 'server' AND site_id = 'site_test'";
        $ch = curl_init('http://localhost:11402/?user=ci_user&password=ci_password&database=ci_db');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $sql, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $out = curl_exec($ch);
        curl_close($ch);

        return $out === false ? 0 : (int) trim((string) $out);
    }

    public function testOrderCompletedReachesClickHouse(): void
    {
        $outbox = new FileOutbox($this->dir);
        $client = new Client(new Config($this->endpoint(), 'sk_test_1', 'dev_secret_test_change_me'), $outbox);
        $event = Event::create('order_completed', ['order_id' => 'T1', 'revenue' => 25000, 'currency' => 'RSD']);
        $client->track($event);

        $this->assertSame(1, $client->flush());

        $count = 0;
        for ($i = 0; $i < 20; $i++) {
            $count = $this->clickhouseCount($event->id());
            if ($count === 1) {
                break;
            }
            usleep(500000);
        }
        $this->assertSame(1, $count);
    }

    public function testWrongSecretKeepsEvent(): void
    {
        $outbox = new FileOutbox($this->dir);
        $client = new Client(new Config($this->endpoint(), 'sk_test_1', 'wrong'), $outbox);
        $client->track(Event::create('order_completed', ['order_id' => 'T2']));

        $this->assertSame(0, $client->flush());
        $this->assertCount(1, $outbox->peek(10));
    }
}
