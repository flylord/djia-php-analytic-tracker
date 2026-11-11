<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

final class Visitor {
	public const COOKIE = 'ci_aid';

	public static function anonymousId(array $cookies): ?string {
		$value = $cookies[self::COOKIE] ?? null;
		if (!is_string($value) || preg_match('/\A[\x20-\x7E]{1,128}\z/', $value) !== 1) {
			return null;
		}

		return $value;
	}
}
