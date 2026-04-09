<?php

namespace App\Services\Image\Providers;

use Illuminate\Support\Facades\Http;

class OpenAiModerationProvider
{
    /**
     * @return array{approved:bool,reason:?string,raw:array}
     */
    public function moderate(string $imagePath): array
    {
        $key = (string) config('services.openai.key');
        if (trim($key) === '') {
            return [
                'approved' => false,
                'reason' => 'OpenAI API key not configured.',
                'raw' => [],
            ];
        }
        if (! is_file($imagePath)) {
            return [
                'approved' => false,
                'reason' => 'Image not found for AI moderation.',
                'raw' => [],
            ];
        }

        $url = (string) config('services.openai.moderation_url', 'https://api.openai.com/v1/moderations');
        $model = (string) config('services.openai.moderation_model', 'omni-moderation-latest');

        $mime = $this->guessMime($imagePath);
        $dataUrl = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($imagePath));

        $payload = [
            'model' => $model,
            'input' => [[
                'type' => 'image_url',
                'image_url' => ['url' => $dataUrl],
            ]],
        ];

        $resp = Http::timeout(20)
            ->withToken($key)
            ->acceptJson()
            ->post($url, $payload);

        if (! $resp->ok()) {
            return [
                'approved' => false,
                'reason' => 'OpenAI moderation failed: HTTP '.$resp->status(),
                'raw' => ['body' => $resp->json()],
            ];
        }

        $json = $resp->json() ?? [];
        $flagged = (bool) (($json['results'][0]['flagged'] ?? false));
        $cats = $json['results'][0]['categories'] ?? [];
        $scores = $json['results'][0]['category_scores'] ?? [];

        return [
            'approved' => ! $flagged,
            'reason' => $flagged ? $this->buildReason($cats, $scores) : null,
            'raw' => is_array($json) ? $json : [],
        ];
    }

    private function guessMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    private function buildReason(mixed $categories, mixed $scores): string
    {
        if (! is_array($categories)) {
            return 'Flagged by AI moderation.';
        }
        $flaggedCats = [];
        foreach ($categories as $k => $v) {
            if ($v === true) {
                $flaggedCats[] = (string) $k;
            }
        }
        $flaggedCats = array_values(array_unique(array_filter($flaggedCats, static fn ($x) => $x !== '')));
        if ($flaggedCats === []) {
            return 'Flagged by AI moderation.';
        }

        $bits = [];
        foreach ($flaggedCats as $c) {
            $score = is_array($scores) && array_key_exists($c, $scores) ? (float) $scores[$c] : null;
            $bits[] = $score !== null ? ($c.' ('.number_format($score, 2).')') : $c;
        }

        return 'Flagged categories: '.implode(', ', $bits);
    }
}
