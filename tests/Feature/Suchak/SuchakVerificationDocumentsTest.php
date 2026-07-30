<?php

use App\Models\SuchakAccount;
use App\Models\SuchakVerificationDocument;
use App\Models\SuchakVerificationRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function suchakWithAccount(): User
{
    $user = User::factory()->create();
    SuchakAccount::query()->create([
        'user_id' => $user->id,
        'business_type' => 'individual',
        'suchak_name' => 'Test Suchak',
    ]);

    return $user->fresh();
}

function uploadIdentity(UploadedFile $file)
{
    return test()->postJson('/api/v1/suchak/register/documents', [
        'verification_type' => SuchakVerificationRecord::TYPE_IDENTITY,
        'document' => $file,
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    Sanctum::actingAs(suchakWithAccount());
});

test('a second document is added beside the first, not on top of it', function () {
    uploadIdentity(UploadedFile::fake()->image('aadhaar-front.jpg'))->assertOk();
    uploadIdentity(UploadedFile::fake()->image('aadhaar-back.jpg'))->assertOk();

    $record = SuchakVerificationRecord::query()
        ->where('verification_type', SuchakVerificationRecord::TYPE_IDENTITY)
        ->firstOrFail();

    expect($record->documents)->toHaveCount(2)
        ->and($record->documents->pluck('original_name')->all())
        ->toBe(['aadhaar-front.jpg', 'aadhaar-back.jpg']);

    // Still exactly one verification — the admin decides once about an
    // identity, however many pages it took to show it.
    expect(SuchakVerificationRecord::query()->count())->toBe(1);
});

test('a PDF is accepted alongside images', function () {
    uploadIdentity(UploadedFile::fake()->image('aadhaar.jpg'))->assertOk();
    uploadIdentity(UploadedFile::fake()->create('pan.pdf', 40, 'application/pdf'))->assertOk();

    $record = SuchakVerificationRecord::query()->firstOrFail();
    expect($record->documents)->toHaveCount(2)
        ->and($record->documents->last()->isPdf())->toBeTrue();
});

test('the single-path column keeps naming a file that exists', function () {
    uploadIdentity(UploadedFile::fake()->image('first.jpg'))->assertOk();
    uploadIdentity(UploadedFile::fake()->image('second.jpg'))->assertOk();

    $record = SuchakVerificationRecord::query()->firstOrFail();

    // Older readers follow this column and must not land on a deleted file.
    expect($record->document_path)->toBe($record->documents->last()->document_path);
});

test('a new document reopens an already approved verification', function () {
    uploadIdentity(UploadedFile::fake()->image('front.jpg'))->assertOk();

    $record = SuchakVerificationRecord::query()->firstOrFail();
    $record->forceFill([
        'admin_status' => SuchakVerificationRecord::STATUS_APPROVED,
        'verified_at' => now(),
    ])->save();

    uploadIdentity(UploadedFile::fake()->image('back.jpg'))->assertOk();

    // Approving page one must not leave the record approved once page two
    // arrives, because nobody has looked at page two.
    expect($record->fresh()->admin_status)->toBe(SuchakVerificationRecord::STATUS_PENDING);
});

test('a document can be removed and its file goes with it', function () {
    uploadIdentity(UploadedFile::fake()->image('front.jpg'))->assertOk();
    uploadIdentity(UploadedFile::fake()->image('back.jpg'))->assertOk();

    $record = SuchakVerificationRecord::query()->firstOrFail();
    $doomed = $record->documents->first();
    $path = $doomed->document_path;

    $this->deleteJson('/api/v1/suchak/register/documents/'.$doomed->id)->assertOk();

    expect($record->fresh()->documents)->toHaveCount(1);
    Storage::disk('local')->assertMissing($path);
});

test('removing the last document leaves the requirement unmet, not missing', function () {
    uploadIdentity(UploadedFile::fake()->image('only.jpg'))->assertOk();

    $record = SuchakVerificationRecord::query()->firstOrFail();
    $this->deleteJson('/api/v1/suchak/register/documents/'.$record->documents->first()->id)
        ->assertOk();

    $record->refresh();
    expect($record->exists)->toBeTrue()
        ->and($record->document_path)->toBeNull()
        ->and($record->documents)->toHaveCount(0);
});

test('one Suchak cannot delete another Suchak document', function () {
    uploadIdentity(UploadedFile::fake()->image('mine.jpg'))->assertOk();
    $mine = SuchakVerificationDocument::query()->firstOrFail();

    Sanctum::actingAs(suchakWithAccount());

    $this->deleteJson('/api/v1/suchak/register/documents/'.$mine->id)
        ->assertStatus(404);

    expect(SuchakVerificationDocument::query()->whereKey($mine->id)->exists())->toBeTrue();
});

test('the onboarding status lists every document sent', function () {
    uploadIdentity(UploadedFile::fake()->image('front.jpg'))->assertOk();
    uploadIdentity(UploadedFile::fake()->create('pan.pdf', 30, 'application/pdf'))->assertOk();

    $response = $this->getJson('/api/v1/suchak/register/status')->assertOk();

    $identity = collect($response->json('data.onboarding.document_rows'))
        ->firstWhere('type', SuchakVerificationRecord::TYPE_IDENTITY);

    expect($identity['documents'])->toHaveCount(2)
        ->and($identity['documents'][1]['is_pdf'])->toBeTrue()
        ->and($identity['uploaded'])->toBeTrue();
});
