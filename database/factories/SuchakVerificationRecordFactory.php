<?php

namespace Database\Factories;

use App\Models\SuchakAccount;
use App\Models\SuchakVerificationRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakVerificationRecord>
 */
class SuchakVerificationRecordFactory extends Factory
{
    protected $model = SuchakVerificationRecord::class;

    public function definition(): array
    {
        return [
            'suchak_account_id' => SuchakAccount::factory(),
            'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
            'document_path' => null,
            'admin_status' => SuchakVerificationRecord::STATUS_PENDING,
            'admin_user_id' => null,
            'remarks' => null,
            'verified_at' => null,
            'rejected_at' => null,
        ];
    }
}
