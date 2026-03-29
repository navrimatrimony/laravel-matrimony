<?php

namespace Tests\Unit;

use App\Services\Parsing\IntakeParsedJsonUtf8Sanitizer;
use PHPUnit\Framework\TestCase;

class IntakeParsedJsonUtf8SanitizerTest extends TestCase
{
    public function test_nested_invalid_utf8_string_becomes_json_encodable(): void
    {
        $bad = "मराठी\x80नाव";
        $this->assertFalse(mb_check_encoding($bad, 'UTF-8'));

        $payload = [
            'core' => [
                'full_name' => $bad,
                'ok' => 'सुस्थिर',
            ],
            'contacts' => [],
        ];

        $stats = [];
        $clean = IntakeParsedJsonUtf8Sanitizer::sanitize($payload, $stats);

        $this->assertGreaterThan(0, $stats['strings_fixed'] ?? 0);
        $this->assertIsString($clean['core']['full_name']);
        $this->assertTrue(mb_check_encoding($clean['core']['full_name'], 'UTF-8'));
        $this->assertSame('सुस्थिर', $clean['core']['ok']);

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function test_valid_marathi_unchanged(): void
    {
        $s = 'कु. प्राजक्ता सुभाष पानसरे';
        $stats = [];
        $out = IntakeParsedJsonUtf8Sanitizer::sanitize(['core' => ['full_name' => $s]], $stats);
        $this->assertSame(0, $stats['strings_fixed'] ?? 0);
        $this->assertSame($s, $out['core']['full_name']);
    }
}
