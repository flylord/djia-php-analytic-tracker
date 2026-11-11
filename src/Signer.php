<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

final class Signer {
	public static function sign(string $secret, string $timestamp, string $body): string {
		return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
	}
}
