<?php

namespace Database\Factories;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakProfileNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SuchakProfileNote>
 */
class SuchakProfileNoteFactory extends Factory
{
    protected $model = SuchakProfileNote::class;

    public function definition(): array
    {
        return [
            'suchak_account_id' => SuchakAccount::factory(),
            'matrimony_profile_id' => MatrimonyProfile::factory(),
            'collaboration_request_id' => null,
            'note_type' => SuchakProfileNote::TYPE_GENERAL,
            'note_text' => 'Private Suchak note for follow-up.',
            'visibility' => SuchakProfileNote::VISIBILITY_PRIVATE,
            'follow_up_at' => null,
        ];
    }

    public function withCollaboration(): self
    {
        return $this->state([
            'collaboration_request_id' => SuchakCollaborationRequest::factory(),
        ]);
    }
}
