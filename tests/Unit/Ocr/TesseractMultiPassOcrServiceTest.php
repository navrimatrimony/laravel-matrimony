<?php

use App\Models\AdminSetting;
use App\Services\Ocr\ImagePreprocessingService;
use App\Services\Ocr\TesseractMultiPassOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

class FakeTesseractMultiPassOcrServiceForTest extends TesseractMultiPassOcrService
{
    /** @var list<string|\Throwable> */
    public array $outputs = [];

    /** @var list<array<string, mixed>>|null */
    public ?array $variantsOverride = null;

    /**
     * @return list<array<string, mixed>>
     */
    protected function imageVariants(
        string $absolutePath,
        string $relativeStoredPath,
        ?string $originalName,
        ?string $presetOverride
    ): array {
        if ($this->variantsOverride !== null) {
            return $this->variantsOverride;
        }

        return parent::imageVariants($absolutePath, $relativeStoredPath, $originalName, $presetOverride);
    }

    protected function runTesseractAttempt(string $path, array $languages, int $psm): string
    {
        $next = array_shift($this->outputs);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return (string) ($next ?? '');
    }
}

function tesseractMultipassTempImage(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ocr_multipass_test_'.uniqid('', true).'.png';
    file_put_contents($path, 'not-a-real-image-but-readable');

    return $path;
}

function tesseractMultipassOriginalVariant(string $path): array
{
    return [
        'key' => 'original',
        'path' => $path,
        'preset' => null,
        'preprocess_used' => false,
        'fallback_used' => false,
        'cleanup' => false,
        'meta' => ['driver' => null, 'steps' => []],
    ];
}

beforeEach(function () {
    config([
        'ocr.tesseract_multipass.enabled' => true,
        'ocr.tesseract_multipass.psm_modes' => [6],
        'ocr.tesseract_multipass.max_attempts' => 6,
        'ocr.tesseract_multipass.english_fallback_enabled' => false,
        'ocr.tesseract_multipass.imagemagick_cli_enabled' => false,
        'ocr.preprocessing.cleanup_enabled' => true,
        'app.debug' => false,
    ]);
});

test('scoring prefers Marathi biodata labels over Latin garbage', function () {
    $service = app(TesseractMultiPassOcrService::class);

    $garbage = $service->scoreText('AAAA BBBB CCCC XX YY ZZ 123 ### ###');
    $marathi = $service->scoreText("नाव : पूजा पाटील\nजन्म तारीख : 12/03/1996\nशिक्षण : B.Com\nमोबाईल : 9876543210");

    expect($marathi['score'])->toBeGreaterThan($garbage['score'])
        ->and($marathi['label_hits'])->toBeGreaterThan($garbage['label_hits']);
});

test('scoring prefers text with name dob mobile and height labels', function () {
    $service = app(TesseractMultiPassOcrService::class);

    $short = $service->scoreText("नाव : राहुल\nजन्म : 1996");
    $rich = $service->scoreText("नाव : राहुल पाटील\nजन्म तारीख : 12/03/1996\nउंची : 5 फुट 7 इंच\nमोबाईल : 9876543210\nशिक्षण : B.Com");

    expect($rich['score'])->toBeGreaterThan($short['score'])
        ->and($rich['mobile_like_count'])->toBe(1);
});

test('failed OCR attempts are ignored and best valid attempt is returned', function () {
    AdminSetting::setValue('intake_ocr_language_hint', 'mr');
    config(['ocr.tesseract_multipass.psm_modes' => [6, 4, 11]]);

    $path = tesseractMultipassTempImage();
    $service = new FakeTesseractMultiPassOcrServiceForTest(app(ImagePreprocessingService::class));
    $service->variantsOverride = [tesseractMultipassOriginalVariant($path)];
    $service->outputs = [
        new RuntimeException('tesseract failed'),
        'Latin garbage ABC DEF 123',
        "नाव : सीमा जाधव\nजन्म तारीख : 08/08/1997\nउंची : 5 फुट 4 इंच\nमोबाईल : 9876543210\nशिक्षण : M.Com",
    ];

    $result = $service->extractFromImage($path, 'intakes/test.png', 'test.png');

    expect($result['text'])->toContain('सीमा जाधव')
        ->and($result['debug']['chosen_psm'])->toBe(11)
        ->and($result['debug']['failed_attempt_count'])->toBe(1);

    @unlink($path);
});

test('preprocessing unavailable falls back to original image attempts', function () {
    AdminSetting::setValue('intake_ocr_language_hint', 'mr');
    config([
        'ocr.tesseract_multipass.psm_modes' => [6],
        'ocr.tesseract_multipass.preprocessing_presets' => ['resolved'],
    ]);

    $path = tesseractMultipassTempImage();
    $mock = Mockery::mock(ImagePreprocessingService::class);
    $mock->shouldReceive('resolvePreset')->andReturn('marathi_printed');
    $mock->shouldReceive('shouldPreprocess')->andReturn(true);
    $mock->shouldReceive('preprocessForOcr')->andReturn([
        'used' => false,
        'preset' => 'marathi_printed',
        'output_path' => null,
        'output_absolute_path' => null,
        'source_path' => $path,
        'fallback_used' => false,
        'meta' => [
            'driver' => 'none',
            'skipped_reason' => 'no_supported_image_driver',
            'steps' => [],
        ],
    ]);

    $service = new FakeTesseractMultiPassOcrServiceForTest($mock);
    $service->outputs = ["नाव : fallback\nमोबाईल : 9876543210"];

    $result = $service->extractFromImage($path, 'intakes/test.png', 'test.png');

    expect($result['text'])->toContain('fallback')
        ->and($result['debug']['final_ocr_input_path'])->toBe($path)
        ->and($result['debug']['preprocess_used'])->toBeFalse()
        ->and($result['debug']['variants_summary'])->toHaveCount(1);

    @unlink($path);
});

test('multi output selection chooses the strongest Marathi biodata candidate', function () {
    AdminSetting::setValue('intake_ocr_language_hint', 'mr');
    config(['ocr.tesseract_multipass.psm_modes' => [6, 4, 11]]);

    $path = tesseractMultipassTempImage();
    $service = new FakeTesseractMultiPassOcrServiceForTest(app(ImagePreprocessingService::class));
    $service->variantsOverride = [tesseractMultipassOriginalVariant($path)];
    $service->outputs = [
        'Latin OCR GARBAGE XX YY ZZ 111',
        "नाव : रोहन\nजन्म : 1995",
        "नाव : रोहन देशमुख\nजन्म तारीख : 10/10/1995\nउंची : 5 फुट 8 इंच\nमोबाईल : 9876543210\nशिक्षण : BE\nव्यवसाय : Engineer",
    ];

    $result = $service->extractFromImage($path, 'intakes/test.png', 'test.png');

    expect($result['text'])->toContain('रोहन देशमुख')
        ->and($result['debug']['score_meta']['label_hits'])->toBeGreaterThanOrEqual(5);

    @unlink($path);
});
