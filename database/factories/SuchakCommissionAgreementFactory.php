<?php

namespace Database\Factories;

use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakCommissionAgreement>
 */
class SuchakCommissionAgreementFactory extends Factory
{
    protected $model = SuchakCommissionAgreement::class;

    public function definition(): array
    {
        return [
            'collaboration_request_id' => SuchakCollaborationRequest::factory(),
            'groom_side_suchak_account_id' => SuchakAccount::factory(),
            'bride_side_suchak_account_id' => SuchakAccount::factory(),
            'agreement_type' => SuchakCommissionAgreement::TYPE_COLLABORATION_ACK,
            'split_type' => SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED,
            'groom_side_share' => null,
            'bride_side_share' => null,
            'fixed_amount' => null,
            'currency' => 'INR',
            'agreement_text_snapshot' => SuchakCommissionAgreement::MVP_ACK_TEXT,
            'accepted_by_groom_suchak_at' => null,
            'accepted_by_bride_suchak_at' => null,
            'agreement_status' => SuchakCommissionAgreement::STATUS_PENDING,
        ];
    }
}
