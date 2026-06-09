<?php

namespace Database\Factories;

use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakConsentEvent>
 */
class SuchakConsentEventFactory extends Factory
{
    protected $model = SuchakConsentEvent::class;

    public function definition(): array
    {
        return [
            'consent_id' => SuchakConsent::factory(),
            'event_type' => SuchakConsentEvent::EVENT_REQUESTED,
            'event_note' => null,
            'actor_type' => SuchakConsentEvent::ACTOR_SUCHAK,
            'actor_id' => null,
            'created_at' => now(),
        ];
    }
}
