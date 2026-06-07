<?php

namespace Tests\Feature\Intake;

use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReparseActionTest extends TestCase
{
    use RefreshDatabase;

    private function marathiBiodataText(): string
    {
        return <<<'TXT'
मुलाचे नाव :- चि.अनिकेत जयवंत पाटील
जन्म दि :- १६/११/१९९६
वर्ण :- निम गोरा
रक्त गट :- B+ve
नोकरी :- SHILPA PHARMA LIFE SCINCE LTD UNIT 1 KARNATK RAICHUR
शेती :- 1 एकर
वडील :- श्री.जयवंत तुकाराम पाटील (पोस्टमास्टर) मोबाईल-८८०५५२६१९७
घराचा पत्ता :- मु.पो.रेठरे हरणाक्ष, ता.वाळवा, जि.सांगली.
| रास | मकर | गण | देव |
| देवक | गण | नक्षत्र | श्रवण |
| चरण | १ले | | |
TXT;
    }

    public function test_owner_reparse_updates_parsed_json_immediately_from_normalized_draft(): void
    {
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.use_normalized_draft_parser' => true]);

        $owner = User::factory()->create();
        $parsedBefore = app(IntakeParsedSnapshotSkeleton::class)->ensure([
            'core' => [
                'full_name' => 'चि.अनिकेत जयवंत पाटील',
                'date_of_birth' => null,
                'blood_group' => null,
                'physical_complexion' => null,
            ],
            'career_history' => [[
                'occupation_title' => null,
                'company_name' => 'OLD COMPANY',
            ]],
            'property_assets' => [],
            'horoscope' => [[
                'rashi' => null,
                'gan' => null,
                'nakshatra' => null,
                'charan' => null,
                'varna' => null,
            ]],
        ]);

        $intake = BiodataIntake::create([
            'raw_ocr_text' => $this->marathiBiodataText(),
            'parsed_json' => $parsedBefore,
            'uploaded_by' => $owner->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $this->actingAs($owner)
            ->post(route('intake.reparse', $intake))
            ->assertRedirect(route('intake.index'));

        $intake->refresh();

        $this->assertSame('parsed', $intake->parse_status);
        $this->assertSame('16/11/1996', $intake->parsed_json['core']['date_of_birth'] ?? null);
        $this->assertSame('B+', $intake->parsed_json['core']['blood_group'] ?? null);
        $this->assertSame('निम गोरा', $intake->parsed_json['core']['complexion'] ?? null);
        $this->assertSame('नोकरी', $intake->parsed_json['core']['occupation_title'] ?? null);
        $this->assertSame(
            'shilpa pharma life scince ltd unit 1 karnatk raichur',
            $intake->parsed_json['career_history'][0]['company_name'] ?? null
        );
        $this->assertSame('land', $intake->parsed_json['property_assets'][0]['asset_type'] ?? null);
        $this->assertStringContainsString('जन्म दि 16/11/1996', (string) $intake->last_parse_input_text);
    }

    public function test_admin_reparse_also_updates_parsed_json_inline(): void
    {
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.use_normalized_draft_parser' => true]);

        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();

        $intake = BiodataIntake::create([
            'raw_ocr_text' => $this->marathiBiodataText(),
            'parsed_json' => app(IntakeParsedSnapshotSkeleton::class)->ensure([
                'core' => [
                    'full_name' => 'चि.अनिकेत जयवंत पाटील',
                    'date_of_birth' => null,
                    'complexion' => null,
                    'blood_group' => null,
                ],
            ]),
            'uploaded_by' => $owner->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.biodata-intakes.reparse', $intake))
            ->assertRedirect(route('admin.biodata-intakes.show', $intake));

        $intake->refresh();
        $this->assertSame('parsed', $intake->parse_status);
        $this->assertSame('16/11/1996', $intake->parsed_json['core']['date_of_birth'] ?? null);
        $this->assertSame('निम गोरा', $intake->parsed_json['core']['complexion'] ?? null);
        $this->assertSame('B+', $intake->parsed_json['core']['blood_group'] ?? null);
    }

    public function test_admin_member_reparse_redirects_to_admin_intake_show_not_member_status(): void
    {
        config(['intake.testing_active_parser' => 'rules_only']);
        config(['intake.use_normalized_draft_parser' => true]);

        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();

        $intake = BiodataIntake::create([
            'raw_ocr_text' => $this->marathiBiodataText(),
            'parsed_json' => app(IntakeParsedSnapshotSkeleton::class)->ensure([
                'core' => ['full_name' => 'चि.अनिकेत जयवंत पाटील'],
            ]),
            'uploaded_by' => $owner->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('intake.reparse', $intake))
            ->assertRedirect(route('admin.biodata-intakes.show', $intake));
    }
}
