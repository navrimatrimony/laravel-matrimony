<?php

namespace App\Modules\Suchak\Support;

/**
 * The ready-made service plans every Suchak gets without building one. They are
 * platform-defined presets (fixed name / price / services), so a Suchak can pick
 * one and collect payment immediately — no editing, no per-package admin review.
 *
 * One source of truth, reused by the payment-request options API (to show the
 * plan + services) and the prepare-setup flow (to instantiate the chosen plan).
 * A Suchak can still create a custom package later; that path keeps admin review.
 */
final class SuchakDefaultPlans
{
    public const KEY_BASIC = 'basic';
    public const KEY_PREMIUM = 'premium';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'key' => self::KEY_BASIC,
                'name' => 'Basic matchmaking',
                'name_mr' => 'बेसिक जुळवणी',
                'description' => 'Profile registration and a shortlist of suitable matches.',
                'description_mr' => 'प्रोफाइल नोंदणी आणि योग्य स्थळांची निवडसूची.',
                'price_amount' => '2000',
                'currency' => 'INR',
                'deliverables' => [
                    [
                        'name' => 'Profile registration',
                        'name_mr' => 'प्रोफाइल नोंदणी',
                        'description' => 'Candidate profile prepared and listed.',
                        'description_mr' => 'उमेदवाराचे प्रोफाइल तयार करून नोंदवले जाते.',
                    ],
                    [
                        'name' => 'Shortlist of matches',
                        'name_mr' => 'योग्य स्थळांची निवडसूची',
                        'description' => 'A curated shortlist of suitable matches.',
                        'description_mr' => 'योग्य स्थळांची निवडक यादी.',
                    ],
                    [
                        'name' => 'Basic coordination',
                        'name_mr' => 'प्राथमिक समन्वय',
                        'description' => 'Initial coordination between the two families.',
                        'description_mr' => 'दोन्ही कुटुंबांमध्ये प्राथमिक समन्वय.',
                    ],
                ],
            ],
            [
                'key' => self::KEY_PREMIUM,
                'name' => 'Premium matchmaking',
                'name_mr' => 'प्रीमियम जुळवणी',
                'description' => 'Personal matchmaking with meeting coordination and priority support.',
                'description_mr' => 'वैयक्तिक जुळवणी, भेटींचा समन्वय व प्राधान्य सेवा.',
                'price_amount' => '5000',
                'currency' => 'INR',
                'deliverables' => [
                    [
                        'name' => 'Everything in Basic',
                        'name_mr' => 'बेसिकमधील सर्व सेवा',
                        'description' => 'All Basic plan services included.',
                        'description_mr' => 'बेसिक योजनेतील सर्व सेवा समाविष्ट.',
                    ],
                    [
                        'name' => 'Personal matchmaking',
                        'name_mr' => 'वैयक्तिक जुळवणी',
                        'description' => 'Hand-picked matches with personal follow-up.',
                        'description_mr' => 'निवडक स्थळे आणि वैयक्तिक पाठपुरावा.',
                    ],
                    [
                        'name' => 'Meeting coordination',
                        'name_mr' => 'भेटींचा समन्वय',
                        'description' => 'Arranging and coordinating family meetings.',
                        'description_mr' => 'कुटुंबांच्या भेटी ठरवणे व समन्वय.',
                    ],
                    [
                        'name' => 'Priority support',
                        'name_mr' => 'प्राधान्य सेवा',
                        'description' => 'Faster responses and priority handling.',
                        'description_mr' => 'जलद प्रतिसाद व प्राधान्याने सेवा.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(?string $key): ?array
    {
        foreach (self::all() as $plan) {
            if ($plan['key'] === $key) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Stage/deliverable payloads for createCustomPackage(), built from a preset.
     *
     * @param  array<string, mixed>  $plan
     * @return array{stages: array<int, array<string, mixed>>, deliverables: array<int, array<string, mixed>>}
     */
    public static function catalogPayload(array $plan): array
    {
        $stageKey = 'plan_'.$plan['key'];
        $deliverables = [];
        $sort = 10;
        foreach ($plan['deliverables'] as $d) {
            $deliverables[] = [
                'stage_key' => $stageKey,
                'deliverable_key' => $stageKey.'_'.$sort,
                'deliverable_name' => $d['name'],
                'deliverable_name_mr' => $d['name_mr'] ?? null,
                'deliverable_description' => $d['description'] ?? '',
                'deliverable_description_mr' => $d['description_mr'] ?? null,
                'sort_order' => $sort,
            ];
            $sort += 10;
        }

        return [
            'stages' => [
                [
                    'stage_key' => $stageKey,
                    'stage_name' => $plan['name'],
                    'stage_description' => $plan['description'] ?? '',
                    'sort_order' => 10,
                    'expected_days' => 30,
                ],
            ],
            'deliverables' => $deliverables,
        ];
    }
}
