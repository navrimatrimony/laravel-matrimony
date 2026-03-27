<?php

namespace App\Services;

use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
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
    public function __construct(
        private IntakeParsedSnapshotSkeleton $skeleton,
    ) {
    }

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

            return $this->skeleton->ensure($decoded);
        } catch (\Throwable $e) {
            Log::warning('ExternalAiParsingService: AI request failed.', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse raw biodata text using a Marathi-aware prompt and strict schema (v2).
     * Use when admin selects "AI first (SSOT v2)". Same return shape as parseToSsot() for compatibility.
     *
     * @return array<string, mixed>|null SSOT array or null on failure
     */
    public function parseToSsotV2(string $rawText): ?array
    {
        $key = config('services.openai.key');
        if ($key === null || $key === '') {
            return null;
        }

        $url = config('services.openai.url', 'https://api.openai.com/v1/chat/completions');
        $model = config('services.openai.model', 'gpt-4o-mini');
        $maxChars = (int) config('intake.ai_first_v2.max_chars', 12000);
        $v2Model = config('intake.ai_first_v2.model');
        if ($v2Model !== null && $v2Model !== '') {
            $model = $v2Model;
        }

        if (mb_strlen($rawText) > $maxChars) {
            $rawText = mb_substr($rawText, 0, $maxChars);
        }

        $system = 'You are an expert data extraction assistant for a Marathi/Hindi/English marriage biodata (विवाह परिचय पत्र) system. '
            . 'The text is often in Devanagari (मराठी) or mixed. Extract structured JSON in the EXACT schema given. '
            . 'Recognise common Marathi labels: नाव, उंची, वर्ण/रंग, रक्त गट, जन्मतारीख, जात, उपजात, धर्म, लग्नस्थिती, '
            . 'नाडी (आध्य/मध्य/अंत्य), गण (देव/मनुष्य/राक्षस), चरण, रास/राशी, नक्षत्र, देवक, कुलदैवत, गोत्र, '
            . 'वडील, आई, भाऊ, बहीण, दाजी (sister\'s husband), मामा, आजोळ (maternal), पत्ता, नोकरी, शिक्षण. '
            . 'CRITICAL: Use JSON null for unknown/missing fields. Never output the word "null" as a string. '
            . 'Never copy section titles or bare labels (e.g. "शिक्षण", "रास", "नाव") as if they were field values. '
            . 'For controlled-option style fields (religion/caste/sub_caste/marital_status/complexion/blood_group etc), extract raw labels conservatively; do not force dropdown decisions. '
            . 'If a line combines community tokens (religion + caste + sub_caste), split only when explicit and unambiguous; otherwise keep conservative raw values. '
            . 'For person names in siblings/relatives/parents, preserve honorifics/prefixes exactly when present (e.g. श्री., सौ., डॉ.); do not strip them. '
            . 'If multiple relatives share the same relation (e.g. multiple मामा/चुलते/आत्या), keep each person as a separate row; never collapse into one. '
            . 'Copy names, numbers, dates, and places exactly as written—do not "fix" spellings or invent missing data. '
            . 'Return ONLY valid JSON. No markdown, no code fences, no explanations.';

        // Keep AI output compact: include ONLY keys you found; omit null/unknown fields.
        // Backend assembles full canonical skeleton via IntakeParsedSnapshotSkeleton.
        $user = 'Extract the following biodata into a compact JSON payload. '
            . 'Include only keys you actually found; omit unknown keys instead of filling nulls. '
            . "Rules: (1) Marathi dates like १२/०३/१९९६ or 12/03/1996 → YYYY-MM-DD. "
            . "(2) Height in feet/inch (फूट/इंच) → convert to height_cm (1 ft = 30.48 cm, 1 inch = 2.54 cm). "
            . "(3) Blood group: accept B+, B+ve, B positive → B+; similar for A, AB, O. "
            . "(4) नाडी आध्य/आद्य → nadi=adi; मध्य → madhyam; अंत्य → anty. "
            . "(5) गण देव/मनुष्य/राक्षस → gan=deva/manushya/rakshasa. "
            . "(6) दाजी = sister\'s husband: put in siblings[].spouse for the matching sister. "
            . "(7) If unsure, use JSON null (not the string \"null\") and confidence 0.0. Never invent phone numbers or income. "
            . "(8) Education: degree = course/qualification; specialization = branch/stream if explicitly separate; institution = college/school name only—do not put the full शिक्षण line into one field if distinct parts exist. "
            . "(9) Horoscope: rashi/nakshatra/devak/kuldaivat/gotra must be only the actual value tokens, not labels like \"रास\" or \"नक्षत्र\" alone. "
            . "(10) Strip decorative symbols from caste/jāt strings (e.g. stray % from tables) but keep the caste text itself. "
            . "(11) Do not output guessed *_id values; extract raw textual labels only and keep them conservative when ambiguous. "
            . "(12) Preserve honorifics like श्री./सौ./डॉ. in person names when present. "
            . "(13) For repeated same-relation relatives, output multiple array entries (do not merge). "
            . "(14) Return ONLY JSON, no backticks.\n\n"
            . "Top-level keys you MAY use (only include those you found): core, contacts, children, marriages, siblings, relatives, education_history, career_history, addresses, property_summary, property_assets, horoscope, preferences, extended_narrative, confidence_map.\n\n"
            . "Biodata text:\n\n" . $rawText;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.1,
                'max_tokens' => 2500,
            ]);

            if (! $response->successful()) {
                Log::warning('ExternalAiParsingService: AI v2 API non-2xx.', ['status' => $response->status()]);
                return null;
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? null;
            if (! is_string($content) || $content === '') {
                return null;
            }

            $content = trim($content);
            $content = preg_replace('/^```json\s*|\s*```$/i', '', $content);

            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return null;
            }

            $requiredKeys = [
                'core', 'contacts', 'children', 'education_history', 'career_history',
                'addresses', 'siblings', 'relatives', 'property_summary', 'property_assets',
                'horoscope', 'preferences', 'extended_narrative', 'confidence_map',
            ];
            foreach ($requiredKeys as $key) {
                if (! array_key_exists($key, $decoded)) {
                    if ($key === 'core') {
                        $decoded['core'] = [];
                    } elseif ($key === 'confidence_map') {
                        $decoded['confidence_map'] = [];
                    } elseif ($key === 'extended_narrative') {
                        $decoded['extended_narrative'] = ['narrative_about_me' => null, 'narrative_expectations' => null, 'additional_notes' => null];
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

            return $this->skeleton->ensure($decoded);
        } catch (\Throwable $e) {
            Log::warning('ExternalAiParsingService: AI v2 request failed.', ['message' => $e->getMessage()]);
            return null;
        }
    }
}

