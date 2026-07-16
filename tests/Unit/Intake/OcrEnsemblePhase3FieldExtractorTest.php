<?php

use App\Models\BiodataIntakeOcrAttempt;
use App\Services\Intake\OcrEnsemble\Data\OcrEngineFieldCandidatesDto;
use App\Services\Intake\OcrEnsemble\OcrEnsembleFieldExtractor;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleCommunityExtractor;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleDobNormalizer;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleMobileSelector;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleNameExtractor;
use Tests\TestCase;

uses(TestCase::class);

test('production field extractor returns dto with sixteen fields', function () {
    $text = <<<'TXT'
मुलाचे नाव : अविनाश अर्जुन खोडवे
जन्म तारीख : 04/01/1992
मोबाईल : 8149379216
धर्म : Hindu
जात : Maratha
TXT;

    $dto = app(OcrEnsembleFieldExtractor::class)->extractFromText(
        $text,
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
    );

    expect($dto)->toBeInstanceOf(OcrEngineFieldCandidatesDto::class)
        ->and(array_keys($dto->toFieldMap()))->toBe(OcrEnsemblePhase3Constants::STRUCTURED_FIELDS)
        ->and($dto->field('full_name'))->toContain('अविनाश')
        ->and($dto->field('date_of_birth'))->toBe('1992-01-04')
        ->and($dto->field('primary_contact_number'))->toBe('8149379216')
        ->and($dto->field('religion'))->toBe('Hindu')
        ->and($dto->field('caste'))->toBe('Maratha');
});

test('production field extractor strips ku honorific from name', function () {
    $text = "* कु. अभिजीत अशोक पाटील\nजात : हिंदू - मराठा\nशिक्षण : B.E. Computer\nनोकरी : Software Engineer";

    $dto = app(OcrEnsembleFieldExtractor::class)->extractFromText(
        $text,
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
    );

    expect($dto->field('full_name'))->toBe('अभिजीत अशोक पाटील')
        ->and($dto->field('religion'))->toBe('Hindu')
        ->and($dto->field('caste'))->toBe('Maratha')
        ->and($dto->field('education'))->toContain('B.E.')
        ->and($dto->field('occupation'))->toBe('Software Engineer');
});

test('production mobile selector prefers candidate mobile over relative numbers', function () {
    $lines = [
        'मुलाचे नाव : राहुल शिंदे',
        'वडील मोबाईल : 9123456789',
        'मोबाईल : 9876543210',
        'कौटुंबिक माहिती',
        'आई मोबाईल : 9988776655',
    ];

    $phone = app(OcrEnsembleMobileSelector::class)->selectPrimary($lines);

    expect($phone)->toBe('9876543210');
});

test('production mobile selector recovers मो.नं. on glued megapage OCR lines', function () {
    $line = '// / गणेशाय नमः //नावनवनाथ पाटीलणे तारीखDecember 10, 1995णे वेळ06:50 AM'
        .'णे िठकाणतडवळे जातिहंदू मराठा मो.नं.7057066223,7776010849 वडील मोबाईल 9123456789';

    $phone = app(OcrEnsembleMobileSelector::class)->selectPrimary([$line]);

    expect($phone)->toBe('7057066223');
});

test('production mobile selector prefers phone immediately after संपर्क label', function () {
    $lines = [
        'नाव : अविनाश प्रकाश कदम',
        'संपर्क: 9850959973 8437054414',
        'वडील मोबाईल 9123456789',
    ];

    expect(app(OcrEnsembleMobileSelector::class)->selectPrimary($lines))->toBe('9850959973');
});

test('production mobile selector recovers OCR संपकण नं label', function () {
    $lines = [
        'मुलाचे नाव : शिवाजी',
        'संपकण नं 8698501396',
    ];

    expect(app(OcrEnsembleMobileSelector::class)->selectPrimary($lines))->toBe('8698501396');
});

