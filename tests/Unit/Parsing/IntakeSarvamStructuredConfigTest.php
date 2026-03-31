<?php

namespace Tests\Unit\Parsing;

use Tests\TestCase;

class IntakeSarvamStructuredConfigTest extends TestCase
{
    public function test_sarvam_structured_model_is_locked_to_sarvam_m(): void
    {
        $this->assertSame('sarvam-m', (string) config('intake.sarvam_structured.model'));
    }
}

