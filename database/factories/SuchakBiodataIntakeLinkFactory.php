<?php

namespace Database\Factories;

use App\Models\BiodataIntake;
use App\Models\SuchakAccount;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakBiodataIntakeLink>
 */
class SuchakBiodataIntakeLinkFactory extends Factory
{
    protected $model = SuchakBiodataIntakeLink::class;

    public function definition(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create(['user_id' => $user->id]);

        return [
            'suchak_account_id' => $account->id,
            'biodata_intake_id' => BiodataIntake::query()->create([
                'uploaded_by' => $user->id,
                'raw_ocr_text' => 'Factory biodata text',
                'intake_status' => 'uploaded',
                'parse_status' => 'pending',
                'approved_by_user' => false,
                'intake_locked' => false,
                'snapshot_schema_version' => 1,
            ])->id,
            'matrimony_profile_id' => null,
            'source_status' => SuchakBiodataIntakeLink::STATUS_INTAKE_UPLOADED,
            'created_by_user_id' => $user->id,
        ];
    }
}