test('production mobile selector prefers वडील मोबाईल over address मोबाईल', function () {
    $lines = [
        'मुलाचे नाव : अनिकेत जयवंत पाटील',
        'वडील :- श्री.जयवंत तुकाराम पाटील (पोस्टमास्टर) मोबाईल-8805526197',
        'एक(विवाहित) पत्ता-बिउर,ता.शिराळा,जिसांगली मोबाईल-9209905005',
    ];

    expect(app(OcrEnsembleMobileSelector::class)->selectPrimary($lines))->toBe('8805526197');
});

test('production mobile selector prefers संपर्क नंबर over address संपर्क', function () {
    $lines = [
        'मुलाचे नाव : नाथ सिध्देश्वर पाटील',
        'संपर्क नंबर :-- 9940168213',
        'विटा. ता .खानापूर, जि.सांगली. संपर्क : 9604289289',
    ];

    expect(app(OcrEnsembleMobileSelector::class)->selectPrimary($lines))->toBe('9940168213');
});

test('production dob normalizer parses dd/mm/yyyy and recovers ocr digit substitutions', function () {
    $normalizer = app(OcrEnsembleDobNormalizer::class);

    expect($normalizer->normalize('04/01/1992'))->toBe('1992-01-04')
        ->and($normalizer->normalize('O4/O1/1992'))->toBe('1992-01-04')
        ->and($normalizer->normalize('15-08-1998'))->toBe('1998-08-15');
});

test('production dob normalizer recovers fuzzy जन्म label and year glyph ocr errors', function () {
    $normalizer = app(OcrEnsembleDobNormalizer::class);

    // Intake #460-class: ज → अ corruption + 9→3 in year
    expect($normalizer->normalizeFromLines([
        'अन्म तारीख > 24/10/1938 अन्म वेळ + रात्री 09 वा.45 मि',
    ]))->toBe('1998-10-24');

    // Intake #472-class: heavy label garble but date present; year 1396 → 1996
    expect($normalizer->normalize('02/10/1396'))->toBe('1996-10-02');

    // Clean label still works
    expect($normalizer->normalizeFromLines([
        'जन्म तारीख : 04/01/1992',
    ]))->toBe('1992-01-04');
});

test('production dob normalizer reads Marathi month forms present in raw OCR', function () {
    $normalizer = app(OcrEnsembleDobNormalizer::class);

    expect($normalizer->lineLooksLikeDobLabel('जन्म तारीख :-_ ०८ ऑगस्ट १९९७'))->toBeTrue()
        ->and($normalizer->normalizeFromLines([
            'जन्म तारीख :-_ ०८ ऑगस्ट १९९७ जन्म वार व वेळ :-_ शुक्रवार',
        ]))->toBe('1997-08-08')
        ->and($normalizer->normalize('9सप्टेंबट 2000'))->toBe('2000-09-09')
        ->and($normalizer->normalize('December 10, 1995'))->toBe('1995-12-10')
        ->and($normalizer->normalize('24th March 1991'))->toBe('1991-03-24')
        ->and($normalizer->normalize('18 ऑगस्ट1998'))->toBe('1998-08-18')
        ->and($normalizer->normalizeFromLines([
            'जन्मतारीख :_ 18 ऑगस्ट1998 भे > अ',
        ]))->toBe('1998-08-18')
        ->and($normalizer->normalizeFromLines([
            'नाव नवनाथ पाटीलणे तारीखDecember 10, 1995णे वेळ06:50 AM',
        ]))->toBe('1995-12-10')
        // Invalid OCR month 14 → 11 via digit confusion (1↔4)
        ->and($normalizer->normalizeFromLines([
            'जन्म दि :- १६/१४/१९९६',
        ]))->toBe('1996-11-16');
});

test('production field extractor recovers Marathi month DOB from raw-like page text', function () {
    $text = <<<'TXT'
॥ श्री गणेश प्रसन्न ॥
बायोडाटा
जन्म तारीख :-_ ०८ ऑगस्ट १९९७
जन्म वार व वेळ :-_ शुक्रवार दुपारी
TXT;

    $dto = app(OcrEnsembleFieldExtractor::class)->extractFromText(
        $text,
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
    );

    expect($dto->field('date_of_birth'))->toBe('1997-08-08');
});

