<?php

namespace App\DTO;

class RuleResult
{
    public function __construct(
        public bool $allowed,
        public string $code,
        public string $message,
        public ?array $action = null,
    ) {}

    public static function allow(): self
    {
        return new self(true, 'OK', '', null);
    }

    /**
     * @return array{allowed: bool, code: string, message: string, action: ?array}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'code' => $this->code,
            'message' => $this->message,
            'action' => $this->action,
        ];
    }
}
