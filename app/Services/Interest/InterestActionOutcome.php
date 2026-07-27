<?php

namespace App\Services\Interest;

use App\DTO\RuleResult;
use App\Models\Interest;

/**
 * Result of one interest action executed by {@see InterestActionService}.
 *
 * Both surfaces — web ({@see \App\Http\Controllers\InterestController}) and mobile
 * ({@see \App\Http\Controllers\Api\InterestApiController}) — get this identical outcome
 * and only decide how to *render* it. No business decision lives in a controller.
 */
final class InterestActionOutcome
{
    /**
     * @param  string  $messageKey  Translation key for the success message ('' when denied)
     * @param  bool  $duplicate  True when the interest already existed: no row created,
     *                           no notification sent, no send quota consumed
     */
    private function __construct(
        public readonly bool $ok,
        public readonly ?RuleResult $error,
        public readonly int $status,
        public readonly ?Interest $interest,
        public readonly string $messageKey,
        public readonly bool $duplicate,
    ) {}

    public static function success(?Interest $interest, string $messageKey): self
    {
        return new self(true, null, 200, $interest, $messageKey, false);
    }

    public static function alreadyExists(Interest $interest, string $messageKey): self
    {
        return new self(true, null, 200, $interest, $messageKey, true);
    }

    public static function denied(RuleResult $error, int $status = 422): self
    {
        return new self(false, $error, $status, null, '', false);
    }

    /**
     * Localized success message (Latin digits only — frozen workspace rule).
     */
    public function message(): string
    {
        return $this->messageKey === '' ? '' : (string) __($this->messageKey);
    }
}