test('production field extractor recovers dob from corrupted जन्म label line', function () {
    $text = <<<'TXT'
मुलाचे नाव : कु. प्रौती राजेंद्र पाटील
अन्म तारीख > 24/10/1938 अन्म वेळ + रात्री 09 वा.45 मि
मोबाईल : 9145206745
धर्म : Hindu
जात : Maratha
TXT;

    $dto = app(OcrEnsembleFieldExtractor::class)->extractFromText(
        $text,
        OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR,
    );

    expect($dto->field('date_of_birth'))->toBe('1998-10-24');
});

test('production name extractor tolerates ocr text with no candidate name', function () {
    $extractor = app(OcrEnsembleNameExtractor::class);
    $lines = [
        '* कु.',
        'जन्म तारीख : 04/01/1992',
        'मोबाईल : 9876543210',
    ];

    expect($extractor->extract($lines))->toBeNull();
});

test('production name extractor reads English resume Name and biodata-title name lines', function () {
    $extractor = app(OcrEnsembleNameExtractor::class);

    expect($extractor->extract([
        "RESUME Name: - Ms. Sonam Sanjeev Father’s Name: - Mr. Sanjeev Gopi",
    ]))->toBe('Sonam Sanjeev')
        ->and($extractor->extract([
            'मुलाचे नाव : चच.ओंकार सुभाष भोसले. जन्म दिनांक : 08',
        ]))->toBe('ओंकार सुभाष भोसले')
        ->and($extractor->extract([
            'बायोडाटा रेखा शिवदास पाटील जन्मतारीख 15 जून 1999',
        ]))->toBe('रेखा शिवदास पाटील')
        ->and($extractor->extract([
            'र : कु. प्रतीक्षा दशरथ कचरे. जन्म : २४/०३/१९९९',
        ]))->toBe('प्रतीक्षा दशरथ कचरे')
        ->and($extractor->extract([
            'मुलीचे बां : कु. स्नेहा प्रताप पाटील जन्म तारीख : १९',
        ]))->toBe('स्नेहा प्रताप पाटील')
        ->and($extractor->extract([
            'नाव : सुप्रिया गणेशराव बोबडे फार 9 झज',
        ]))->toBe('सुप्रिया गणेशराव बोबडे')
        ->and($extractor->extract([
            'नाव : मोहित पंढरीनाथ पवाट त्स दुस',
        ]))->toBe('मोहित पंढरीनाथ पवाट');
});

test('production name extractor recovers glued नाव and does not truncate चिवाजी', function () {
    $extractor = app(OcrEnsembleNameExtractor::class);

    expect($extractor->extract([
        '// / गणेशाय नमः //नावनवनाथ पाटीलणे तारीखDecember 10, 1995',
    ]))->toContain('नवनाथ')
        ->and($extractor->extract([
            'मुलाचे नाव :- चि. चिवाजी प्रकाि गुरव',
        ]))->toBe('चिवाजी प्रकाि गुरव')
        ->and($extractor->extract([
            '(*) नाब :_ कु.प्रतिक्षा नितिन मगर (0)',
        ]))->toContain('प्रतिक्षा')
        ->and($extractor->extract([
            'श्रीनाथ सिध्देश्वर पाटील',
        ]))->toBe('नाथ सिध्देश्वर पाटील');
});

test('production field extractor extracts candidates from ocr attempts', function () {
    $attempt = new BiodataIntakeOcrAttempt([
        'engine' => BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR,
        'raw_text' => "मुलाचे नाव : Test Candidate\nमोबाईल : 9876543210",
    ]);
    $attempt->id = 101;

    $result = app(OcrEnsembleFieldExtractor::class)->extractCandidates([$attempt]);

    expect($result->isEmpty())->toBeFalse()
        ->and($result->primary()?->engineKey)->toBe(BiodataIntakeOcrAttempt::ENGINE_LARAVEL_NATIVE_OCR)
        ->and($result->primary()?->ocrAttemptId)->toBe(101)
        ->and($result->primary()?->field('primary_contact_number'))->toBe('9876543210');
});

