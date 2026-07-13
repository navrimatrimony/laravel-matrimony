<?php

namespace App\Services\Intake;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Benchmark-only PaddleOCR client (HTTP sidecar or CLI fallback).
 */
class OcrEnsembleBenchmarkPaddleOcrClient
{
    public const ENGINE = 'paddleocr_v1';

    public function isConfigured(): bool
    {
        return $this->sidecarUrl() !== '' || $this->cliRunnerPath() !== null;
    }

    public function healthCheck(): bool
    {
        $url = $this->sidecarUrl();
        if ($url === '') {
            return $this->cliRunnerPath() !== null;
        }

        try {
            $response = Http::timeout(5)->get(rtrim($url, '/').'/health');

            return $response->successful()
                && is_array($response->json())
                && ($response->json('status') === 'ok');
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * @return array{text: string, duration_ms: int|null, engine: string, engine_meta: array<string, mixed>|null}
     */
    public function extractFromImagePath(string $absoluteImagePath): array
    {
        if (! is_file($absoluteImagePath) || ! is_readable($absoluteImagePath)) {
            throw new RuntimeException("PaddleOCR input image not readable: {$absoluteImagePath}");
        }

        $url = $this->sidecarUrl();
        if ($url !== '') {
            return $this->extractViaHttp($url, $absoluteImagePath);
        }

        return $this->extractViaCli($absoluteImagePath);
    }

    /**
     * @return array{text: string, duration_ms: int|null, engine: string, engine_meta: array<string, mixed>|null}
     */
    private function extractViaHttp(string $baseUrl, string $absoluteImagePath): array
    {
        $timeout = max(5, (int) config('ocr.ensemble.phase2.benchmark.timeout_seconds', 120));

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post(rtrim($baseUrl, '/').'/ocr', [
                    'image_path' => $absoluteImagePath,
                    'preprocessing_version' => IntakeOcrEnsemblePhase1Service::PREPROCESSING_VERSION,
                    'language_hint' => 'devanagari',
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('PaddleOCR sidecar connection failed: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('PaddleOCR sidecar failed: HTTP '.$response->status().' '.$response->body());
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('PaddleOCR sidecar returned invalid JSON.');
        }

        return $this->normalizePayload($payload);
    }

    /**
     * @return array{text: string, duration_ms: int|null, engine: string, engine_meta: array<string, mixed>|null}
     */
    private function extractViaCli(string $absoluteImagePath): array
    {
        $runner = $this->cliRunnerPath();
        if ($runner === null) {
            throw new RuntimeException('PaddleOCR sidecar URL is empty and CLI runner was not found.');
        }

        $python = trim((string) config('ocr.ensemble.phase2.benchmark.python_binary', 'python3'));
        $timeout = max(5, (int) config('ocr.ensemble.phase2.benchmark.timeout_seconds', 120));
        $command = sprintf(
            '%s %s --image %s',
            escapeshellarg($python),
            escapeshellarg($runner),
            escapeshellarg($absoluteImagePath),
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, dirname($runner));
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start PaddleOCR CLI runner.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $started = microtime(true);

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (! $status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if ((microtime(true) - $started) > $timeout) {
                proc_terminate($process);
                throw new RuntimeException('PaddleOCR CLI runner timed out after '.$timeout.'s.');
            }
            usleep(100_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException('PaddleOCR CLI runner failed: '.trim($stderr !== '' ? $stderr : $stdout));
        }

        $payload = json_decode(trim($stdout), true);
        if (! is_array($payload)) {
            throw new RuntimeException('PaddleOCR CLI runner returned invalid JSON.');
        }

        return $this->normalizePayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{text: string, duration_ms: int|null, engine: string, engine_meta: array<string, mixed>|null}
     */
    private function normalizePayload(array $payload): array
    {
        return [
            'text' => trim((string) ($payload['text'] ?? '')),
            'duration_ms' => is_numeric($payload['duration_ms'] ?? null) ? (int) $payload['duration_ms'] : null,
            'engine' => trim((string) ($payload['engine'] ?? self::ENGINE)),
            'engine_meta' => is_array($payload['engine_meta'] ?? null) ? $payload['engine_meta'] : null,
        ];
    }

    private function sidecarUrl(): string
    {
        return rtrim(trim((string) config('ocr.ensemble.phase2.benchmark.sidecar_url', '')), '/');
    }

    private function cliRunnerPath(): ?string
    {
        $configured = trim((string) config('ocr.ensemble.phase2.benchmark.cli_runner', ''));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $default = base_path('tools/ocr-ensemble-paddle-sidecar/run_ocr.py');

        return is_file($default) ? $default : null;
    }
}
