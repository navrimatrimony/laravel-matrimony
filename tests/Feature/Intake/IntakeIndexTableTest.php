<?php

namespace Tests\Feature\Intake;

use App\Models\BiodataIntake;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeIndexTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_index_displays_visible_intake_id_column(): void
    {
        $user = User::factory()->create();

        $intake = BiodataIntake::create([
            'original_filename' => 'sample-biodata.jpg',
            'raw_ocr_text' => 'sample text',
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'approved_by_user' => false,
        ]);

        $this->actingAs($user)
            ->get(route('intake.index'))
            ->assertOk()
            ->assertSeeText('Intake ID')
            ->assertSeeText('#'.$intake->id)
            ->assertSeeText('sample-biodata.jpg');
    }
}
