<?php

namespace Tests\Unit;

use App\Models\BiodataIntake;
use App\Services\AiVisionExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class SarvamPresignedUploadPutHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_presigned_put_includes_azure_block_blob_header(): void
    {
        if (! class_exists('ZipArchive', false)) {
            $this->markTestSkipped('PHP zip extension (ZipArchive) required for Sarvam flow.');
        }

        config()->set('intake.ai_vision_extract.provider', 'sarvam');
        config()->set('services.sarvam.subscription_key', 'test-sarvam-key');
        config()->set('services.sarvam.base_url', 'https://api.sarvam.ai');
        config()->set('intake.ai_vision_extract.sarvam_poll_seconds', 5);
        config()->set('intake.ai_vision_extract.min_extracted_chars', 40);
        config()->set('intake.ai_vision_extract.min_extracted_non_space', 25);
        config()->set('intake.ai_vision_extract.min_extracted_lines', 2);

        $outZipPath = sys_get_temp_dir().'/sarvam_test_out_'.uniqid('', true).'.zip';
        $z = new ZipArchive;
        $this->assertTrue($z->open($outZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $z->addFromString('page.md', str_repeat("Marathi biodata line.\n", 30));
        $z->close();
        $outZipBytes = (string) file_get_contents($outZipPath);
        @unlink($outZipPath);

        $presignedUploadUrl = 'https://fake-upload.blob.core.windows.net/container/blob?sas=token';
        $presignedDownloadUrl = 'https://fake-download.blob.core.windows.net/container/out.zip?sas=token';

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($outZipBytes, $presignedUploadUrl, $presignedDownloadUrl) {
            $url = $request->url();
            $method = $request->method();

            if ($method === 'PUT' && str_starts_with($url, 'https://fake-upload.blob.core.windows.net')) {
                $headers = $request->headers();
                $blobType = $headers['x-ms-blob-type'][0]
                    ?? $headers['X-Ms-Blob-Type'][0]
                    ?? null;
                if ($blobType !== 'BlockBlob') {
                    return Http::response(
                        'Missing x-ms-blob-type',
                        400,
                        ['Content-Type' => 'application/xml']
                    );
                }

                return Http::response('', 201);
            }

            if ($method === 'GET' && $url === $presignedDownloadUrl) {
                return Http::response($outZipBytes, 200, ['Content-Type' => 'application/zip']);
            }

            if ($method === 'POST' && preg_match('#/doc-digitization/job/v1$#', $url)) {
                return Http::response(['job_id' => 'job-sarvam-put-test'], 200);
            }

            if ($method === 'POST' && str_contains($url, '/upload-files')) {
                return Http::response([
                    'upload_urls' => [
                        'input.zip' => ['file_url' => $presignedUploadUrl],
                    ],
                ], 200);
            }

            if ($method === 'POST' && str_contains($url, '/start')) {
                return Http::response([], 200);
            }

            if ($method === 'GET' && str_contains($url, '/status')) {
                return Http::response(['job_state' => 'Completed'], 200);
            }

            if ($method === 'POST' && str_contains($url, '/download-files')) {
                return Http::response([
                    'download_urls' => [
                        'out.zip' => ['file_url' => $presignedDownloadUrl],
                    ],
                ], 200);
            }

            return Http::response('unexpected: '.$method.' '.$url, 500);
        });

        $rel = 'intakes/test-sarvam-put-headers.jpg';
        $abs = storage_path('app/private/'.$rel);
        if (! is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0777, true);
        }
        @file_put_contents($abs, 'FAKEJPEG');

        $intake = new BiodataIntake([
            'file_path' => $rel,
            'original_filename' => 'test.jpg',
            'raw_ocr_text' => '',
        ]);

        $svc = app(AiVisionExtractionService::class);
        $res = $svc->extractTextForIntake($intake);

        $this->assertTrue((bool) ($res['meta']['ok'] ?? false), print_r($res['meta'] ?? [], true));

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($presignedUploadUrl) {
            if ($request->url() !== $presignedUploadUrl || $request->method() !== 'PUT') {
                return false;
            }
            $headers = $request->headers();
            $blobType = $headers['x-ms-blob-type'][0] ?? $headers['X-Ms-Blob-Type'][0] ?? null;

            return $blobType === 'BlockBlob';
        });
    }
}
