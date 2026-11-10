<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

final class FileOutbox implements Outbox
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public function push(string $eventId, array $payload): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Outbox directory is not creatable');
        }
        $json = json_encode($payload);
        if ($json === false) {
            throw new \RuntimeException('Outbox payload is not encodable');
        }
        $final = $this->path($eventId);
        $temp = $this->directory . '/.tmp-' . bin2hex(random_bytes(8));
        if (@file_put_contents($temp, $json) === false) {
            throw new \RuntimeException('Outbox write failed');
        }
        if (!@rename($temp, $final)) {
            @unlink($temp);
            throw new \RuntimeException('Outbox rename failed');
        }
    }

    public function peek(int $limit): array
    {
        $files = glob($this->directory . '/*.json');
        if ($files === false || $limit <= 0) {
            return [];
        }
        $entries = [];
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            $entries[] = [$mtime === false ? 0 : $mtime, basename($file), $file];
        }
        usort($entries, static function (array $a, array $b): int {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });

        $result = [];
        foreach ($entries as [, $name, $file]) {
            $raw = @file_get_contents($file);
            $payload = $raw === false ? null : json_decode($raw, true);
            if (!is_array($payload)) {
                if ($raw !== false) {
                    @unlink($file);
                }
                continue;
            }
            $result[substr($name, 0, -5)] = $payload;
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function remove(array $eventIds): void
    {
        foreach ($eventIds as $eventId) {
            @unlink($this->path((string) $eventId));
        }
    }

    private function path(string $eventId): string
    {
        return $this->directory . '/' . basename($eventId) . '.json';
    }
}
