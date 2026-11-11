<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

final class Config {
	private string $endpoint;
	private string $keyId;
	private string $secret;
	private float $timeoutSeconds;

	public function __construct(string $endpoint, string $keyId, string $secret, float $timeoutSeconds = 2.0) {
		$this->endpoint = rtrim($endpoint, '/');
		$this->keyId = $keyId;
		$this->secret = $secret;
		$this->timeoutSeconds = $timeoutSeconds;
	}

	public function endpoint(): string {
		return $this->endpoint;
	}

	public function keyId(): string {
		return $this->keyId;
	}

	public function secret(): string {
		return $this->secret;
	}

	public function timeoutSeconds(): float {
		return $this->timeoutSeconds;
	}
}
