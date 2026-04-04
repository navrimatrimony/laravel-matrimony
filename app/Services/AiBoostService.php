<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Optional Sarvam (or compatible) chat completion for a 0–20 compatibility signal.
 * Cached per profile pair; failures return 0 so rule-based boost still applies.
 */
class AiBoostService
{
    private const CACHE_TTL_SECONDS = 604800;

    /**
     * Integer 0–20 for use inside {@see MatchBoostService} (capped by max_boost_limit there).
     */
    public function getBoostScore(MatrimonyProfile $profileA, MatrimonyProfile $profileB): int
    {
        $id1 = min($profileA->id, $profileB->id);
        $id2 = max($profileA->id, $profileB->id);
        $settings = \App\Models\MatchBoostSetting::current();
        $ver = (string) ($settings->updated_at?->timestamp ?? '0');

        $cacheKey = 'ai_match_boost:'.$id1.':'.$id2.':'.$ver.':'.($settings->use_ai ? '1' : '0').':'.(string) ($settings->ai_model ?? '');

        return (int) Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($profileA, $profileB, $settings): int {
            if (! $settings->use_ai) {
                return 0;
            }

            $provider = strtolower(trim((string) ($settings->ai_provider ?? '')));
            if ($provider !== '' && $provider !== 'sarvam') {
                return 0;
            }

            $key = (string) (config('services.sarvam.subscription_key') ?? '');
            if ($key === '') {
                return 0;
            }

            $payloadA = $this->profilePayload($profileA);
            $payloadB = $this->profilePayload($profileB);

            $userPrompt = "Profile A:\n".json_encode($payloadA, JSON_UNESCAPED_UNICODE)
                ."\n\nProfile B:\n".json_encode($payloadB, JSON_UNESCAPED_UNICODE)
                ."\n\nRate compatibility between these two profiles for matrimony from 0 to 20 only."
                .' Reply with a single integer 0-20 and nothing else.';

            $model = trim((string) ($settings->ai_model ?? ''));
            if ($model === '') {
                $model = (string) config('services.sarvam.chat_model', 'sarvam-105b');
            }

            $base = rtrim((string) config('services.sarvam.base_url', 'https://api.sarvam.ai'), '/');
            $url = $base.'/v1/chat/completions';

            try {
                $response = Http::timeout((int) config('services.sarvam.timeout', 20))
                    ->withHeaders([
                        'api-subscription-key' => $key,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($url, [
                        'model' => $model,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'You output only one integer from 0 to 20. No words, no punctuation.',
                            ],
                            [
                                'role' => 'user',
                                'content' => $userPrompt,
                            ],
                        ],
                    ]);

                if (! $response->successful()) {
                    Log::warning('ai_match_boost.sarvam_http', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return 0;
                }

                $content = (string) data_get($response->json(), 'choices.0.message.content', '');
                $n = $this->parseIntScore($content);

                return max(0, min(20, $n));
            } catch (\Throwable $e) {
                Log::warning('ai_match_boost.sarvam_exception', ['e' => $e->getMessage()]);

                return 0;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(MatrimonyProfile $p): array
    {
        $p->loadMissing(['profession', 'city', 'state', 'country']);

        $age = null;
        if ($p->date_of_birth) {
            try {
                $age = Carbon::parse($p->date_of_birth)->age;
            } catch (\Throwable) {
                $age = null;
            }
        }

        return [
            'age' => $age,
            'location' => trim(implode(', ', array_filter([
                $p->city?->name,
                $p->state?->name,
                $p->country?->name,
            ]))),
            'profession' => $p->profession?->name ?? $p->occupation_title,
            'education' => $p->highest_education,
            'interests' => trim(implode(' | ', array_filter([
                $p->occupation_title,
                $p->specialization,
                $p->work_location_text,
            ]))) ?: 'Not specified',
        ];
    }

    private function parseIntScore(string $raw): int
    {
        $raw = trim($raw);
        if (preg_match('/-?\d+/', $raw, $m)) {
            return (int) $m[0];
        }

        return 0;
    }
}
