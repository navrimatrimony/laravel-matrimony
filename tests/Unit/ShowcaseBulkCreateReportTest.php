<?php

namespace Tests\Unit;

use App\Models\AdminSetting;
use App\Services\Showcase\ShowcasePhotoPoolSettings;
use App\Services\Showcase\ShowcaseBulkCreateReport;
use App\Services\Showcase\ShowcaseProfileCreateResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseBulkCreateReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_outcomes(): void
    {
        $report = new ShowcaseBulkCreateReport(5);
        $report->add(new ShowcaseProfileCreateResult(1, ShowcaseProfileCreateResult::OUTCOME_CREATED));
        $report->add(new ShowcaseProfileCreateResult(2, ShowcaseProfileCreateResult::OUTCOME_CREATED_WITHOUT_PHOTO, ShowcasePhotoPoolSettings::MISSING_FOLDER, 'female / hindu / never_married / 25-30', 'eng/female/hindu/never_married/25-30'));
        $report->add(new ShowcaseProfileCreateResult(null, ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_PHOTO, ShowcasePhotoPoolSettings::MISSING_FOLDER, 'female / muslim / never_married / 25-30', 'eng/female/muslim/never_married/25-30'));
        $report->add(new ShowcaseProfileCreateResult(null, ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_LOCATION));
        $report->add(new ShowcaseProfileCreateResult(null, ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_PHOTO, ShowcasePhotoPoolSettings::MISSING_FOLDER, 'female / muslim / never_married / 25-30', 'eng/female/muslim/never_married/25-30'));

        $summary = $report->toSummary();

        $this->assertSame(5, $summary['requested']);
        $this->assertSame(2, $summary['created']);
        $this->assertSame(1, $summary['with_photo']);
        $this->assertSame(1, $summary['without_photo']);
        $this->assertSame(2, $summary['skipped_no_photo']);
        $this->assertSame(1, $summary['skipped_no_location']);
    }

    public function test_grouped_warnings_merge_same_folder(): void
    {
        $report = new ShowcaseBulkCreateReport(3);
        $report->add(new ShowcaseProfileCreateResult(10, ShowcaseProfileCreateResult::OUTCOME_CREATED_WITHOUT_PHOTO, ShowcasePhotoPoolSettings::POOL_EXHAUSTED, 'female / hindu / never_married / 25-30', 'eng/female/hindu/never_married/25-30'));
        $report->add(new ShowcaseProfileCreateResult(11, ShowcaseProfileCreateResult::OUTCOME_CREATED_WITHOUT_PHOTO, ShowcasePhotoPoolSettings::POOL_EXHAUSTED, 'female / hindu / never_married / 25-30', 'eng/female/hindu/never_married/25-30'));
        $report->add(new ShowcaseProfileCreateResult(null, ShowcaseProfileCreateResult::OUTCOME_SKIPPED_NO_PHOTO, ShowcasePhotoPoolSettings::MISSING_FOLDER, 'male / hindu / never_married / 31-35', 'eng/male/hindu/never_married/31-35'));

        $groups = $report->groupedPhotoWarnings();

        $this->assertCount(2, $groups);
        $exhausted = collect($groups)->firstWhere('reason_key', ShowcasePhotoPoolSettings::POOL_EXHAUSTED);
        $this->assertNotNull($exhausted);
        $this->assertSame(2, $exhausted['count']);
        $this->assertSame([10, 11], $exhausted['profile_ids']);
    }

    public function test_photo_policy_labels_reflect_admin_setting(): void
    {
        AdminSetting::setValue(ShowcasePhotoPoolSettings::SETTING_KEY, json_encode([
            'missing_exact_folder_action' => ShowcasePhotoPoolSettings::ACTION_SKIP_PROFILE,
            'pool_exhausted_action' => ShowcasePhotoPoolSettings::ACTION_CREATE_WITHOUT_PHOTO,
            'allow_reuse_when_bucket_exhausted' => true,
        ]));

        $labels = ShowcaseBulkCreateReport::photoPolicyLabels();

        $this->assertSame(__('showcase_bulk.policy_skip'), $labels['missing']);
        $this->assertSame(__('showcase_bulk.policy_create_without_photo'), $labels['exhausted']);
        $this->assertSame(__('showcase_bulk.policy_reuse_on'), $labels['reuse']);
    }
}
