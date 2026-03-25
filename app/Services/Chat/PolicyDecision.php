<?php

namespace App\Services\Chat;

class PolicyDecision
{
    public function __construct(
        public bool $allowed,
        public string $code,
        public string $humanMessage = '',
        public ?\DateTimeInterface $lockedUntil = null,
        public array $meta = [],
    ) {}

    public static function allow(array $meta = []): self
    {
        return new self(true, 'allowed', '', null, $meta);
    }

    public static function deny(string $code, string $humanMessage, ?\DateTimeInterface $lockedUntil = null, array $meta = []): self
    {
        return new self(false, $code, $humanMessage, $lockedUntil, $meta);
    }
}

