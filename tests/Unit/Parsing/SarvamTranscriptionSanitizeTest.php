<?php

namespace Tests\Unit\Parsing;

use App\Services\AiVisionExtractionService;
use Tests\TestCase;

class SarvamTranscriptionSanitizeTest extends TestCase
{
    public function test_drops_photo_and_deity_description_lines(): void
    {
        $raw = "वधूवर सूचक केंद्र\n"
            ."एका महिलाचा फोटो दिसत आहे.\n"
            ."हे चित्र भगवान गणेशाचे आहे.\n"
            ."मुलीचे नांव : कु. दिव्या हेमंत जाधव\n"
            ."रक्तगट : A+\n";

        $clean = AiVisionExtractionService::sanitizeTransientParseInputText($raw);

        $this->assertStringContainsString('मुलीचे नांव', $clean);
        $this->assertStringContainsString('रक्तगट', $clean);
        $this->assertStringNotContainsString('महिलाचा फोटो', $clean);
        $this->assertStringNotContainsString('भगवान गणेशाचे', $clean);
    }

    public function test_drops_bureau_header_lines(): void
    {
        $raw = "विवाह सूचक\nमुलीचे नांव : कु. दिव्या हेमंत जाधव\n";
        $clean = AiVisionExtractionService::sanitizeTransientParseInputText($raw);
        $this->assertStringNotContainsString('विवाह सूचक', $clean);
        $this->assertStringContainsString('मुलीचे नांव', $clean);
    }

    public function test_drops_sarvam_photo_prose_without_biodata_labels(): void
    {
        $raw = "*एका लांब, काळ्या केसांच्या तरुणीचे हे पूर्ण-लांबीचे छायाचित्र आहे. तिने साडी परिधान केली असून ती कॅमेऱ्याकडे हसत पाहते. पार्श्वभूमी अस्पष्ट (ब्लर) आहे.*\n"
            ."मुलीचे नाव - कु.प्रियांका उत्तम फडतरे\n"
            ."जन्म तारीख - २४-२५/१० / १९९४\n";

        $clean = AiVisionExtractionService::sanitizeTransientParseInputText($raw);

        $this->assertStringNotContainsString('छायाचित्र', $clean);
        $this->assertStringNotContainsString('पार्श्वभूमी', $clean);
        $this->assertStringContainsString('कु.प्रियांका', $clean);
        $this->assertStringContainsString('जन्म तारीख', $clean);
    }
}
