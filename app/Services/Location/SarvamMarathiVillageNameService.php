<?php

namespace App\Services\Location;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Batched Devanagari labels for Maharashtra villages via Sarvam chat (sarvam-m).
 */
class SarvamMarathiVillageNameService
{
    /**
     * @param  array<int, array{id:int, name:string, taluka:?string, district:?string}>  $places
     * @return array<int, string> village id => Marathi name (Devanagari)
     */
    public function translatePlaces(array $places): array
    {
        if ($places === []) {
            return [];
        }

        $key = trim((string) config('services.sarvam.subscription_key'));
        if ($key === '') {
            Log::warning('SarvamMarathiVillageNameService: missing services.sarvam.subscription_key');

            return [];
        }

        $url = (string) config('intake.sarvam_structured.chat_completions_url', 'https://api.sarvam.ai/v1/chat/completions');
        $model = 'sarvam-m';

        $payloadJson = json_encode(array_values($places), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $system = <<<'SYS'
You translate Maharashtra (India) village names into standard Marathi in Devanagari script.
Input is JSON array of objects: id (number), name (place name, may be English/Roman Marathi), taluka (optional), district (optional).
Output ONLY a single JSON object whose keys are string forms of each id and values are the correct Marathi village names (Devanagari only, no English).
Use authentic local spellings when known; disambiguate using taluka/district when provided.
Do not include explanations, markdown, or keys other than the ids from the input.
SYS;

        $user = "Places JSON:\n".$payloadJson;

        try {
            $response = Http::withHeaders([
                'api-subscription-key' => $key,
                'Content-Type' => 'application/json',
            ])->timeout(90)->post($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.05,
                'max_tokens' => 2500,
            ]);

            if (! $response->successful()) {
                Log::warning('SarvamMarathiVillageNameService: HTTP error', ['status' => $response->status()]);

                return [];
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? null;
            if (! is_string($content) || trim($content) === '') {
                return [];
            }

            return $this->parseIdMap($content, $places);
        } catch (\Throwable $e) {
            Log::warning('SarvamMarathiVillageNameService: request failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<int, array{id:int}>  $places
     * @return array<int, string>
     */
    private function parseIdMap(string $content, array $places): array
    {
        $content = trim($content);
        $content = preg_replace('/^```json\s*|\s*```$/i', '', $content) ?? $content;
        $content = trim((string) $content);

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return [];
        }

        $allowed = [];
        foreach ($places as $p) {
            $allowed[(int) ($p['id'] ?? 0)] = true;
        }

        $out = [];
        foreach ($decoded as $k => $v) {
            $id = is_int($k) ? $k : (int) (string) $k;
            if (! isset($allowed[$id]) || ! is_string($v)) {
                continue;
            }
            $trim = trim($v);
            if ($trim !== '') {
                $out[$id] = $trim;
            }
        }

        return $out;
    }
}
