<?php

namespace Database\Factories;

use App\Models\SuchakPipeline;
use App\Models\SuchakProfileRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakPipeline>
 */
class SuchakPipelineFactory extends Factory
{
    protected $model = SuchakPipeline::class;

    public function definition(): array
    {
        $request = SuchakProfileRequest::factory()->create();
        $lockedAt = now();

        return [
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $request->target_matrimony_profile_id,
            'requesting_matrimony_profile_id' => $request->requesting_matrimony_profile_id,
            'selected_suchak_account_id' => $request->selected_suchak_account_id,
            'representation_id' => $request->representation_id,
            'pipeline_status' => SuchakPipeline::STATUS_PENDING,
            'attribution_locked_at' => $lockedAt,
            'lock_expires_at' => $lockedAt->copy()->addHours(48),
            'sla_status' => SuchakPipeline::SLA_WITHIN,
            'converted_at' => null,
            'closed_at' => null,
        ];
    }
}
