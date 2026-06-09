<?php

namespace Database\Factories;

use App\Models\SuchakPipeline;
use App\Models\SuchakPipelineEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakPipelineEvent>
 */
class SuchakPipelineEventFactory extends Factory
{
    protected $model = SuchakPipelineEvent::class;

    public function definition(): array
    {
        return [
            'pipeline_id' => SuchakPipeline::factory(),
            'event_type' => SuchakPipelineEvent::EVENT_REQUEST_CREATED,
            'actor_type' => SuchakPipelineEvent::ACTOR_USER,
            'actor_id' => null,
            'event_note' => null,
            'created_at' => now(),
        ];
    }
}