test('production field extractor does not import benchmark classes', function () {
    $paths = [
        app_path('Services/Intake/OcrEnsemble/OcrEnsembleFieldExtractor.php'),
        app_path('Services/Intake/OcrEnsemble/Support'),
    ];

    foreach ($paths as $path) {
        $files = is_dir($path) ? glob($path.'/*.php') ?: [] : [$path];
        foreach ($files as $file) {
            expect((string) file_get_contents($file))->not->toContain('OcrEnsembleBenchmark');
        }
    }
});

test('production community extractor splits hindu maratha jati line', function () {
    $lines = ['जात : हिंदू - मराठा (९६ कुळी)'];
    $result = app(OcrEnsembleCommunityExtractor::class)->extract($lines);

    expect($result['religion'])->toBe('Hindu')
        ->and($result['caste'])->toBe('Maratha')
        ->and($result['sub_caste'])->toBe('96 कुळी');
});

test('production community extractor recovers glued जातिहंदू megapage OCR', function () {
    $lines = ['उंची5 फूट 7 इंच जातिहंदू मराठा (96 कुळी)देवकपाचपालवी'];
    $result = app(OcrEnsembleCommunityExtractor::class)->extract($lines);

    expect($result['religion'])->toBe('Hindu')
        ->and($result['caste'])->toBe('Maratha')
        ->and($result['sub_caste'])->toBe('96 कुळी');
});

test('production community extractor recovers OCR-corrupt जात हहंद line', function () {
    $lines = ['जात :- हहंद –गुरव'];
    $result = app(OcrEnsembleCommunityExtractor::class)->extract($lines);

    expect($result['religion'])->toBe('Hindu');
});

test('production community extractor rejects OCR garbage as religion', function () {
    expect(app(OcrEnsembleCommunityExtractor::class)->normalizeReligion('जात 1 न - मराठा ठे ) | र'))
        ->toBeNull();
});

test('production community extractor recovers कुळ हिंदु मराठा line', function () {
    $lines = ['कुळ : हिंदु मराठा (96 कुळी मराठा)'];
    $result = app(OcrEnsembleCommunityExtractor::class)->extract($lines);

    expect($result['religion'])->toBe('Hindu')
        ->and($result['caste'])->toBe('Maratha');
});

test('production community extractor recovers धर्म-जात compound with Maratha', function () {
    $lines = ['धर्म-जात 1 न - मराठा ठे ) | र'];
    $result = app(OcrEnsembleCommunityExtractor::class)->extract($lines);

    expect($result['religion'])->toBe('Hindu')
        ->and($result['caste'])->toBe('Maratha');
});

test('production gender extractor recovers Ms. on English Name line', function () {
    $lines = [
        'RESUME',
        'Name: - Ms. Sonam Sanjeev',
        'Father’s Name: - Mr. Sanjeev Gopi',
    ];

    expect(app(\App\Services\Intake\OcrEnsemble\Support\OcrEnsembleGenderExtractor::class)->extract($lines))
        ->toBe('female');
});

test('production gender extractor recovers मुलीची माहिती section', function () {
    $lines = ['* मुलीची माहिती *', 'नाव : स्नेहा'];

    expect(app(\App\Services\Intake\OcrEnsemble\Support\OcrEnsembleGenderExtractor::class)->extract($lines))
        ->toBe('female');
});

test('production gender extractor recovers candidate कुमारी honorific', function () {
    $lines = ['नाव : कुमारी प्रतीक्षा नितिन मगर'];

    expect(app(\App\Services\Intake\OcrEnsemble\Support\OcrEnsembleGenderExtractor::class)->extract($lines))
        ->toBe('female');
});

test('production gender extractor does not treat OCR कु. on male name as female', function () {
    $lines = ['नाव: कु. अविनाश प्रकाश कदम'];

    expect(app(\App\Services\Intake\OcrEnsemble\Support\OcrEnsembleGenderExtractor::class)->extract($lines, 'male'))
        ->toBe('male');
});

test('production gender extractor prefers मुलीची माहिती over male fallback', function () {
    $lines = ['* मुलीची माहिती *', 'नाव : स्नेहा'];

    expect(app(\App\Services\Intake\OcrEnsemble\Support\OcrEnsembleGenderExtractor::class)->extract($lines, 'male'))
        ->toBe('female');
});
