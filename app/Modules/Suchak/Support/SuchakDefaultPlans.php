<?php

namespace App\Modules\Suchak\Support;

/**
 * The ready-made service plans every Suchak STARTS with — SEED CONTENT, not a
 * runtime authority.
 *
 * Demoted 2026-08-02. These constants used to be the plan itself: a Suchak could
 * not edit a ready-made plan because there was nothing to edit — no row, no
 * writer, only a `final` class with two hardcoded prices. The four fee columns a
 * preset entry read were therefore structurally always null.
 *
 * Now {@see seedRows()} is read ONCE, to create the Suchak's own
 * suchak_customer_plans row (lazily, on first read of their plans — see
 * SuchakCustomerPlanService::ensurePresetRows). From that moment the ROW is the
 * plan and it is editable like any other; the constants below only ever supply
 * the initial content and a last-resort fallback for a legacy row whose columns
 * were never filled in.
 *
 * `preset_key` stays on the seeded row: it is the row's identity, it is what
 * keeps the row undeletable, and it is what the send-time flow scopes a
 * customer's package by.
 *
 * Still read at send time by SuchakPaymentSetupApiController for the package
 * NAME and the stage/deliverable payload of a preset send ({@see catalogPayload})
 * and for the "fold in the Basic services" toggle of a custom send
 * ({@see deliverablesForStage}). Those are unchanged by the demotion.
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
     * The two presets expressed as suchak_customer_plans ROWS — the one seed
     * shape, read by the lazy seeder (SuchakCustomerPlanService::ensurePresetRows)
     * and by the backfill migration, so neither can drift from the other.
     *
     * Deliberately absent: `duration` and all four fee columns. A ready-made plan
     * fixes no duration and charges no meeting / post-marriage fee until a Suchak
     * says so, and NULL is how the app and the acceptance page read "ठरलेले नाही".
     * Inventing a figure here would put a charge on a card nobody agreed to.
     *
     * `sort_order` is the preset's natural code order, kept until an explicit
     * reorder moves it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function seedRows(): array
    {
        $rows = [];

        foreach (array_values(self::all()) as $index => $plan) {
            $rows[] = [
                'preset_key' => (string) $plan['key'],
                'name' => (string) $plan['name'],
                'name_mr' => $plan['name_mr'] ?? null,
                'price_amount' => number_format((float) $plan['price_amount'], 2, '.', ''),
                'currency' => strtoupper((string) $plan['currency']),
                'services_json' => array_map(static fn (array $deliverable): array => [
                    'name' => (string) $deliverable['name'],
                    'name_mr' => $deliverable['name_mr'] ?? null,
                ], $plan['deliverables'] ?? []),
                'sort_order' => $index,
            ];
        }

        return $rows;
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

    /**
     * A preset's deliverables re-expressed in catalog shape, bound to an
     * arbitrary $stageKey and starting at $startSort. Lets a custom package fold
     * a preset's services (e.g. Basic) into its OWN single stage, reusing the
     * same deliverable shape as {@see catalogPayload} without duplicating it.
     * Sort orders step by 10 so callers can append more deliverables after these.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function deliverablesForStage(string $key, string $stageKey, int $startSort = 10): array
    {
        $plan = self::find($key);
        if ($plan === null) {
            return [];
        }

        $deliverables = [];
        $sort = $startSort;
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

        return $deliverables;
    }
}
