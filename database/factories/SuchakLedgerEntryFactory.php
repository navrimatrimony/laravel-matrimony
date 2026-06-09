<?php

namespace Database\Factories;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakLedgerEntry>
 */
class SuchakLedgerEntryFactory extends Factory
{
    protected $model = SuchakLedgerEntry::class;

    public function definition(): array
    {
        return [
            'suchak_account_id' => SuchakAccount::factory(),
            'matrimony_profile_id' => MatrimonyProfile::factory(),
            'pipeline_id' => null,
            'collaboration_request_id' => null,
            'entry_type' => SuchakLedgerEntry::TYPE_REGISTRATION_FEE_EXPECTED,
            'amount' => null,
            'currency' => 'INR',
            'status' => SuchakLedgerEntry::STATUS_EXPECTED,
            'due_date' => null,
            'paid_at' => null,
            'note' => 'Private Suchak ledger note.',
        ];
    }

    public function withPipeline(): self
    {
        return $this->state([
            'pipeline_id' => SuchakPipeline::factory(),
        ]);
    }

    public function withCollaboration(): self
    {
        return $this->state([
            'collaboration_request_id' => SuchakCollaborationRequest::factory(),
        ]);
    }
}
