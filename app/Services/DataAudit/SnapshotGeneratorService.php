<?php

namespace App\Services\DataAudit;

use App\Http\Controllers\Api\MatrimonyProfileApiController;
use App\Http\Controllers\MatrimonyProfileController;
use App\Http\Controllers\ProfileWizardController;
use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;
use App\Services\Governance\RecursiveRenderedFieldExtractor;
use App\Services\Governance\Repeaters\RepeaterDiffEngine;
use App\Services\Governance\Repeaters\RepeaterExplainabilityService;
use App\Services\Governance\Repeaters\RepeaterRowMatcher;
use App\Services\Governance\Repeaters\RepeaterSnapshotBuilder;
use Illuminate\Support\Facades\Log;

class SnapshotGeneratorService
{
    public function __construct(
        private readonly RenderedFieldExtractor $extractor,
        private readonly RecursiveRenderedFieldExtractor $recursiveExtractor
    ) {}

    /**
     * @param  array{api: bool, public_profile: bool, wizard: bool}  $sources
     * @return array<string, mixed>
     */
    public function captureProfileSnapshot(MatrimonyProfile $profile, array $sources): array
    {
        $startedAt = microtime(true);
        $memoryStart = memory_get_usage(true);

        $db = $this->captureDb($profile);
        $repeaters = $this->captureRepeaters($profile);
        $api = $sources['api'] ? $this->captureApi($profile) : [];
        $rendered = $this->captureRendered($profile, $sources, $db, $repeaters, $api);
        $renderStatus = $this->renderCaptureStatus($rendered, $sources, $db);
        $repeaterGovernance = $this->buildRepeaterGovernancePayload($repeaters, $rendered, $sources);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $memoryPeakKb = (int) round((memory_get_peak_usage(true) - $memoryStart) / 1024);

        return [
            'snapshot_version' => '2',
            'schema_version' => 'matrimony_profile_v2',
            'profile_id' => $profile->id,
            'entity_type' => 'matrimony_profile',
            'entity_id' => $profile->id,
            'render_capture_status' => $renderStatus['status'],
            'render_capture_completed' => $renderStatus['completed'],
            'comparison_eligible' => $renderStatus['eligible'],
            'extraction_quality_score' => $renderStatus['quality_score'],
            'capture_sources_present' => $renderStatus['sources_present'],
            'captured_at' => now()->toIso8601String(),
            'sources' => [
                'db' => true,
                'api' => $sources['api'],
                'rendered' => $sources['public_profile'] || $sources['wizard'],
            ],
            'db' => $db,
            'repeaters' => $repeaters,
            'api' => $api,
            'rendered' => $rendered,
            'repeater_governance' => $repeaterGovernance,
            'metrics' => [
                'capture_duration_ms' => $durationMs,
                'memory_peak_kb' => max(0, $memoryPeakKb),
                'rendered_pages_count' => count(array_filter([
                    $sources['public_profile'],
                    $sources['wizard'],
                ])),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $rendered
     * @param  array<string,mixed>  $db
     * @param  array{api: bool, public_profile: bool, wizard: bool}  $sources
     * @return array{status: string, completed: bool, eligible: bool, quality_score: int, sources_present: array<string,bool>}
     */
    private function renderCaptureStatus(array $rendered, array $sources, array $db): array
    {
        $pages = is_array($rendered['pages'] ?? null) ? $rendered['pages'] : [];
        $fields = is_array($rendered['fields'] ?? null) ? $rendered['fields'] : [];
        $required = [];
        if ($sources['wizard']) {
            $required[] = 'wizard';
        }
        if ($sources['public_profile']) {
            $required[] = 'public_profile';
        }
        $sourcesPresent = [
            'wizard' => isset($pages['wizard']) && is_array($pages['wizard']),
            'public_profile' => isset($pages['public_profile']) && is_array($pages['public_profile']),
            'api' => $sources['api'],
        ];

        $completed = true;
        foreach ($required as $src) {
            if (! ($sourcesPresent[$src] ?? false)) {
                $completed = false;
            }
        }
        $dbKeys = count($db);
        $nonEmptyRendered = 0;
        foreach ($fields as $sourceRows) {
            if (is_array($sourceRows)) {
                foreach ($sourceRows as $row) {
                    if (is_array($row) && ! empty($row['raw_rendered'])) {
                        $nonEmptyRendered++;
                    } elseif (is_scalar($row) && trim((string) $row) !== '') {
                        $nonEmptyRendered++;
                    }
                }
            } elseif (is_scalar($sourceRows) && trim((string) $sourceRows) !== '') {
                $nonEmptyRendered++;
            }
        }
        $quality = $dbKeys > 0 ? (int) max(0, min(100, round(($nonEmptyRendered / $dbKeys) * 100))) : 0;
        $status = $completed ? 'complete' : 'failed';
        $eligible = $completed && $quality >= 15;

        return [
            'status' => $status,
            'completed' => $completed,
            'eligible' => $eligible,
            'quality_score' => $quality,
            'sources_present' => $sourcesPresent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureDb(MatrimonyProfile $profile): array
    {
        return [
            'full_name' => $profile->full_name,
            'gender' => $profile->gender_id,
            'date_of_birth' => optional($profile->date_of_birth)?->format('Y-m-d') ?? $profile->date_of_birth,
            'height_cm' => $profile->height_cm,
            'religion' => $profile->religion_id,
            'caste' => $profile->caste_id,
            'education' => $profile->highest_education,
            'occupation' => $profile->occupation_title,
            'annual_income' => $profile->annual_income,
            'city' => $profile->location_id,
            'state' => $profile->state_id ?? $profile->residence_state_id ?? null,
            'mother_tongue' => $profile->mother_tongue_id,
            'marital_status' => $profile->marital_status_id ?? $profile->marital_status ?? null,
            'family_type' => $profile->family_type_id,
            'complexion' => $profile->complexion_id,
            'blood_group' => $profile->blood_group_id,
            'nakshatra' => optional($profile->horoscope)->nakshatra_id,
            'rashi' => optional($profile->horoscope)->rashi_id,
            'mangal_dosh' => optional($profile->horoscope)->mangal_dosh_type_id,
            'income_range' => $profile->income_range_id,
            'professions' => $profile->profession_id,
            'partner_preferences' => optional($profile->preferenceCriteria)?->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureRepeaters(MatrimonyProfile $profile): array
    {
        $siblingRows = $profile->siblings()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($r) => [
            'id' => (int) $r->id,
            'relation_type' => (string) ($r->relation_type ?? ''),
            'name' => (string) ($r->name ?? ''),
            'marital_status' => (string) ($r->marital_status ?? ''),
            'occupation' => (string) ($r->occupation ?? ''),
            'occupation_master_id' => $r->occupation_master_id,
            'occupation_custom_id' => $r->occupation_custom_id,
            'city_id' => $r->city_id,
            'contact_number' => (string) ($r->contact_number ?? ''),
            'notes' => (string) ($r->notes ?? ''),
        ])->values()->all();

        $childrenRows = $profile->children()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($r) => [
            'id' => (int) $r->id,
            'child_name' => (string) ($r->child_name ?? ''),
            'gender' => (string) ($r->gender ?? ''),
            'age' => $r->age,
            'child_living_with_id' => $r->child_living_with_id,
        ])->values()->all();

        $educationRows = Schema::hasTable('profile_education')
            ? DB::table('profile_education')->where('profile_id', $profile->id)->orderBy('id')->get()->map(fn ($r) => [
                'id' => (int) ($r->id ?? 0),
                'degree' => (string) ($r->degree ?? ''),
                'specialization' => (string) ($r->specialization ?? ''),
                'university' => (string) ($r->university ?? ''),
                'year_completed' => $r->year_completed,
            ])->values()->all()
            : [];

        $relativeRows = $profile->relatives()->orderBy('id')->get()->map(fn ($r) => [
            'id' => (int) $r->id,
            'relation_type' => (string) ($r->relation_type ?? ''),
            'name' => (string) ($r->name ?? ''),
            'occupation' => (string) ($r->occupation ?? ''),
            'occupation_master_id' => $r->occupation_master_id,
            'occupation_custom_id' => $r->occupation_custom_id,
            'city_id' => $r->city_id,
            'state_id' => $r->state_id,
            'contact_number' => (string) ($r->contact_number ?? ''),
            'notes' => (string) ($r->notes ?? ''),
            'is_primary_contact' => (bool) $r->is_primary_contact,
        ])->values()->all();

        $contacts = [];
        if (Schema::hasTable('profile_contacts')) {
            $contacts = DB::table('profile_contacts')
                ->where('profile_id', $profile->id)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get()
                ->map(fn ($r) => [
                    'id' => (int) ($r->id ?? 0),
                    'contact_relation_id' => $r->contact_relation_id ?? null,
                    'phone_number' => (string) ($r->phone_number ?? ''),
                    'is_primary' => (bool) ($r->is_primary ?? false),
                    'verified_status' => (string) ($r->verified_status ?? ''),
                ])->values()->all();
        }

        return [
            'siblings' => $siblingRows,
            'children' => $childrenRows,
            'education_history' => $educationRows,
            'career_history' => [],
            'relatives' => $relativeRows,
            'property_details' => (string) ($profile->getAttribute('property_details') ?? ''),
            'contacts' => $contacts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureApi(MatrimonyProfile $profile): array
    {
        $apiController = app(MatrimonyProfileApiController::class);
        $owner = $profile->user;
        if (! $owner) {
            return ['error' => 'profile_has_no_owner_user'];
        }

        return $this->runAsUser($owner->id, function () use ($apiController, $profile) {
            $request = Request::create('/api/matrimony-profiles/'.$profile->id, 'GET');
            $request->setUserResolver(fn () => Auth::user());
            app()->instance('request', $request);
            $response = $apiController->showById($profile->id);

            return method_exists($response, 'getData')
                ? $response->getData(true)
                : ['error' => 'unexpected_api_response_type'];
        });
    }

    /**
     * @param  array<string, mixed>  $db
     * @param  array{api: bool, public_profile: bool, wizard: bool}  $sources
     * @return array<string, mixed>
     */
    private function captureRendered(MatrimonyProfile $profile, array $sources, array $db, array $repeaters, array $api): array
    {
        $owner = $profile->user;
        if (! $owner) {
            return ['error' => 'profile_has_no_owner_user'];
        }

        $out = [
            'pages' => [],
            'fields' => [],
            'fields_by_source' => [],
        ];
        $wizardRepeaterFlat = [];
        $publicRepeaterFlat = [];
        $renderPayload = [
            'db' => $db,
            'repeaters' => $repeaters,
            'api' => is_array($api['profile'] ?? null) ? $api['profile'] : [],
        ];

        if ($sources['public_profile']) {
            $html = $this->runAsUser($owner->id, function () use ($profile) {
                ViewFacade::share('errors', new ViewErrorBag);
                $controller = app(MatrimonyProfileController::class);
                $response = $controller->show($profile->id);

                return $this->toHtml($response);
            });
            $out['pages']['public_profile'] = [
                'route' => route('matrimony.profile.show', $profile->id),
                'html_sha1' => sha1($html),
                'html_excerpt' => mb_substr($html, 0, 2000),
            ];
            $sourceExtract = $this->extractor->extract($html, $db);
            $out['fields_by_source']['public_profile'] = $sourceExtract;
            $recursivePublic = $this->recursiveExtractor->extractAgainstHtml($renderPayload, $html);
            $publicRepeaterFlat = $this->filterRepeaterRenderPaths($recursivePublic);
            $out['fields'] = array_merge(
                $out['fields'],
                $recursivePublic,
                $this->convertSourceExtract($sourceExtract)
            );
        }

        if ($sources['wizard']) {
            $html = $this->runAsUser($owner->id, function () {
                ViewFacade::share('errors', new ViewErrorBag);
                $controller = app(ProfileWizardController::class);
                $request = Request::create('/matrimony/profile/wizard/full?all=1', 'GET', ['all' => 1]);
                $request->setUserResolver(fn () => Auth::user());
                app()->instance('request', $request);
                $response = $controller->show($request, 'full');

                return $this->toHtml($response);
            });
            $out['pages']['wizard'] = [
                'section' => 'full',
                'html_sha1' => sha1($html),
                'html_excerpt' => mb_substr($html, 0, 2000),
            ];
            $sourceExtract = $this->extractor->extract($html, $db);
            $out['fields_by_source']['wizard'] = $sourceExtract;
            $recursiveWizard = $this->recursiveExtractor->extractAgainstHtml($renderPayload, $html);
            $wizardRepeaterFlat = $this->filterRepeaterRenderPaths($recursiveWizard);
            $out['fields'] = array_merge(
                $out['fields'],
                $recursiveWizard,
                $this->convertSourceExtract($sourceExtract)
            );
        }
        ksort($out['fields']);
        $out['repeaters_render_flat'] = [
            'wizard' => $wizardRepeaterFlat,
            'public_profile' => $publicRepeaterFlat,
        ];

        return $out;
    }

    /**
     * @param  array<string,mixed>  $flat
     * @return array<string,mixed>
     */
    private function filterRepeaterRenderPaths(array $flat): array
    {
        $o = [];
        foreach ($flat as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'repeaters.')) {
                $o[$k] = $v;
            }
        }
        ksort($o);

        return $o;
    }

    /**
     * @param  array<string,array<int,array<string,mixed>>>  $repeaters
     * @param  array<string,mixed>  $rendered
     * @param  array{api: bool, public_profile: bool, wizard: bool}  $sources
     * @return array<string,mixed>
     */
    private function buildRepeaterGovernancePayload(array $repeaters, array $rendered, array $sources): array
    {
        $flats = is_array($rendered['repeaters_render_flat'] ?? null) ? $rendered['repeaters_render_flat'] : [];
        $wizardFlat = is_array($flats['wizard'] ?? null) ? $flats['wizard'] : [];
        $publicFlat = is_array($flats['public_profile'] ?? null) ? $flats['public_profile'] : [];

        $builder = app(RepeaterSnapshotBuilder::class);
        $matcher = app(RepeaterRowMatcher::class);
        $diffEngine = app(RepeaterDiffEngine::class);
        $explainer = app(RepeaterExplainabilityService::class);

        $byRepeater = [];
        $repeaterFieldDiffs = [];
        foreach (array_keys($repeaters) as $name) {
            $wRows = $this->rowsFromRepeaterRenderFlat($wizardFlat, (string) $name);
            $pRows = $this->rowsFromRepeaterRenderFlat($publicFlat, (string) $name);
            if ($wRows === [] && $pRows === []) {
                continue;
            }
            $normW = $builder->build([(string) $name => $wRows])[(string) $name] ?? [];
            $normP = $builder->build([(string) $name => $pRows])[(string) $name] ?? [];
            $normW = is_array($normW) ? $normW : [];
            $normP = is_array($normP) ? $normP : [];
            $rawDiffs = $diffEngine->diff((string) $name, $normW, $normP);
            $explained = $explainer->explain($rawDiffs);
            $byRepeater[(string) $name] = [
                'wizard_row_count' => count($wRows),
                'public_row_count' => count($pRows),
                'matcher_matches' => count($matcher->match($normW, $normP)),
                'explained_diffs' => $explained,
            ];
            foreach ($explained as $d) {
                if (! is_array($d)) {
                    continue;
                }
                $wizIdx = (int) ($d['row'] ?? $d['wizard_index'] ?? 0);
                $repeaterFieldDiffs[] = [
                    'repeater' => (string) $name,
                    'row' => $wizIdx + 1,
                    'field' => (string) ($d['field'] ?? ''),
                    'wizard' => $d['wizard'] ?? null,
                    'api' => null,
                    'public_profile' => $d['public_profile'] ?? null,
                    'normalized' => [
                        'wizard' => is_scalar($d['wizard'] ?? null) ? mb_strtolower(trim((string) $d['wizard'])) : null,
                        'api' => null,
                        'public_profile' => is_scalar($d['public_profile'] ?? null) ? mb_strtolower(trim((string) $d['public_profile'])) : null,
                    ],
                    'comparison_type' => ($d['status'] ?? '') === 'missing_row' ? 'missing_row' : 'semantic_mismatch',
                    'severity' => ($d['status'] ?? '') === 'missing_row' ? 'high' : 'medium',
                    'status' => (string) ($d['status'] ?? ''),
                ];
            }
        }

        $proof = [
            'executed_at' => now()->toIso8601String(),
            'services_used' => [
                RepeaterSnapshotBuilder::class,
                RepeaterRowMatcher::class,
                RepeaterDiffEngine::class,
                RepeaterExplainabilityService::class,
            ],
            'sources' => $sources,
            'wizard_repeater_flat_paths' => count($wizardFlat),
            'public_repeater_flat_paths' => count($publicFlat),
            'repeaters_with_diffs' => count($byRepeater),
        ];
        Log::info('governance_repeater_runtime', $proof + ['diff_events' => count($repeaterFieldDiffs)]);

        return [
            'runtime_proof' => $proof,
            'by_repeater' => $byRepeater,
            'repeater_field_diffs' => $repeaterFieldDiffs,
        ];
    }

    /**
     * @param  array<string,mixed>  $flat
     * @return list<array<string,mixed>>
     */
    private function rowsFromRepeaterRenderFlat(array $flat, string $repeater): array
    {
        $prefix = 'repeaters.'.$repeater.'.';
        /** @var array<int,array<string,mixed>> $grouped */
        $grouped = [];
        foreach ($flat as $path => $val) {
            if (! is_string($path) || ! str_starts_with($path, $prefix)) {
                continue;
            }
            $rest = substr($path, strlen($prefix));
            if (! preg_match('/^(\d+)\.(.+)$/', $rest, $m)) {
                continue;
            }
            $idx = (int) $m[1];
            $col = (string) $m[2];
            $grouped[$idx][$col] = $val;
        }
        ksort($grouped);

        return array_values($grouped);
    }

    /**
     * @param  array<string,array{raw_rendered:string|null,normalized:string|null}>  $sourceExtract
     * @return array<string,mixed>
     */
    private function convertSourceExtract(array $sourceExtract): array
    {
        $out = [];
        foreach ($sourceExtract as $field => $row) {
            $out[$field] = $row['raw_rendered'] ?? null;
        }

        return $out;
    }

    private function runAsUser(int $userId, callable $callback): mixed
    {
        Auth::shouldUse('web');
        $already = Auth::user();
        Auth::loginUsingId($userId);
        try {
            return $callback();
        } finally {
            Auth::logout();
            if ($already !== null) {
                Auth::login($already);
            }
        }
    }

    private function toHtml(mixed $response): string
    {
        if ($response instanceof View) {
            return $response->render();
        }

        if ($response instanceof Response) {
            return (string) $response->getContent();
        }

        if (is_object($response) && method_exists($response, 'getContent')) {
            return (string) $response->getContent();
        }

        if (is_string($response)) {
            return $response;
        }

        return '';
    }
}
