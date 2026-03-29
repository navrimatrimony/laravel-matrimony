<?php

namespace Tests\Unit\Intake;

use App\Services\Intake\IntakeBiodataIdentityFingerprint;
use Tests\TestCase;

class IntakeBiodataIdentityFingerprintTest extends TestCase
{
    private function baseSnippet(): string
    {
        return <<<'TXT'
मुलीचे नांव : कु. दिव्या हेमंत जाधव
जन्मतारीख : 15/06/2000
उंची : 5'4"
संपर्क 9811111111
शिक्षण बी.कॉम
नोकरी खाजगी
TXT;
    }

    public function test_fingerprint_matches_when_spacing_and_honorific_vary(): void
    {
        $f = new IntakeBiodataIdentityFingerprint;
        $a = $this->baseSnippet();
        $b = str_replace('मुलीचे नांव : कु.', 'मुलीचे नांव: ', $a);
        $b = str_replace('9811111111', ' 9811111111 ', $b);

        $fa = $f->fingerprintForProvider('openai', $a);
        $fb = $f->fingerprintForProvider('openai', $b);

        $this->assertNotNull($fa);
        $this->assertSame($fa, $fb);
    }

    public function test_fingerprint_null_without_phone(): void
    {
        $f = new IntakeBiodataIdentityFingerprint;
        $t = "मुलीचे नांव: टेस्ट\nजन्मतारीख: 01/01/1999\n";

        $this->assertNull($f->fingerprintForProvider('openai', $t));
    }

    public function test_provider_isolation(): void
    {
        $f = new IntakeBiodataIdentityFingerprint;
        $t = $this->baseSnippet();
        $this->assertNotSame(
            $f->fingerprintForProvider('openai', $t),
            $f->fingerprintForProvider('sarvam', $t)
        );
    }

    public function test_identity_reuse_evidence_accepts_honorific_and_spacing_variants(): void
    {
        $f = new IntakeBiodataIdentityFingerprint;
        $a = $f->extractSignals($this->baseSnippet());
        $bText = str_replace('मुलीचे नांव : कु. दिव्या', "मुलीचे नांव: दिव्या", $this->baseSnippet());
        $b = $f->extractSignals($bText);

        $this->assertNotNull($f->identityReuseEvidenceScore($a, $b));
    }

    public function test_identity_reuse_evidence_null_when_dob_conflicts(): void
    {
        $f = new IntakeBiodataIdentityFingerprint;
        $a = $f->extractSignals($this->baseSnippet());
        $bText = str_replace('15/06/2000', '16/06/2000', $this->baseSnippet());
        $b = $f->extractSignals($bText);

        $this->assertNull($f->identityReuseEvidenceScore($a, $b));
    }

    public function test_identity_reuse_evidence_null_when_phone_differs(): void
    {
        $f = new IntakeBiodataIdentityFingerprint;
        $a = $f->extractSignals($this->baseSnippet());
        $bText = str_replace('9811111111', '9822222222', $this->baseSnippet());
        $b = $f->extractSignals($bText);

        $this->assertNull($f->identityReuseEvidenceScore($a, $b));
    }
}
