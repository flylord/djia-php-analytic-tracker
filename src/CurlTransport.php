<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

final class CurlTransport implements Transport {
	private const MIN_TIMEOUT_MS = 100;
	private const MAX_CONNECT_MS = 1000;

	/**
	 * @return int[] connect and total timeout in milliseconds
	 */
	public static function timeoutsMs(float $timeoutSeconds): array {
		$total = max(self::MIN_TIMEOUT_MS, (int)round($timeoutSeconds * 1000));

		return [min(self::MAX_CONNECT_MS, $total), $total];
	}

	public function post(string $url, array $headers, string $body, float $timeoutSeconds, ?string &$responseBody = null): int {
		[$connectMs, $totalMs] = self::timeoutsMs($timeoutSeconds);
		$ch = curl_init($url);
		if ($ch === false) {
			return 0;
		}
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT_MS => $connectMs,
			CURLOPT_TIMEOUT_MS => $totalMs,
			CURLOPT_NOSIGNAL => true,
		]);
		$result = curl_exec($ch);
		$status = $result === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		$responseBody = is_string($result) ? $result : null;

		return $status;
	}
}
