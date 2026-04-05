<?php

namespace App\Events;

use App\Models\ContactRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after the receiver chooses interested / not interested.
 */
class MediationRequestResponded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ContactRequest $mediationRequest
    ) {}
}
