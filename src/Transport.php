<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

interface Transport
{
    /**
     * @param string[] $headers header lines such as "Name: value"
     * @return int HTTP status, 0 on network error
     */
    public function post(string $url, array $headers, string $body, float $timeoutSeconds): int;
}
