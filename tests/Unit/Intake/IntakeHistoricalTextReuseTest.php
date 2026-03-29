<?php

namespace Tests\Unit\Intake;

use App\Models\BiodataIntake;
use App\Models\User;
use App\Services\Intake\IntakeBiodataIdentityFingerprint;
use App\Services\Intake\IntakeExtractionReuseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeHistoricalTextReuseTest extends TestCase
{
    use RefreshDatabase;

    private function longMarathiBiodataText(): string
    {
        return str_repeat(
            "मुलीचे नांव : कु. टेस्ट परसे\nजन्मतारीख : 12/03/1996\nमो 9876543210\nशिक्षण बी.कॉम\nनोकरी खाजगी\nजन्म\nनाव\nधर्म हिंदू\n",
            8
        );
    }

    public function test_load_historical_raw_ocr_peers_finds_older_intake_with_same_identity(): void
    {
        $user = User::factory()->create();
        $text = $this->longMarathiBiodataText();

        $intake1 = BiodataIntake::create([
            'raw_ocr_text' => $text,
            'uploaded_by' => $user->id,
            'parse_status' => 'parsed',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $intake2 = BiodataIntake::create([
            'raw_ocr_text' => $text,
            'uploaded_by' => $user->id,
            'parse_status' => 'pending',
            'intake_status' => 'DRAFT',
            'intake_locked' => false,
            'approved_by_user' => false,
        ]);

        $resolver = app(IntakeExtractionReuseResolver::class);
        $fp = app(IntakeBiodataIdentityFingerprint::class);
        $sig = $fp->extractSignals($text);
        $peers = $resolver->loadHistoricalRawOcrPeers($intake2, $sig, 40);

        $this->assertNotEmpty($peers);
        $this->assertSame((int) $intake1->id, $peers[0]['intake_id']);
        $this->assertArrayHasKey('identity_evidence_score', $peers[0]);
    }
}
