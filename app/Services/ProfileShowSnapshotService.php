<?php

namespace App\Services;

use App\Models\Caste;
use App\Models\City;
use App\Models\District;
use App\Models\EducationDegree;
use App\Models\OccupationMaster;
use App\Models\MasterDiet;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\State;
use App\Support\HeightDisplay;
use App\Support\ProfileDisplayCopy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ordered “profile snapshot” sections for the public profile show page (wizard order).
 *
 * @phpstan-type SnapshotRow array{label: string, value?: string|null, locked?: bool, full?: bool}
 * @phpstan-type SnapshotGroup array{heading: string, lines: list<string>}
 * @phpstan-type SnapshotSection array{
 *   id: string,
 *   title: string,
 *   kicker: string,
 *   accent: string,
 *   icon: string,
 *   rows?: list<SnapshotRow>,
 *   groups?: list<SnapshotGroup>,
 *   timelines?: list<array{title: string, items: list<string>}>
 * }
 */
class ProfileShowSnapshotService
{
    public function __construct(
        private IncomeEngineService $incomeEngine
    ) {}

    /**
     * @param  array<string, mixed>  $ctx
     * @return list<SnapshotSection>
     */
    public function build(MatrimonyProfile $profile, array $ctx): array
    {
        $profile->loadMissing([
            'gender',
            'maritalStatus',
            'religion',
            'caste',
            'subCaste',
            'motherTongue',
            'complexion',
            'physicalBuild',
            'bloodGroup',
            'profession',
            'workingWithType',
            'incomeCurrency',
            'familyIncomeCurrency',
            'familyType',
            'seriousIntent',
            'birthCity',
            'birthTaluka',
            'birthDistrict',
            'birthState',
            'nativeCity',
            'nativeTaluka',
            'nativeDistrict',
            'nativeState',
            'city',
            'taluka',
            'district',
            'state',
            'country',
            'educationHistory',
            'career',
            'addresses',
            'addresses.village',
            'children.childLivingWith',
            'marriages',
            'siblings.city',
            'relatives.city',
            'relatives.state',
            'allianceNetworks.city',
            'allianceNetworks.state',
            'allianceNetworks.district',
            'allianceNetworks.taluka',
            'horoscope.rashi',
            'horoscope.nakshatra',
            'horoscope.gan',
            'horoscope.nadi',
            'horoscope.mangalDoshType',
            'horoscope.yoni',
        ]);

        $ctx = array_merge([
            'is_own_profile' => false,
            'date_of_birth_visible' => true,
            'marital_status_visible' => true,
            'education_visible' => true,
            'location_visible' => true,
            'caste_visible' => true,
            'height_visible' => true,
            'enable_relatives_section' => true,
            'profile_property_summary' => null,
            'preference_criteria' => null,
            'preferred_religion_ids' => [],
            'preferred_caste_ids' => [],
            'preferred_district_ids' => [],
            'preferred_education_degree_ids' => [],
            'preferred_occupation_master_ids' => [],
            'preferred_diet_ids' => [],
            'preferred_marital_status_ids' => [],
            'extended_attributes' => null,
            'extended_values' => [],
            'extended_meta' => [],
        ], $ctx);

        $sections = array_filter([
            $this->sectionBasicInfo($profile, $ctx),
            $this->sectionPhysical($profile, $ctx),
            $this->sectionEducationCareer($profile, $ctx),
            $this->sectionFamily($profile, $ctx),
            $this->sectionSiblingsDetail($profile),
            $this->sectionExtendedFamily($profile, $ctx),
            $this->sectionAlliance($profile),
            $this->sectionProperty($profile, $ctx),
            $this->sectionHoroscope($profile),
            $this->sectionPartnerPreferences($profile, $ctx),
            $this->sectionAdditional($ctx),
        ]);

        return array_values(array_filter($sections));
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return ?SnapshotSection
     */
    private function sectionBasicInfo(MatrimonyProfile $profile, array $ctx): ?array
    {
        $rows = [];

        $gLabel = $profile->gender?->label ?? null;
        if ($this->present($gLabel)) {
            $rows[] = $this->row(__('Gender'), $gLabel);
        }

        if (($ctx['date_of_birth_visible'] ?? true) && $this->present($profile->date_of_birth)) {
            try {
                $d = Carbon::parse($profile->date_of_birth)->format('j M Y');
                $rows[] = $this->row(__('Date of birth'), $d);
            } catch (\Throwable) {
            }
        }

        if ($this->present($profile->birth_time)) {
            $rows[] = $this->row(__('Birth time'), (string) $profile->birth_time);
        }

        if (($ctx['marital_status_visible'] ?? true) && $profile->maritalStatus && $this->present($profile->maritalStatus->label ?? null)) {
            $rows[] = $this->row(__('Marital status'), (string) $profile->maritalStatus->label);
        }

        if ($profile->seriousIntent && $this->present($profile->seriousIntent->name ?? null)) {
            $rows[] = $this->row(__('Serious intent'), (string) $profile->seriousIntent->name);
        }

        if ($this->present($profile->religion?->label ?? null)) {
            $rows[] = $this->row(__('Religion'), (string) $profile->religion->label);
        }
        if ($this->present($profile->caste?->label ?? null)) {
            $rows[] = $this->row(__('Caste'), (string) $profile->caste->label);
        }
        if ($this->present($profile->subCaste?->label ?? null)) {
            $rows[] = $this->row(__('Subcaste'), (string) $profile->subCaste->label);
        }
        if ($this->present($profile->motherTongue?->label ?? null)) {
            $rows[] = $this->row(__('Mother tongue'), (string) $profile->motherTongue->label);
        }

        if ($this->present($profile->address_line)) {
            $rows[] = $this->row(__('Address'), (string) $profile->address_line);
        }

        if ($ctx['location_visible'] ?? true) {
            $loc = trim(ProfileDisplayCopy::profileResidenceDisplayLine($profile));
            if ($this->present($loc)) {
                $rows[] = $this->row(__('Location'), $loc);
            }
        }

        $birthPlace = implode(', ', array_filter([
            $profile->birthCity?->name,
            $profile->birthTaluka?->name,
            $profile->birthDistrict?->name,
            $profile->birthState?->name,
        ]));
        if ($this->present($birthPlace)) {
            $rows[] = $this->row(__('Birth Place'), $birthPlace);
        }

        $nativePlace = implode(', ', array_filter([
            $profile->nativeCity?->name,
            $profile->nativeTaluka?->name,
            $profile->nativeDistrict?->name,
            $profile->nativeState?->name,
        ]));
        if ($this->present($nativePlace)) {
            $rows[] = $this->row(__('Native Place'), $nativePlace);
        }

        if ($profile->addresses?->isNotEmpty()) {
            $ai = 0;
            foreach ($profile->addresses as $addr) {
                $ai++;
                $line = implode(', ', array_filter([
                    trim((string) ($addr->village?->name ?? '')),
                    $addr->city?->name,
                    $addr->taluka?->name,
                    $addr->district?->name,
                    $addr->state?->name,
                    $addr->country?->name,
                ]));
                if (trim((string) ($addr->postal_code ?? '')) !== '') {
                    $line .= ($line !== '' ? ' — ' : '').$addr->postal_code;
                }
                if ($this->present($line)) {
                    $label = $profile->addresses->count() > 1
                        ? __('Address').' '.$ai
                        : __('Address');
                    $rows[] = $this->row($label, $line, false, true);
                }
            }
        }

        if (($ctx['is_own_profile'] ?? false) && $this->present($profile->primary_contact_number)) {
            $rows[] = $this->row(__('Phone'), (string) $profile->primary_contact_number);
        }

        if ($rows === []) {
            return null;
        }

        return $this->wrap('basic_info', __('profile.snapshot_section_basic_info'), __('profile.snapshot_kicker_ordered'), 'stone', 'id-card', $rows);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sectionPhysical(MatrimonyProfile $profile, array $ctx): ?array
    {
        $rows = [];
        $hv = (bool) ($ctx['height_visible'] ?? true);

        if ($hv && $this->present($profile->height_cm)) {
            $rows[] = $this->row(__('Height'), $profile->height_cm.' cm');
        }
        if (($profile->weight_kg ?? null) !== null && (string) $profile->weight_kg !== '') {
            $rows[] = $this->row(__('Weight'), $profile->weight_kg.' kg');
        }
        if ($profile->complexion && $this->present($profile->complexion->label ?? null)) {
            $rows[] = $this->row(__('Complexion'), (string) $profile->complexion->label);
        }
        if ($profile->physicalBuild && $this->present($profile->physicalBuild->label ?? null)) {
            $rows[] = $this->row(__('Physical Build'), (string) $profile->physicalBuild->label);
        }
        if ($profile->bloodGroup && $this->present($profile->bloodGroup->label ?? null)) {
            $rows[] = $this->row(__('Blood Group'), (string) $profile->bloodGroup->label);
        }

        if ($rows === []) {
            return null;
        }

        return $this->wrap('physical', __('profile.snapshot_section_physical'), __('profile.snapshot_kicker_ordered'), 'rose', 'user', $rows);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sectionEducationCareer(MatrimonyProfile $profile, array $ctx): ?array
    {
        $rows = [];
        $timelines = [];
        $eduVisible = (bool) ($ctx['education_visible'] ?? true);

        if ($eduVisible) {
            $eduLine = trim((string) ($profile->highest_education ?? ''));
            if ($this->present($profile->highest_education_other)) {
                $eduLine .= ($eduLine !== '' ? ' — ' : '').$profile->highest_education_other;
            }
            if ($this->present($eduLine)) {
                $rows[] = $this->row(__('Education'), $eduLine);
            }
        }
        if ($this->present($profile->specialization)) {
            $rows[] = $this->row(__('Specialization'), (string) $profile->specialization);
        }

        if ($profile->workingWithType && $this->present($profile->workingWithType->name ?? null)) {
            $rows[] = $this->row(__('components.education.working_with'), (string) $profile->workingWithType->name);
        }
        if ($profile->profession && $this->present($profile->profession->name ?? null)) {
            $rows[] = $this->row(__('components.education.working_as'), (string) $profile->profession->name);
        }
        if ($this->present($profile->occupation_title)) {
            $rows[] = $this->row(__('Occupation'), (string) $profile->occupation_title);
        }
        if ($this->present($profile->company_name)) {
            $rows[] = $this->row(__('Company'), (string) $profile->company_name);
        }

        $arr = $profile->toArray();
        $personalDisp = $this->incomeEngine->formatForDisplay($arr, 'income', $profile->incomeCurrency);
        $familyDisp = $this->incomeEngine->formatForDisplay($arr, 'family_income', $profile->familyIncomeCurrency ?? $profile->incomeCurrency);

        $hasPersonal = ($profile->income_value_type ?? null) !== null
            || ($profile->income_amount ?? null) !== null
            || ($profile->income_min_amount ?? null) !== null
            || ($profile->annual_income ?? null) !== null;
        $hasFamily = ($profile->family_income_value_type ?? null) !== null
            || ($profile->family_income_amount ?? null) !== null
            || ($profile->family_income_min_amount ?? null) !== null
            || ($profile->family_income ?? null) !== null;

        if ($hasPersonal) {
            $rows[] = $this->incomeRow(__('Income'), $personalDisp, (bool) ($profile->income_private ?? false));
        }
        if ($hasFamily) {
            $rows[] = $this->incomeRow(__('Family Income'), $familyDisp, (bool) ($profile->family_income_private ?? false));
        }
        if ($profile->incomeCurrency && ! $hasPersonal && ! $hasFamily) {
            $sym = $profile->incomeCurrency->displaySymbol().' '.($profile->incomeCurrency->code ?? '');
            $rows[] = $this->row(__('Income Currency'), trim($sym));
        }

        $workCity = $profile->work_city_id ? City::query()->where('id', $profile->work_city_id)->value('name') : null;
        $workState = $profile->work_state_id ? State::query()->where('id', $profile->work_state_id)->value('name') : null;
        $workLoc = implode(', ', array_filter([$workCity, $workState]));
        if ($this->present($workLoc)) {
            $rows[] = $this->row(__('Work Location'), $workLoc);
        }

        if ($profile->educationHistory && $profile->educationHistory->isNotEmpty()) {
            $items = [];
            foreach ($profile->educationHistory as $edu) {
                $line = ($edu->degree ?: '—')
                    .($edu->specialization ? ' – '.$edu->specialization : '')
                    .($edu->university ? ' ('.$edu->university.')' : '')
                    .($edu->year_completed ? ', '.$edu->year_completed : '');
                $items[] = $line;
            }
            if ($items !== []) {
                $timelines[] = ['title' => __('Education History'), 'items' => $items];
            }
        }

        if ($profile->career && $profile->career->isNotEmpty()) {
            $items = [];
            foreach ($profile->career as $job) {
                $items[] = ($job->designation ?: '—')
                    .($job->company ? ' at '.$job->company : '')
                    .(($job->start_year || $job->end_year) ? ' ('.($job->start_year ?? '').'–'.($job->end_year ?? '').')' : '');
            }
            if ($items !== []) {
                $timelines[] = ['title' => __('Career History'), 'items' => $items];
            }
        }

        if ($rows === [] && $timelines === []) {
            return null;
        }

        $section = $this->wrap('education_career', __('profile.snapshot_section_education_career'), __('profile.snapshot_kicker_ordered'), 'sky', 'academic-cap', $rows);
        if ($timelines !== []) {
            $section['timelines'] = $timelines;
        }

        return $section;
    }

    private function sectionFamily(MatrimonyProfile $profile, array $ctx): ?array
    {
        $rows = [];
        $siblings = $profile->siblings ?? collect();
        $brothers = $siblings->where('relation_type', 'brother')->count();
        $sisters = $siblings->where('relation_type', 'sister')->count();

        if ($this->present($profile->father_name)) {
            $v = (string) $profile->father_name;
            if ($this->present($profile->father_occupation)) {
                $v .= ' · '.$profile->father_occupation;
            }
            $rows[] = $this->row(__('Father'), $v);
        }
        if ($this->present($profile->mother_name)) {
            $v = (string) $profile->mother_name;
            if ($this->present($profile->mother_occupation)) {
                $v .= ' · '.$profile->mother_occupation;
            }
            $rows[] = $this->row(__('Mother'), $v);
        }

        if ($brothers > 0 || $sisters > 0) {
            $b = $brothers > 0 ? $brothers.' brother'.($brothers !== 1 ? 's' : '') : '';
            $s = $sisters > 0 ? $sisters.' sister'.($sisters !== 1 ? 's' : '') : '';
            $rows[] = $this->row(__('Siblings'), trim($b.($b && $s ? ', ' : '').$s));
        }

        if ($profile->familyType && $this->present($profile->familyType->label ?? null)) {
            $rows[] = $this->row(__('Family Type'), (string) $profile->familyType->label);
        }

        if ($profile->children?->isNotEmpty()) {
            $i = 0;
            foreach ($profile->children as $child) {
                $i++;
                $parts = array_filter([
                    $child->child_name ?: __('Child').' '.$i,
                    ! empty($child->age) ? $child->age.' '.__('search.years_short') : null,
                    ! empty($child->gender) ? strtolower((string) $child->gender) : null,
                    $child->childLivingWith?->label ? __('Living with').': '.$child->childLivingWith->label : null,
                ]);
                $rows[] = $this->row(__('Child').' '.$i, implode(' · ', $parts), false, true);
            }
        }

        $marriageBlocks = [];
        if ($profile->marriages?->isNotEmpty()) {
            foreach ($profile->marriages as $marriageRow) {
                $lines = array_filter([
                    ($marriageRow->marriage_year ?? null) !== null && $marriageRow->marriage_year !== '' ? __('Marriage year').': '.$marriageRow->marriage_year : null,
                    ($marriageRow->divorce_year ?? null) !== null && $marriageRow->divorce_year !== '' ? __('Divorce year').': '.$marriageRow->divorce_year : null,
                    ($marriageRow->separation_year ?? null) !== null && $marriageRow->separation_year !== '' ? __('Separation year').': '.$marriageRow->separation_year : null,
                    ($marriageRow->spouse_death_year ?? null) !== null && $marriageRow->spouse_death_year !== '' ? __('Spouse death year').': '.$marriageRow->spouse_death_year : null,
                    ($marriageRow->divorce_status ?? '') !== '' ? __('Divorce status').': '.$marriageRow->divorce_status : null,
                    ($marriageRow->remarriage_reason ?? '') !== '' ? __('Remarriage reason').': '.$marriageRow->remarriage_reason : null,
                    ($marriageRow->notes ?? '') !== '' ? __('Notes').': '.$marriageRow->notes : null,
                ]);
                if ($lines !== []) {
                    $marriageBlocks[] = array_values($lines);
                }
            }
        }

        $marriageBlocks = $this->dedupeMarriageBlocks($marriageBlocks);

        if ($rows === [] && $marriageBlocks === []) {
            return null;
        }

        $section = $this->wrap('family', __('profile.snapshot_section_family'), __('profile.snapshot_kicker_ordered'), 'amber', 'home', $rows);

        if ($marriageBlocks !== []) {
            $section['marriage_blocks'] = $marriageBlocks;
        }

        return $section;
    }

    private function sectionSiblingsDetail(MatrimonyProfile $profile): ?array
    {
        if (! $profile->siblings?->isNotEmpty()) {
            return null;
        }

        $groups = [];
        $byGender = $profile->siblings->groupBy(fn ($s) => ($s->gender ?? 'other') ?: 'other');
        foreach ($byGender as $gender => $items) {
            $lines = [];
            foreach ($items as $sib) {
                $parts = array_filter([
                    $this->present($sib->name) ? (string) $sib->name : null,
                    $this->present($sib->occupation) ? (string) $sib->occupation : null,
                    $sib->marital_status ? ucfirst((string) $sib->marital_status) : null,
                    $sib->city?->name,
                    $sib->notes ? Str::limit((string) $sib->notes, 80) : null,
                ]);
                $lines[] = implode(' · ', $parts) ?: '—';
            }
            $groups[] = [
                'heading' => Str::title((string) $gender),
                'lines' => $lines,
            ];
        }

        if ($groups === []) {
            return null;
        }

        return [
            'id' => 'siblings_detail',
            'title' => __('profile.snapshot_section_siblings'),
            'kicker' => __('profile.snapshot_kicker_ordered'),
            'accent' => 'indigo',
            'icon' => 'users',
            'groups' => $groups,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sectionExtendedFamily(MatrimonyProfile $profile, array $ctx): ?array
    {
        if (empty($ctx['enable_relatives_section']) || ! $profile->relatives?->isNotEmpty()) {
            return null;
        }

        $labels = [
            'paternal_uncle' => 'Paternal Uncle', 'wife_paternal_uncle' => 'Wife of Paternal Uncle',
            'paternal_aunt' => 'Paternal Aunt', 'husband_paternal_aunt' => 'Husband of Paternal Aunt',
            'maternal_uncle' => 'Maternal Uncle', 'wife_maternal_uncle' => 'Wife of Maternal Uncle',
            'maternal_aunt' => 'Maternal Aunt', 'husband_maternal_aunt' => 'Husband of Maternal Aunt',
            'Cousin' => 'Cousin',
            'paternal_grandfather' => 'Paternal Grandfather', 'paternal_grandmother' => 'Paternal Grandmother',
            'maternal_grandfather' => 'Maternal Grandfather', 'maternal_grandmother' => 'Maternal Grandmother',
            'great_uncle' => 'Great Uncle', 'great_aunt' => 'Great Aunt', 'other_grandparents_family' => 'Other (Grandparents\' family)',
            'maternal_cousin' => 'Cousin (maternal)', 'other_maternal' => 'Other (maternal)',
            'Uncle' => 'Uncle', 'Aunt' => 'Aunt', 'Grandfather' => 'Grandfather', 'Grandmother' => 'Grandmother', 'Other' => 'Other',
        ];

        $groups = [];
        $byType = $profile->relatives->groupBy(fn ($r) => $r->relation_type ?: 'Other');
        foreach ($byType as $type => $relatives) {
            $lines = [];
            foreach ($relatives as $rel) {
                $tail = trim(implode(', ', array_filter([$rel->city?->name, $rel->state?->name])));
                $line = ($rel->name ?: '—')
                    .($rel->occupation ? ' · '.$rel->occupation : '')
                    .($tail !== '' ? ' ('.$tail.')' : '')
                    .($rel->contact_number ? ' · '.$rel->contact_number : '')
                    .($rel->notes ? ' · '.Str::limit((string) $rel->notes, 80) : '');
                $lines[] = $line;
            }
            $heading = $labels[$type] ?? Str::title(str_replace('_', ' ', (string) $type));
            $groups[] = ['heading' => $heading, 'lines' => $lines];
        }

        return [
            'id' => 'extended_family',
            'title' => __('profile.snapshot_section_extended_family'),
            'kicker' => __('profile.snapshot_kicker_ordered'),
            'accent' => 'violet',
            'icon' => 'users',
            'groups' => $groups,
        ];
    }

    private function sectionAlliance(MatrimonyProfile $profile): ?array
    {
        if (! $profile->allianceNetworks?->isNotEmpty()) {
            return null;
        }

        $groups = [];
        $byLoc = $profile->allianceNetworks->groupBy(function ($a) {
            $parts = array_filter([$a->city?->name, $a->taluka?->name, $a->district?->name, $a->state?->name]);

            return implode(', ', $parts) ?: 'Other';
        });

        foreach ($byLoc as $locLabel => $items) {
            $lines = [];
            foreach ($items as $a) {
                $lines[] = ($a->surname ?: '—').($a->notes ? ' · '.Str::limit((string) $a->notes, 80) : '');
            }
            $groups[] = ['heading' => $locLabel, 'lines' => $lines];
        }

        return [
            'id' => 'alliance',
            'title' => __('profile.snapshot_section_alliance'),
            'kicker' => __('profile.snapshot_kicker_ordered'),
            'accent' => 'cyan',
            'icon' => 'map',
            'groups' => $groups,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sectionProperty(MatrimonyProfile $profile, array $ctx): ?array
    {
        $rows = [];
        $summary = $ctx['profile_property_summary'] ?? null;

        if ($summary) {
            if (! empty($summary->owns_house)) {
                $rows[] = $this->row(__('Owns house'), __('Yes'));
            }
            if (! empty($summary->owns_flat)) {
                $rows[] = $this->row(__('Owns flat'), __('Yes'));
            }
            if (! empty($summary->owns_agriculture)) {
                $rows[] = $this->row(__('Owns agriculture'), __('Yes'));
            }
            if (($summary->total_land_acres ?? null) !== null && (string) $summary->total_land_acres !== '') {
                $rows[] = $this->row(__('Total land (acres)'), (string) $summary->total_land_acres);
            }
            if (($summary->annual_agri_income ?? null) !== null && (string) $summary->annual_agri_income !== '') {
                $rows[] = $this->row(__('Annual agriculture income'), (string) $summary->annual_agri_income);
            }
            if ($this->present($summary->agriculture_type ?? null)) {
                $rows[] = $this->row(__('Agriculture type'), (string) $summary->agriculture_type);
            }
            if ($this->present($summary->summary_notes ?? null)) {
                $rows[] = $this->row(__('Notes'), (string) $summary->summary_notes, false, true);
            }
        }

        $assets = DB::table('profile_property_assets')->where('profile_id', $profile->id)->orderBy('id')->get();
        foreach ($assets as $idx => $asset) {
            $bits = array_filter([
                $asset->asset_type ?? null,
                $asset->location ?? null,
                $asset->ownership_type ?? null,
                isset($asset->estimated_value) && $asset->estimated_value !== null ? __('Est. value').': '.$asset->estimated_value : null,
            ]);
            if ($bits !== []) {
                $rows[] = $this->row(__('Property').' '.($idx + 1), implode(' · ', $bits), false, true);
            }
        }

        if ($rows === []) {
            return null;
        }

        return $this->wrap('property', __('profile.snapshot_section_property'), __('profile.snapshot_kicker_ordered'), 'emerald', 'building', $rows);
    }

    private function sectionHoroscope(MatrimonyProfile $profile): ?array
    {
        $h = $profile->horoscope;
        if (! $h) {
            return null;
        }

        $rows = [];
        if ($h->rashi && $this->present($h->rashi->label ?? null)) {
            $rows[] = $this->row(__('Rashi'), (string) $h->rashi->label);
        }
        if ($h->nakshatra && $this->present($h->nakshatra->label ?? null)) {
            $rows[] = $this->row(__('Nakshatra'), (string) $h->nakshatra->label);
        }
        if ($h->gan && $this->present($h->gan->label ?? null)) {
            $rows[] = $this->row(__('Gan'), (string) $h->gan->label);
        }
        if ($h->nadi && $this->present($h->nadi->label ?? null)) {
            $rows[] = $this->row(__('Nadi'), (string) $h->nadi->label);
        }
        if ($h->mangalDoshType && $this->present($h->mangalDoshType->label ?? null)) {
            $rows[] = $this->row(__('Mangal Dosh'), (string) $h->mangalDoshType->label);
        }
        if ($h->yoni && $this->present($h->yoni->label ?? null)) {
            $rows[] = $this->row(__('Yoni'), (string) $h->yoni->label);
        }
        if ($this->present($h->charan ?? null)) {
            $rows[] = $this->row(__('Charan'), (string) $h->charan);
        }
        if ($this->present($h->devak ?? null)) {
            $rows[] = $this->row(__('Devak'), (string) $h->devak);
        }
        if ($this->present($h->kul ?? null)) {
            $rows[] = $this->row(__('Kul'), (string) $h->kul);
        }
        if ($this->present($h->gotra ?? null)) {
            $rows[] = $this->row(__('Gotra'), (string) $h->gotra);
        }
        if ($this->present($h->navras_name ?? null)) {
            $rows[] = $this->row(__('Navras name'), (string) $h->navras_name);
        }
        if ($this->present($h->birth_weekday ?? null)) {
            $rows[] = $this->row(__('Birth weekday'), (string) $h->birth_weekday);
        }

        if ($rows === []) {
            return null;
        }

        return $this->wrap('horoscope', __('profile.snapshot_section_horoscope'), __('profile.snapshot_kicker_ordered'), 'purple', 'sparkles', $rows);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sectionPartnerPreferences(MatrimonyProfile $profile, array $ctx): ?array
    {
        $rows = [];
        $pc = $ctx['preference_criteria'] ?? null;

        if ($pc && (($pc->preferred_age_min ?? null) !== null || ($pc->preferred_age_max ?? null) !== null)) {
            $rows[] = $this->row(__('Age'), ($pc->preferred_age_min ?? '—').'–'.($pc->preferred_age_max ?? '—'));
        }

        if ($pc && (($pc->preferred_height_min_cm ?? null) !== null || ($pc->preferred_height_max_cm ?? null) !== null)) {
            $hMin = (int) ($pc->preferred_height_min_cm ?? 0);
            $hMax = (int) ($pc->preferred_height_max_cm ?? 0);
            $text = ($hMin > 0 && $hMax > 0)
                ? HeightDisplay::formatCmRange($hMin, $hMax)
                : (($pc->preferred_height_min_cm ?? '—').'–'.($pc->preferred_height_max_cm ?? '—').' cm');
            $rows[] = $this->row(__('wizard.preferred_height_range'), $text);
        }

        if ($pc && ($pc->preferred_city_id ?? null)) {
            $name = City::query()->where('id', $pc->preferred_city_id)->value('name');
            if ($this->present($name)) {
                $rows[] = $this->row(__('City'), (string) $name);
            }
        }

        $msIds = $ctx['preferred_marital_status_ids'] ?? [];
        if ($msIds !== []) {
            $labels = MasterMaritalStatus::query()->whereIn('id', $msIds)->orderBy('label')->pluck('label')->filter()->values()->all();
            if ($labels !== []) {
                $rows[] = $this->row(__('wizard.marital_status_preference'), implode(', ', $labels));
            }
        } elseif ($pc && ($pc->preferred_marital_status_id ?? null)) {
            $lbl = MasterMaritalStatus::query()->where('id', $pc->preferred_marital_status_id)->value('label');
            if ($this->present($lbl)) {
                $rows[] = $this->row(__('wizard.marital_status_preference'), (string) $lbl);
            }
        }

        if ($pc && in_array($pc->partner_profile_with_children ?? null, ['no', 'yes_if_live_separate', 'yes'], true)) {
            $pwc = $pc->partner_profile_with_children;
            $pwcLabel = match ($pwc) {
                'no' => __('wizard.partner_children_no'),
                'yes_if_live_separate' => __('wizard.partner_children_yes_if_live_separate'),
                'yes' => __('wizard.partner_children_yes'),
                default => (string) $pwc,
            };
            $rows[] = $this->row(__('wizard.profile_with_children_partner'), $pwcLabel);
        }

        $prefRel = $ctx['preferred_religion_ids'] ?? [];
        if ($prefRel !== []) {
            $labs = Religion::query()->whereIn('id', $prefRel)->pluck('label')->filter()->values()->all();
            if ($labs !== []) {
                $rows[] = $this->row(__('Religions'), implode(', ', $labs));
            }
        }

        $prefCastes = $ctx['preferred_caste_ids'] ?? [];
        if ($prefCastes !== []) {
            $labs = Caste::query()->whereIn('id', $prefCastes)->pluck('label')->filter()->values()->all();
            if ($labs !== []) {
                $rows[] = $this->row(__('Castes'), implode(', ', $labs));
            }
        }

        $prefDist = $ctx['preferred_district_ids'] ?? [];
        if ($prefDist !== []) {
            $labs = District::query()->whereIn('id', $prefDist)->pluck('name')->filter()->values()->all();
            if ($labs !== []) {
                $rows[] = $this->row(__('Districts'), implode(', ', $labs));
            }
        }

        if ($pc && (($pc->preferred_income_min ?? null) !== null || ($pc->preferred_income_max ?? null) !== null)) {
            $rows[] = $this->row(__('Preferred income'), ($pc->preferred_income_min ?? '—').' – '.($pc->preferred_income_max ?? '—'));
        }

        $prefDeg = $ctx['preferred_education_degree_ids'] ?? [];
        if ($prefDeg !== []) {
            $labs = EducationDegree::query()->whereIn('id', $prefDeg)->orderBy('sort_order')->pluck('title')->filter()->values()->all();
            if ($labs !== []) {
                $rows[] = $this->row(__('Preferred qualification'), implode(', ', $labs));
            }
        }

        $prefDiet = $ctx['preferred_diet_ids'] ?? [];
        if ($prefDiet !== []) {
            $labs = MasterDiet::query()->whereIn('id', $prefDiet)->orderBy('sort_order')->pluck('label')->filter()->values()->all();
            if ($labs !== []) {
                $rows[] = $this->row(__('Preferred diet'), implode(', ', $labs));
            }
        }

        $prefOccM = $ctx['preferred_occupation_master_ids'] ?? [];
        if ($prefOccM !== []) {
            $labs = OccupationMaster::query()->whereIn('id', $prefOccM)->orderBy('sort_order')->pluck('name')->filter()->values()->all();
            if ($labs !== []) {
                $rows[] = $this->row(__('Preferred occupation'), implode(', ', $labs));
            }
        }

        $ea = $ctx['extended_attributes'] ?? null;
        $exp = trim((string) ($ea->narrative_expectations ?? ''));
        if ($exp !== '') {
            $rows[] = $this->row(__('Expectations'), $exp, false, true);
        }

        if ($rows === []) {
            return null;
        }

        return $this->wrap('partner_preferences', __('profile.snapshot_section_partner_preferences'), __('profile.snapshot_kicker_ordered'), 'rose', 'heart', $rows);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function sectionAdditional(array $ctx): ?array
    {
        $values = $ctx['extended_values'] ?? [];
        $meta = $ctx['extended_meta'] ?? [];
        if (! is_array($values) || $values === []) {
            return null;
        }

        $filtered = array_filter($values, fn ($v) => $v !== null && $v !== '');
        if ($filtered === []) {
            return null;
        }

        $rows = [];
        foreach ($filtered as $key => $value) {
            $display = $this->scalarExtendedValue($value);
            if (! $this->present($display)) {
                continue;
            }
            $label = is_string($meta[$key] ?? null) ? (string) $meta[$key] : (string) $key;
            $rows[] = $this->row($label, $display, false, true);
        }

        if ($rows === []) {
            return null;
        }

        return $this->wrap('additional', __('profile.snapshot_section_additional'), __('profile.snapshot_kicker_ordered'), 'stone', 'document', $rows);
    }

    private function scalarExtendedValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $parts[] = is_scalar($v) ? (string) $v : json_encode($v);
            }

            return implode(', ', $parts);
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : json_encode($value);
        }

        return (string) $value;
    }

    /**
     * @return SnapshotRow
     */
    private function incomeRow(string $label, string $display, bool $private): array
    {
        $locked = $private
            || $display === 'Not disclosed'
            || $display === 'Income hidden';

        return [
            'label' => $label,
            'value' => $locked ? '' : $display,
            'locked' => $locked,
            'full' => false,
        ];
    }

    /**
     * @return SnapshotRow
     */
    private function row(string $label, ?string $value, bool $locked = false, bool $full = false): array
    {
        return [
            'label' => $label,
            'value' => $value ?? '',
            'locked' => $locked,
            'full' => $full,
        ];
    }

    /**
     * @param  list<SnapshotRow>  $rows
     * @return SnapshotSection
     */
    private function wrap(string $id, string $title, string $kicker, string $accent, string $icon, array $rows): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'kicker' => $kicker,
            'accent' => $accent,
            'icon' => $icon,
            'rows' => $rows,
        ];
    }

    /**
     * Duplicate {@code profile_marriages} rows with identical display lines → single card.
     *
     * @param  list<list<string>>  $blocks
     * @return list<list<string>>
     */
    private function dedupeMarriageBlocks(array $blocks): array
    {
        $seen = [];
        $out = [];
        foreach ($blocks as $block) {
            $key = json_encode($block, JSON_UNESCAPED_UNICODE);
            if ($key === false || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $block;
        }

        return $out;
    }

    private function present(?string $v): bool
    {
        return $v !== null && trim((string) $v) !== '';
    }
}
