<?php

use App\Models\Caste;
use App\Models\CasteAlias;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Models\User;
use App\Services\MasterData\MasterDataTranslationImportService;
use App\Services\MasterData\ReligionCasteSubCasteResolver;
use App\Support\MasterData\ReligionCasteSubcasteSlugger;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

test('religion display_label prefers Marathi when locale is mr', function () {
    $r = Religion::create([
        'key' => 't-hindu',
        'label' => 'Hindu',
        'label_en' => 'Hindu',
        'label_mr' => 'हिंदू',
        'is_active' => true,
    ]);
    app()->setLocale('mr');
    expect($r->display_label)->toBe('हिंदू');
    app()->setLocale('en');
    expect($r->display_label)->toBe('Hindu');
});

test('caste display_label prefers English when locale is en even if Marathi present', function () {
    $rel = Religion::create([
        'key' => 't-rel',
        'label' => 'Islam',
        'label_en' => 'Islam',
        'label_mr' => 'इस्लाम',
        'is_active' => true,
    ]);
    $c = Caste::create([
        'religion_id' => $rel->id,
        'key' => 't-mali',
        'label' => 'Mali',
        'label_en' => 'Mali',
        'label_mr' => 'माळी',
        'is_active' => true,
    ]);
    app()->setLocale('en');
    expect($c->display_label)->toBe('Mali');
    app()->setLocale('mr');
    expect($c->display_label)->toBe('माळी');
});

test('translation import service updates label_en label_mr and legacy label', function () {
    $rel = Religion::create([
        'key' => 't-import',
        'label' => 'Old',
        'label_en' => 'Old',
        'label_mr' => null,
        'is_active' => true,
    ]);

    app(MasterDataTranslationImportService::class)->importFromDecodedJson([
        [
            'entity_type' => 'religion',
            'key' => 't-import',
            'scope' => [],
            'label_en' => 'New English',
            'label_mr' => 'नवा',
            'aliases_en' => [],
            'aliases_mr' => [],
            'ocr_variants' => [],
        ],
    ]);

    $rel->refresh();
    expect($rel->label_en)->toBe('New English');
    expect($rel->label_mr)->toBe('नवा');
    expect($rel->label)->toBe('New English');
});

test('master import translations artisan command succeeds when JSON exists', function () {
    $exit = Artisan::call('master:import-religion-caste-subcaste-translations');
    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('Translations imported.');
});

test('GET api v1 castes returns locale label plus label_en and label_mr', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $rel = Religion::create([
        'key' => 't-api-rel',
        'label' => 'Hindu',
        'label_en' => 'Hindu',
        'label_mr' => 'हिंदू',
        'is_active' => true,
    ]);
    Caste::create([
        'religion_id' => $rel->id,
        'key' => 't-brahmin',
        'label' => 'Brahmin',
        'label_en' => 'Brahmin',
        'label_mr' => 'ब्राह्मण',
        'is_active' => true,
    ]);

    app()->setLocale('mr');
    $res = $this->getJson('/api/v1/castes?religion_id='.$rel->id);
    $res->assertOk();
    $row = collect($res->json())->first();
    expect($row['label'])->toBe('ब्राह्मण');
    expect($row['label_en'])->toBe('Brahmin');
    expect($row['label_mr'])->toBe('ब्राह्मण');

    app()->setLocale('en');
    $res2 = $this->getJson('/api/v1/castes?religion_id='.$rel->id);
    $row2 = collect($res2->json())->first();
    expect($row2['label'])->toBe('Brahmin');
});

test('resolver matches exact English and Marathi labels', function () {
    $slugger = app(ReligionCasteSubcasteSlugger::class);
    $rel = Religion::create([
        'key' => $slugger->makeKey('Islam'),
        'label' => 'Islam',
        'label_en' => 'Islam',
        'label_mr' => 'इस्लाम',
        'is_active' => true,
    ]);
    $caste = Caste::create([
        'religion_id' => $rel->id,
        'key' => $slugger->makeKey('Mali'),
        'label' => 'Mali',
        'label_en' => 'Mali',
        'label_mr' => 'माळी',
        'is_active' => true,
    ]);
    $sub = SubCaste::create([
        'caste_id' => $caste->id,
        'key' => $slugger->makeKey('Somvanshi'),
        'label' => 'Somvanshi',
        'label_en' => 'Somvanshi',
        'label_mr' => 'सोमवंशी',
        'is_active' => true,
        'status' => 'approved',
    ]);

    $resolver = app(ReligionCasteSubCasteResolver::class);

    $a = $resolver->resolve('Islam', 'Mali', 'Somvanshi');
    expect($a['religion_id'])->toBe($rel->id);
    expect($a['caste_id'])->toBe($caste->id);
    expect($a['sub_caste_id'])->toBe($sub->id);

    $b = $resolver->resolve('इस्लाम', 'माळी', 'सोमवंशी');
    expect($b['religion_id'])->toBe($rel->id);
    expect($b['caste_id'])->toBe($caste->id);
    expect($b['sub_caste_id'])->toBe($sub->id);
});

test('resolver matches aliases and does not match garbage to an id', function () {
    $slugger = app(ReligionCasteSubcasteSlugger::class);
    $rel = Religion::create([
        'key' => $slugger->makeKey('Islam'),
        'label' => 'Islam',
        'label_en' => 'Islam',
        'label_mr' => 'इस्लाम',
        'is_active' => true,
    ]);
    $caste = Caste::create([
        'religion_id' => $rel->id,
        'key' => $slugger->makeKey('Shaikh'),
        'label' => 'Shaikh',
        'label_en' => 'Shaikh',
        'label_mr' => 'शेख',
        'is_active' => true,
    ]);

    CasteAlias::create([
        'caste_id' => $caste->id,
        'alias' => 'Sheikh',
        'alias_type' => 'en',
        'normalized_alias' => 'sheikh',
    ]);

    $resolver = app(ReligionCasteSubCasteResolver::class);
    $r = $resolver->resolve('Islam', 'Sheikh', '');
    expect($r['caste_id'])->toBe($caste->id);
    expect($r['caste_match'])->toContain('alias');

    $bad = $resolver->resolve('zzzznotareligion999', '', '');
    expect($bad['religion_id'])->toBeNull();
});

test('resolver keeps existing ids when new raw text is too weak', function () {
    $slugger = app(ReligionCasteSubcasteSlugger::class);
    $rel = Religion::create([
        'key' => $slugger->makeKey('Hindu'),
        'label' => 'Hindu',
        'label_en' => 'Hindu',
        'label_mr' => 'हिंदू',
        'is_active' => true,
    ]);
    $caste = Caste::create([
        'religion_id' => $rel->id,
        'key' => $slugger->makeKey('Maratha'),
        'label' => 'Maratha',
        'label_en' => 'Maratha',
        'label_mr' => 'मराठा',
        'is_active' => true,
    ]);

    $resolver = app(ReligionCasteSubCasteResolver::class);
    $out = $resolver->resolve('nonsense-religion', 'nonsense-caste', '', $rel->id, $caste->id, null);
    expect($out['religion_id'])->toBe($rel->id);
    expect($out['caste_id'])->toBe($caste->id);
});
