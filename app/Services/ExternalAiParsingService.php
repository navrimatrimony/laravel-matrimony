<?php

namespace App\Services;

use App\Services\Parsing\IntakeParsedSnapshotSkeleton;
use App\Services\Parsing\ProviderResolver;
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
        private ProviderResolver $providerResolver,
    ) {}

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
            .'Extract structured JSON in a fixed schema. Return ONLY valid JSON, no markdown, no explanations.';

        $user = [
            'role' => 'user',
            'content' => "Extract the following biodata into this JSON schema:\n\n"
                ."Schema (keys and types):\n"
                ."{\n"
                ."  \"core\": {\n"
                ."    \"full_name\": string|null,\n"
                ."    \"date_of_birth\": string|null (YYYY-MM-DD or null),\n"
                ."    \"gender\": string|null,\n"
                ."    \"religion\": string|null,\n"
                ."    \"caste\": string|null,\n"
                ."    \"sub_caste\": string|null,\n"
                ."    \"marital_status\": string|null,\n"
                ."    \"annual_income\": integer|null,\n"
                ."    \"family_income\": integer|null,\n"
                ."    \"primary_contact_number\": string|null,\n"
                ."    \"serious_intent_id\": integer|null\n"
                ."  },\n"
                ."  \"contacts\": [],\n"
                ."  \"children\": [],\n"
                ."  \"education_history\": [],\n"
                ."  \"career_history\": [],\n"
                ."  \"addresses\": [],\n"
                ."  \"property_summary\": [],\n"
                ."  \"property_assets\": [],\n"
                ."  \"horoscope\": [],\n"
                ."  \"preferences\": [],\n"
                ."  \"extended_narrative\": [],\n"
                ."  \"confidence_map\": { \"field_name\": number between 0 and 1 }\n"
                ."}\n\n"
                ."Rules:\n"
                ."- If you are not sure about a field, set it to null and confidence 0.0.\n"
                ."- Never hallucinate contact numbers or incomes: only use exact numbers present.\n"
                ."- For Marathi dates like 12/03/1996, normalize to YYYY-MM-DD.\n"
                ."- Return ONLY JSON, no backticks, no markdown.\n\n"
                ."Biodata text:\n\n"
                .$rawText,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$key,
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
     * Structured provider (OpenAI vs Sarvam chat) follows ProviderResolver.
     *
     * @return array<string, mixed>|null SSOT array or null on failure
     */
    public function parseToSsotV2(string $rawText): ?array
    {
        $provider = strtolower(trim($this->providerResolver->structuredParserProvider()));

        return $this->parseToSsotV2WithProvider($rawText, $provider);
    }

    /**
     * @param  string  $provider  openai|sarvam
     * @return array<string, mixed>|null
     */
    public function parseToSsotV2WithProvider(string $rawText, string $provider): ?array
    {
        $provider = strtolower(trim($provider));
        $maxChars = (int) config('intake.ai_first_v2.max_chars', 12000);
        if (mb_strlen($rawText) > $maxChars) {
            $rawText = $this->clipRawTextForStructuredExtraction($rawText, $maxChars);
        }

        if ($provider === ProviderResolver::PROVIDER_SARVAM) {
            return $this->parseToSsotV2ViaSarvam($rawText);
        }

        return $this->parseToSsotV2ViaOpenAi($rawText);
    }

    private function v2SystemPrompt(): string
    {
        return 'You are an expert data extraction assistant for a Marathi/Hindi/English marriage biodata (विवाह परिचय पत्र) system. '
            .'The text is often in Devanagari (मराठी) or mixed. Extract structured JSON in the EXACT schema given. '
            .'Recognise common Marathi labels: नाव, उंची, वर्ण/रंग, रक्त गट, जन्मतारीख, जात, उपजात, धर्म, लग्नस्थिती, '
            .'नाडी (आध्य/मध्य/अंत्य), गण (देव/मनुष्य/राक्षस), चरण, रास/राशी, नक्षत्र, देवक, कुलदैवत, गोत्र, '
            .'वडील, आई, भाऊ, बहीण, दाजी (sister\'s husband), मामा, आजोळ (maternal), पत्ता, नोकरी, शिक्षण. '
            .'IMPORTANT PIPELINE: first create canonical_biodata_draft from the raw text, then fill SSOT JSON from that draft. '
            .'The draft must act like a per-biodata intermediate sheet aligned to our form/database sections, so missing or wrong data can be debugged before profile autofill. '
            .'CRITICAL: Use JSON null for unknown/missing fields. Never output the word "null" as a string. '
            .'Never copy section titles or bare labels (e.g. "शिक्षण", "रास", "नाव") as if they were field values. '
            .'For controlled-option style fields (religion/caste/sub_caste/marital_status/complexion/blood_group etc), extract raw labels conservatively; do not force dropdown decisions. '
            .'If a line combines community tokens (religion + caste + sub_caste), split only when explicit and unambiguous; otherwise keep conservative raw values. '
            .'For person names in siblings/relatives/parents, preserve honorifics/prefixes exactly when present (e.g. श्री., सौ., डॉ.); do not strip them. '
            .'If multiple relatives share the same relation (e.g. multiple मामा/चुलते/आत्या), keep each person as a separate row; never collapse into one. '
            .'Copy names, numbers, dates, and places exactly as written—do not "fix" spellings or invent missing data. '
            .'Return ONLY valid JSON. No markdown, no code fences, no explanations.';
    }

    private function v2UserPrompt(string $rawText): string
    {
        return 'Extract the following biodata into a compact JSON payload. '
            .'Before final SSOT fields, build canonical_biodata_draft as an intermediate normalized biodata sheet matching our form/database sections. '
            .'Include only values that are present in the biodata text; omit or null unknown values. '
            .'Rules: (1) Marathi dates like १२/०३/१९९६ or 12/03/1996 → YYYY-MM-DD. '
            .'(2) Height in feet/inch (फूट/इंच) → convert to height_cm (1 ft = 30.48 cm, 1 inch = 2.54 cm) and keep original height string in core.height when present. '
            .'(3) Blood group: accept B+, B+ve, B positive → B+; similar for A, AB, O. '
            .'(4) नाडी आध्य/आद्य → nadi=adi; मध्य → madhyam; अंत्य → anty. '
            .'(5) गण देव/मनुष्य/राक्षस → gan=deva/manushya/rakshasa. '
            ."(6) दाजी = sister's husband: put in siblings[].spouse for the matching sister. "
            .'(7) If unsure, use JSON null (not the string "null") and confidence 0.0. Never invent phone numbers or income. '
            .'(8) Education: put recognised qualifications in core.highest_education (canonical code or short text; comma-separated if multiple). Use core.highest_education_other only when needed for extra free-text. Do not populate education_history unless the text has separate education rows. '
            .'(9) Horoscope: rashi/nakshatra/devak/kuldaivat/gotra must be only the actual value tokens, not labels like "रास" or "नक्षत्र" alone. '
            .'(10) Strip decorative symbols from caste/jāt strings (e.g. stray % from tables) but keep the caste text itself. '
            .'(11) Do not output guessed *_id values; extract raw textual labels only and keep them conservative when ambiguous. '
            .'(12) Preserve honorifics like श्री./सौ./डॉ. in person names when present. '
            .'(13) For repeated same-relation relatives, output multiple array entries (do not merge). '
            .'(14) Contacts: separate candidate primary, father, mother, sibling, and other contact numbers when labels make that clear; do not duplicate the same phone row across unrelated buckets. '
            .'(15) Return ONLY JSON, no backticks.\n\n'
            .'Output schema guidance:\n'
            .'{\n'
            .'  "canonical_biodata_draft": {\n'
            .'    "identity": {"full_name": string|null, "gender": string|null, "date_of_birth": string|null, "birth_time": string|null, "birth_place_text": string|null},\n'
            .'    "community": {"religion": string|null, "caste": string|null, "sub_caste": string|null, "marital_status": string|null},\n'
            .'    "physical": {"height": string|null, "height_cm": number|null, "weight_kg": number|null, "complexion": string|null, "blood_group": string|null},\n'
            .'    "education_career": {"highest_education": string|null, "occupation_title": string|null, "company_name": string|null, "work_location_text": string|null, "annual_income": number|null},\n'
            .'    "family": {"father_name": string|null, "father_occupation": string|null, "mother_name": string|null, "mother_occupation": string|null, "brother_count": number|null, "sister_count": number|null},\n'
            .'    "contacts": [], "addresses": [], "siblings": [], "relatives": [], "horoscope": [], "property": [], "preferences": [],\n'
            .'    "field_sources": {"core.full_name": "exact source line or short evidence"},\n'
            .'    "missing_expected_fields": ["field names that were not found but are commonly expected"]\n'
            .'  },\n'
            .'  "core": {}, "contacts": [], "children": [], "marriages": [], "siblings": [], "relatives": [], "career_history": [], "addresses": [], "property_summary": [], "property_assets": [], "horoscope": [], "preferences": [], "extended_narrative": {}, "confidence_map": {},\n'
            .'  "extraction_diagnostics": {"used_intermediate_draft": true, "notes": []}\n'
            .'}\n\n'
            .'Top-level SSOT keys you MAY use: canonical_biodata_draft, core, contacts, children, marriages, siblings, relatives, career_history, addresses, property_summary, property_assets, horoscope, preferences, extended_narrative, confidence_map, extraction_diagnostics.\n\n'
            .'Biodata text:\n\n'.$rawText;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseToSsotV2ViaOpenAi(string $rawText): ?array
    {
        $key = config('services.openai.key');
        if ($key === null || $key === '') {
            return null;
        }

        $url = config('services.openai.url', 'https://api.openai.com/v1/chat/completions');
        $model = config('services.openai.model', 'gpt-4o-mini');
        $v2Model = config('intake.ai_first_v2.model');
        if ($v2Model !== null && $v2Model !== '') {
            $model = $v2Model;
        }

        $system = $this->v2SystemPrompt();
        $user = $this->v2UserPrompt($rawText);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$key,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.1,
                'max_tokens' => (int) config('intake.ai_first_v2.max_tokens', 4000),
            ]);

            if (! $response->successful()) {
                Log::warning('ExternalAiParsingService: AI v2 API non-2xx.', ['status' => $response->status()]);

                return null;
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? null;

            return $this->decodeAndEnsureV2Ssot($content);
        } catch (\Throwable $e) {
            Log::warning('ExternalAiParsingService: AI v2 request failed.', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseToSsotV2ViaSarvam(string $rawText): ?array
    {
        $key = trim((string) config('services.sarvam.subscription_key'));
        if ($key === '') {
            Log::warning('ExternalAiParsingService: Sarvam subscription key missing for structured parse.');

            return null;
        }

        $url = (string) config('intake.sarvam_structured.chat_completions_url', 'https://api.sarvam.ai/v1/chat/completions');
        $configuredModel = (string) config('intake.sarvam_structured.model', 'sarvam-m');
        $model = (string) $configuredModel;
        // Defensive runtime guard (should never trigger when config is correct).
        if (strtolower(trim($model)) !== 'sarvam-m') {
            Log::error('Invalid Sarvam structured model configured: '.$model.'. Expected: sarvam-m', [
                'configured_model' => $configuredModel,
            ]);
            $model = 'sarvam-m';
        }

        $system = $this->v2SystemPrompt();
        $user = $this->v2UserPrompt($rawText);

        try {
            $response = Http::withHeaders([
                'api-subscription-key' => $key,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($url, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.1,
                'max_tokens' => (int) config('intake.sarvam_structured.max_tokens', config('intake.ai_first_v2.max_tokens', 4000)),
            ]);

            if (! $response->successful()) {
                Log::warning('ExternalAiParsingService: Sarvam v2 API non-2xx.', ['status' => $response->status()]);

                return null;
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? null;

            return $this->decodeAndEnsureV2Ssot($content);
        } catch (\Throwable $e) {
            Log::warning('ExternalAiParsingService: Sarvam v2 request failed.', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeAndEnsureV2Ssot(mixed $content): ?array
    {
        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        $content = trim($content);
        $content = preg_replace('/^```json\s*|\s*```$/i', '', $content) ?? $content;

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return null;
        }

        $requiredKeys = [
            'canonical_biodata_draft', 'core', 'contacts', 'children', 'education_history', 'career_history',
            'addresses', 'siblings', 'relatives', 'property_summary', 'property_assets',
            'horoscope', 'preferences', 'extended_narrative', 'confidence_map', 'extraction_diagnostics',
        ];
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $decoded)) {
                if ($key === 'core' || $key === 'confidence_map' || $key === 'extraction_diagnostics') {
                    $decoded[$key] = [];
                } elseif ($key === 'extended_narrative') {
                    $decoded['extended_narrative'] = ['narrative_about_me' => null, 'narrative_expectations' => null, 'additional_notes' => null];
                } elseif ($key === 'canonical_biodata_draft') {
                    $decoded['canonical_biodata_draft'] = $this->defaultCanonicalBiodataDraft();
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
        if (! is_array($decoded['canonical_biodata_draft'])) {
            $decoded['canonical_biodata_draft'] = $this->defaultCanonicalBiodataDraft();
        }
        if (! is_array($decoded['extraction_diagnostics'])) {
            $decoded['extraction_diagnostics'] = [];
        }
        $decoded['canonical_biodata_draft'] = $this->normalizeCanonicalBiodataDraft($decoded['canonical_biodata_draft'], $decoded);
        $decoded['extraction_diagnostics'] = array_replace([
            'used_intermediate_draft' => true,
            'notes' => [],
        ], $decoded['extraction_diagnostics']);

        return $this->skeleton->ensure($decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultCanonicalBiodataDraft(): array
    {
        return [
            'identity' => [
                'full_name' => null,
                'gender' => null,
                'date_of_birth' => null,
                'birth_time' => null,
                'birth_place_text' => null,
            ],
            'community' => [
                'religion' => null,
                'caste' => null,
                'sub_caste' => null,
                'marital_status' => null,
            ],
            'physical' => [
                'height' => null,
                'height_cm' => null,
                'weight_kg' => null,
                'complexion' => null,
                'blood_group' => null,
            ],
            'education_career' => [
                'highest_education' => null,
                'occupation_title' => null,
                'company_name' => null,
                'work_location_text' => null,
                'annual_income' => null,
            ],
            'family' => [
                'father_name' => null,
                'father_occupation' => null,
                'mother_name' => null,
                'mother_occupation' => null,
                'brother_count' => null,
                'sister_count' => null,
            ],
            'contacts' => [],
            'addresses' => [],
            'siblings' => [],
            'relatives' => [],
            'horoscope' => [],
            'property' => [],
            'preferences' => [],
            'field_sources' => [],
            'missing_expected_fields' => [],
        ];
    }

    /**
     * Keep AI's intermediate draft, but make it predictable and backfill obvious buckets from SSOT output.
     * This does not infer new data; it only mirrors values already extracted into parsed_json.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function normalizeCanonicalBiodataDraft(array $draft, array $decoded): array
    {
        $out = array_replace_recursive($this->defaultCanonicalBiodataDraft(), $draft);
        $core = is_array($decoded['core'] ?? null) ? $decoded['core'] : [];

        $out['identity'] = $this->normalizeDraftSection($out['identity'] ?? [], [
            'full_name' => $core['full_name'] ?? null,
            'gender' => $core['gender'] ?? null,
            'date_of_birth' => $core['date_of_birth'] ?? null,
            'birth_time' => $core['birth_time'] ?? null,
            'birth_place_text' => $core['birth_place_text'] ?? $core['birth_place'] ?? null,
        ]);
        $out['community'] = $this->normalizeDraftSection($out['community'] ?? [], [
            'religion' => $core['religion'] ?? null,
            'caste' => $core['caste'] ?? null,
            'sub_caste' => $core['sub_caste'] ?? null,
            'marital_status' => $core['marital_status'] ?? null,
        ]);
        $out['physical'] = $this->normalizeDraftSection($out['physical'] ?? [], [
            'height' => $core['height'] ?? null,
            'height_cm' => $core['height_cm'] ?? null,
            'weight_kg' => $core['weight_kg'] ?? null,
            'complexion' => $core['complexion'] ?? null,
            'blood_group' => $core['blood_group'] ?? null,
        ]);
        $out['education_career'] = $this->normalizeDraftSection($out['education_career'] ?? [], [
            'highest_education' => $core['highest_education'] ?? null,
            'occupation_title' => $core['occupation_title'] ?? null,
            'company_name' => $core['company_name'] ?? null,
            'work_location_text' => $core['work_location_text'] ?? null,
            'annual_income' => $core['annual_income'] ?? null,
        ]);
        $out['family'] = $this->normalizeDraftSection($out['family'] ?? [], [
            'father_name' => $core['father_name'] ?? null,
            'father_occupation' => $core['father_occupation'] ?? null,
            'mother_name' => $core['mother_name'] ?? null,
            'mother_occupation' => $core['mother_occupation'] ?? null,
            'brother_count' => $core['brother_count'] ?? null,
            'sister_count' => $core['sister_count'] ?? null,
        ]);

        foreach (['contacts', 'addresses', 'siblings', 'relatives', 'horoscope', 'preferences'] as $section) {
            if (! is_array($out[$section] ?? null) || $out[$section] === []) {
                $out[$section] = is_array($decoded[$section] ?? null) ? $decoded[$section] : [];
            }
        }
        if (! is_array($out['property'] ?? null) || $out['property'] === []) {
            $out['property'] = is_array($decoded['property_assets'] ?? null) ? $decoded['property_assets'] : [];
        }
        if (! is_array($out['field_sources'] ?? null)) {
            $out['field_sources'] = [];
        }
        if (! is_array($out['missing_expected_fields'] ?? null)) {
            $out['missing_expected_fields'] = [];
        }

        return $out;
    }

    /**
     * @param  mixed  $section
     * @param  array<string, mixed>  $fallbacks
     * @return array<string, mixed>
     */
    private function normalizeDraftSection(mixed $section, array $fallbacks): array
    {
        $out = is_array($section) ? $section : [];
        foreach ($fallbacks as $key => $value) {
            if (! array_key_exists($key, $out) || $out[$key] === null || $out[$key] === '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * When raw text exceeds the model prompt budget, choose a window that keeps as much Devanagari
     * (typical Marathi biodata) as possible; naive prefix truncation can leave junk OCR/English blocks
     * and drop the real document body.
     */
    private function clipRawTextForStructuredExtraction(string $rawText, int $maxChars): string
    {
        $maxChars = max(500, $maxChars);
        $len = mb_strlen($rawText);
        if ($len <= $maxChars) {
            return $rawText;
        }

        $span = $len - $maxChars;
        $step = max(500, (int) ceil($span / 40));
        $bestStart = 0;
        $bestScore = -1;

        $considerStart = function (int $start) use ($rawText, $maxChars, &$bestScore, &$bestStart): void {
            if ($start < 0) {
                return;
            }
            $chunk = mb_substr($rawText, $start, $maxChars);
            preg_match_all('/[ऀ-ॿ]/u', $chunk, $m);
            $score = count($m[0] ?? []);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestStart = $start;
            }
        };

        for ($start = 0; $start <= $span; $start += $step) {
            $considerStart($start);
        }
        // Coarse steps can skip the final window (where the real body often sits after long OCR noise).
        if ($span > 0 && ($span % $step) !== 0) {
            $considerStart($span);
        }

        $prefix = mb_substr($rawText, 0, $maxChars);
        preg_match_all('/[ऀ-ॿ]/u', $prefix, $pm);
        $prefixScore = count($pm[0] ?? []);
        if ($prefixScore >= $bestScore) {
            return $prefix;
        }

        return mb_substr($rawText, $bestStart, $maxChars);
    }
}
