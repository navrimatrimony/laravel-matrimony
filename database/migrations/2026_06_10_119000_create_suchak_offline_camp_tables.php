<?php

use App\Models\SuchakOfflineCamp;
use App\Models\SuchakOfflineCampConversionReport;
use App\Models\SuchakOfflineCampIntakeLink;
use App\Models\SuchakOfflineCampPackageAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_offline_camps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('camp_key', 96);
            $table->string('camp_name', 160);
            $table->string('camp_type', 48)->default(SuchakOfflineCamp::TYPE_BIODATA_DRIVE);
            $table->string('camp_status', 32)->default(SuchakOfflineCamp::STATUS_PLANNED);
            $table->string('source_tag', 96);
            $table->string('location_label', 160)->nullable();
            $table->date('camp_date')->nullable();
            $table->unsignedInteger('expected_intake_count')->default(0);
            $table->text('privacy_note');
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['suchak_account_id', 'camp_key'], 'sk_offline_camp_account_key_unique');
            $table->unique(['suchak_account_id', 'source_tag'], 'sk_offline_camp_account_tag_unique');
            $table->index('suchak_account_id', 'sk_offline_camp_account_idx');
            $table->index('camp_type', 'sk_offline_camp_type_idx');
            $table->index('camp_status', 'sk_offline_camp_status_idx');
            $table->index('source_tag', 'sk_offline_camp_source_tag_idx');
            $table->index('camp_date', 'sk_offline_camp_date_idx');
            $table->index('created_by_user_id', 'sk_offline_camp_creator_idx');

            $table->foreign('suchak_account_id', 'sk_offline_camp_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'sk_offline_camp_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_offline_camp_intake_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offline_camp_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('source_link_id');
            $table->unsignedBigInteger('biodata_intake_id');
            $table->string('source_tag', 96);
            $table->string('source_status_snapshot', 48);
            $table->string('link_status', 32)->default(SuchakOfflineCampIntakeLink::STATUS_LINKED);
            $table->string('duplicate_check_status', 32)->default(SuchakOfflineCampIntakeLink::DUPLICATE_UNIQUE);
            $table->string('privacy_safe_duplicate_hash', 64)->nullable();
            $table->string('duplicate_match_reference_hash', 64)->nullable();
            $table->text('link_note')->nullable();
            $table->unsignedBigInteger('linked_by_user_id');
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->unique('source_link_id', 'sk_offline_camp_source_link_unique');
            $table->index('offline_camp_id', 'sk_offline_camp_link_camp_idx');
            $table->index('suchak_account_id', 'sk_offline_camp_link_account_idx');
            $table->index('biodata_intake_id', 'sk_offline_camp_link_intake_idx');
            $table->index('source_tag', 'sk_offline_camp_link_tag_idx');
            $table->index('link_status', 'sk_offline_camp_link_status_idx');
            $table->index('duplicate_check_status', 'sk_offline_camp_link_dup_status_idx');
            $table->index('privacy_safe_duplicate_hash', 'sk_offline_camp_link_dup_hash_idx');
            $table->index('linked_by_user_id', 'sk_offline_camp_link_user_idx');

            $table->foreign('offline_camp_id', 'sk_offline_camp_link_camp_fk')->references('id')->on('suchak_offline_camps')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_offline_camp_link_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('source_link_id', 'sk_offline_camp_link_source_fk')->references('id')->on('suchak_biodata_intake_links')->restrictOnDelete();
            $table->foreign('biodata_intake_id', 'sk_offline_camp_link_intake_fk')->references('id')->on('biodata_intakes')->restrictOnDelete();
            $table->foreign('linked_by_user_id', 'sk_offline_camp_link_user_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_offline_camp_package_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offline_camp_id');
            $table->unsignedBigInteger('offline_camp_intake_link_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('source_link_id');
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('service_package_id');
            $table->string('assignment_status', 32)->default(SuchakOfflineCampPackageAssignment::STATUS_ASSIGNED);
            $table->text('assignment_note');
            $table->unsignedBigInteger('assigned_by_user_id');
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['offline_camp_intake_link_id', 'service_package_id'], 'sk_offline_camp_pkg_link_pkg_unique');
            $table->index('offline_camp_id', 'sk_offline_camp_pkg_camp_idx');
            $table->index('suchak_account_id', 'sk_offline_camp_pkg_account_idx');
            $table->index('source_link_id', 'sk_offline_camp_pkg_source_idx');
            $table->index('customer_context_id', 'sk_offline_camp_pkg_customer_idx');
            $table->index('service_package_id', 'sk_offline_camp_pkg_package_idx');
            $table->index('assignment_status', 'sk_offline_camp_pkg_status_idx');
            $table->index('assigned_by_user_id', 'sk_offline_camp_pkg_user_idx');

            $table->foreign('offline_camp_id', 'sk_offline_camp_pkg_camp_fk')->references('id')->on('suchak_offline_camps')->restrictOnDelete();
            $table->foreign('offline_camp_intake_link_id', 'sk_offline_camp_pkg_link_fk')->references('id')->on('suchak_offline_camp_intake_links')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_offline_camp_pkg_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('source_link_id', 'sk_offline_camp_pkg_source_fk')->references('id')->on('suchak_biodata_intake_links')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_offline_camp_pkg_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('service_package_id', 'sk_offline_camp_pkg_package_fk')->references('id')->on('suchak_service_packages')->restrictOnDelete();
            $table->foreign('assigned_by_user_id', 'sk_offline_camp_pkg_user_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_offline_camp_conversion_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('offline_camp_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('source_tag', 96);
            $table->string('report_status', 32)->default(SuchakOfflineCampConversionReport::STATUS_GENERATED);
            $table->unsignedInteger('total_intake_links')->default(0);
            $table->unsignedInteger('unique_intake_links')->default(0);
            $table->unsignedInteger('possible_duplicate_links')->default(0);
            $table->unsignedInteger('consent_pending_count')->default(0);
            $table->unsignedInteger('customer_context_count')->default(0);
            $table->unsignedInteger('package_assignment_count')->default(0);
            $table->unsignedInteger('active_service_count')->default(0);
            $table->text('report_note');
            $table->json('metrics_json')->nullable();
            $table->unsignedBigInteger('generated_by_user_id');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index('offline_camp_id', 'sk_offline_camp_report_camp_idx');
            $table->index('suchak_account_id', 'sk_offline_camp_report_account_idx');
            $table->index('source_tag', 'sk_offline_camp_report_tag_idx');
            $table->index('report_status', 'sk_offline_camp_report_status_idx');
            $table->index('generated_by_user_id', 'sk_offline_camp_report_user_idx');
            $table->index('generated_at', 'sk_offline_camp_report_at_idx');

            $table->foreign('offline_camp_id', 'sk_offline_camp_report_camp_fk')->references('id')->on('suchak_offline_camps')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_offline_camp_report_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('generated_by_user_id', 'sk_offline_camp_report_user_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_offline_camp_conversion_reports');
        Schema::dropIfExists('suchak_offline_camp_package_assignments');
        Schema::dropIfExists('suchak_offline_camp_intake_links');
        Schema::dropIfExists('suchak_offline_camps');
    }
};
