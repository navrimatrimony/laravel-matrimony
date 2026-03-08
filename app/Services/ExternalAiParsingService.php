<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ExternalAiParsingService
 *
 * Thin wrapper around OpenAI (or compatible) chat completions API.
 * Responsible ONLY for:
 *  - Building a prompt from raw biodata text
 *  - Calling the API
 *  - Normalizing the response into SSOT structure (or returning null on failure)
 *
 * No DB writes. Safe to disable when OPENAI_API_KEY is not configured.
 */
class ExternalAiParsingService
{
    /**
     * Attempt to parse raw biodata text into SSOT structure via AI.
     *
     * @return array<string, mixed>|null SSOT array or null on any failure
     */
    public function parseToSsot(string $rawText): ?array
    {
        $key = config('services.openai.key');
        if ($key === null || $key === '') {
            return null;
        }

        $url = config('services.openai.url', 'https://api.openai.com/v1/chat/completions');
        $model = config('services.openai.model', 'gpt-4o-mini');

        // Truncate extremely long text to keep prompt reasonable.
        $maxChars = 8000;
        if (mb_strlen($rawText) > $maxChars) {
            $rawText = mb_substr($rawText, 0, $maxChars);
        }

        $system = 'You are a careful data extraction assistant for a Marathi/English marriage biodata system. '
            . 'Extract structured JSON in a fixed schema. Return ONLY valid JSON, no markdown, no explanations.';

        $user = [
            'role' => 'user',
            'content' => "Extract the following biodata into this JSON schema:\n\n"
                . "Schema (keys and types):\n"
                . "{\n"
                . "  \"core\": {\n"
                . "    \"full_name\": string|null,\n"
                . "    \"date_of_birth\": string|null (YYYY-MM-DD or null),\n"
                . "    \"gender\": string|null,\n"
                . "    \"religion\": string|null,\n"
                . "    \"caste\": string|null,\n"
                . "    \"sub_caste\": string|null,\n"
                . "    \"marital_status\": string|null,\n"
                . "    \"annual_income\": integer|null,\n"
                . "    \"family_income\": integer|null,\n"
                . "    \"primary_contact_number\": string|null,\n"
                . "    \"serious_intent_id\": integer|null\n"
                . "  },\n"
                . "  \"contacts\": [],\n"
                . "  \"children\": [],\n"
                . "  \"education_history\": [],\n"
                . "  \"career_history\": [],\n"
                . "  \"addresses\": [],\n"
                . "  \"property_summary\": [],\n"
                . "  \"property_assets\": [],\n"
                . "  \"horoscope\": [],\n"
                . "  \"preferences\": [],\n"
                . "  \"extended_narrative\": [],\n"
                . "  \"confidence_map\": { \"field_name\": number between 0 and 1 }\n"
                . "}\n\n"
                . "Rules:\n"
                . "- If you are not sure about a field, set it to null and confidence 0.0.\n"
                . "- Never hallucinate contact numbers or incomes: only use exact numbers present.\n"
                . "- For Marathi dates like 12/03/1996, normalize to YYYY-MM-DD.\n"
                . "- Return ONLY JSON, no backticks, no markdown.\n\n"
                . "Biodata text:\n\n"
                . $rawText,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ])->timeout(40)->post($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    $user,
                ],
                'temperature' => 0.1,
                'max_tokens' => 1200,
            ]);

            if (! $response->successful()) {
                Log::warning('ExternalAiParsingService: AI API non-2xx.', ['status' => $response->status()]);
                return null;
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? null;
            if (! is_string($content) || $content === '') {
                return null;
            }

            // Strip accidental markdown fences if any
            $content = trim($content);
            $content = preg_replace('/^```json\s*|\s*```$/i', '', $content);

            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return null;
            }

            // Ensure required top-level keys exist; fill empties if missing.
            $requiredKeys = [
                'core',
                'contacts',
                'children',
                'education_history',
                'career_history',
                'addresses',
                'property_summary',
                'property_assets',
                'horoscope',
                'preferences',
                'extended_narrative',
                'confidence_map',
            ];

            foreach ($requiredKeys as $key) {
                if (! array_key_exists($key, $decoded)) {
                    if ($key === 'core') {
                        $decoded['core'] = [];
                    } elseif ($key === 'confidence_map') {
                        $decoded['confidence_map'] = [];
                    } else {
                        $decoded[$key] = [];
                    }
                }
            }

            if (! is_array($decoded['core'])) {
                $decoded['core'] = [];
            }
            if (! is_array($decoded['confidence_map'])) {
                $decoded['confidence_map'] = [];
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::warning('ExternalAiParsingService: AI request failed.', ['message' => $e->getMessage()]);
            return null;
        }
    }
}

