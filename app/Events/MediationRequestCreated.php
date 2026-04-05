<?php

namespace App\Events;

use App\Models\ContactRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a mediator {@see ContactRequest} (type=mediator) is stored. Listeners may push to WhatsApp, CRM, etc.
 */
class MediationRequestCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ContactRequest $mediationRequest
    ) {}
}
