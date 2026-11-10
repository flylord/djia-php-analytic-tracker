<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

final class CurlTransport implements Transport
{
    public function post(string $url, array $headers, string $body, float $timeoutSeconds): int
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return 0;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => (int) round($timeoutSeconds * 1000),
            CURLOPT_NOSIGNAL => true,
        ]);
        $result = curl_exec($ch);
        $status = $result === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status;
    }
}
