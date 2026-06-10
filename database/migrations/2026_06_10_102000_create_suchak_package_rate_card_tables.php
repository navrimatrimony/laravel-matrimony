<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_package_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('template_name', 160);
            $table->text('template_description')->nullable();
            $table->decimal('base_price_amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('template_status', 32)->default('approved');
            $table->unsignedBigInteger('created_by_admin_user_id');
            $table->unsignedBigInteger('approved_by_admin_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('template_status', 'sk_pkg_tpl_status_idx');
            $table->index('created_by_admin_user_id', 'sk_pkg_tpl_created_by_idx');
            $table->index('approved_by_admin_user_id', 'sk_pkg_tpl_approved_by_idx');
            $table->index('created_at', 'sk_pkg_tpl_created_idx');

            $table->foreign('created_by_admin_user_id', 'sk_pkg_tpl_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by_admin_user_id', 'sk_pkg_tpl_approved_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('suchak_package_template_stages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_template_id');
            $table->string('stage_key', 80);
            $table->string('stage_name', 160);
            $table->text('stage_description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('expected_days')->nullable();
            $table->timestamps();

            $table->unique(['package_template_id', 'stage_key'], 'sk_pkg_tpl_stage_key_unique');
            $table->index('package_template_id', 'sk_pkg_tpl_stage_tpl_idx');
            $table->index(['package_template_id', 'sort_order'], 'sk_pkg_tpl_stage_sort_idx');

            $table->foreign('package_template_id', 'sk_pkg_tpl_stage_tpl_fk')->references('id')->on('suchak_package_templates')->restrictOnDelete();
        });

        Schema::create('suchak_package_template_deliverables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_template_id');
            $table->unsignedBigInteger('template_stage_id')->nullable();
            $table->string('deliverable_key', 80);
            $table->string('deliverable_name', 160);
            $table->text('deliverable_description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['package_template_id', 'deliverable_key'], 'sk_pkg_tpl_deliv_key_unique');
            $table->index('package_template_id', 'sk_pkg_tpl_deliv_tpl_idx');
            $table->index('template_stage_id', 'sk_pkg_tpl_deliv_stage_idx');
            $table->index(['package_template_id', 'sort_order'], 'sk_pkg_tpl_deliv_sort_idx');

            $table->foreign('package_template_id', 'sk_pkg_tpl_deliv_tpl_fk')->references('id')->on('suchak_package_templates')->restrictOnDelete();
            $table->foreign('template_stage_id', 'sk_pkg_tpl_deliv_stage_fk')->references('id')->on('suchak_package_template_stages')->restrictOnDelete();
        });

        Schema::create('suchak_service_packages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('source_template_id')->nullable();
            $table->string('package_name', 160);
            $table->text('package_description')->nullable();
            $table->decimal('price_amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('package_status', 32)->default('pending_review');
            $table->string('approval_policy_mode', 32)->default('admin_review');
            $table->boolean('requires_admin_approval')->default(true);
            $table->unsignedBigInteger('customized_by_user_id');
            $table->timestamp('submitted_for_review_at')->nullable();
            $table->unsignedBigInteger('approved_by_admin_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by_admin_user_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'sk_service_pkg_account_idx');
            $table->index('customer_context_id', 'sk_service_pkg_customer_idx');
            $table->index('source_template_id', 'sk_service_pkg_template_idx');
            $table->index('package_status', 'sk_service_pkg_status_idx');
            $table->index('approval_policy_mode', 'sk_service_pkg_policy_idx');
            $table->index('customized_by_user_id', 'sk_service_pkg_custom_by_idx');
            $table->index('approved_by_admin_user_id', 'sk_service_pkg_approved_by_idx');
            $table->index('rejected_by_admin_user_id', 'sk_service_pkg_rejected_by_idx');
            $table->index('created_at', 'sk_service_pkg_created_idx');
            $table->index([
                'suchak_account_id',
                'package_status',
                'created_at',
            ], 'sk_service_pkg_acct_status_idx');

            $table->foreign('suchak_account_id', 'sk_service_pkg_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_service_pkg_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('source_template_id', 'sk_service_pkg_template_fk')->references('id')->on('suchak_package_templates')->restrictOnDelete();
            $table->foreign('customized_by_user_id', 'sk_service_pkg_custom_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by_admin_user_id', 'sk_service_pkg_approved_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by_admin_user_id', 'sk_service_pkg_rejected_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('suchak_service_package_stages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_package_id');
            $table->unsignedBigInteger('template_stage_id')->nullable();
            $table->string('stage_key', 80);
            $table->string('stage_name', 160);
            $table->text('stage_description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('expected_days')->nullable();
            $table->timestamps();

            $table->unique(['service_package_id', 'stage_key'], 'sk_service_pkg_stage_key_unique');
            $table->index('service_package_id', 'sk_service_pkg_stage_pkg_idx');
            $table->index('template_stage_id', 'sk_service_pkg_stage_tpl_idx');
            $table->index(['service_package_id', 'sort_order'], 'sk_service_pkg_stage_sort_idx');

            $table->foreign('service_package_id', 'sk_service_pkg_stage_pkg_fk')->references('id')->on('suchak_service_packages')->restrictOnDelete();
            $table->foreign('template_stage_id', 'sk_service_pkg_stage_tpl_fk')->references('id')->on('suchak_package_template_stages')->restrictOnDelete();
        });

        Schema::create('suchak_service_package_deliverables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_package_id');
            $table->unsignedBigInteger('service_package_stage_id')->nullable();
            $table->unsignedBigInteger('template_deliverable_id')->nullable();
            $table->string('deliverable_key', 80);
            $table->string('deliverable_name', 160);
            $table->text('deliverable_description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['service_package_id', 'deliverable_key'], 'sk_service_pkg_deliv_key_unique');
            $table->index('service_package_id', 'sk_service_pkg_deliv_pkg_idx');
            $table->index('service_package_stage_id', 'sk_service_pkg_deliv_stage_idx');
            $table->index('template_deliverable_id', 'sk_service_pkg_deliv_tpl_idx');
            $table->index(['service_package_id', 'sort_order'], 'sk_service_pkg_deliv_sort_idx');

            $table->foreign('service_package_id', 'sk_service_pkg_deliv_pkg_fk')->references('id')->on('suchak_service_packages')->restrictOnDelete();
            $table->foreign('service_package_stage_id', 'sk_service_pkg_deliv_stage_fk')->references('id')->on('suchak_service_package_stages')->restrictOnDelete();
            $table->foreign('template_deliverable_id', 'sk_service_pkg_deliv_tpl_fk')->references('id')->on('suchak_package_template_deliverables')->restrictOnDelete();
        });

        $now = now();

        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => 'suchak_package_publish_approval_mode',
                'policy_value' => 'admin_review',
                'value_type' => 'string',
                'description' => 'Suchak customer package publish approval policy.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', 'suchak_package_publish_approval_mode')
            ->delete();

        Schema::dropIfExists('suchak_service_package_deliverables');
        Schema::dropIfExists('suchak_service_package_stages');
        Schema::dropIfExists('suchak_service_packages');
        Schema::dropIfExists('suchak_package_template_deliverables');
        Schema::dropIfExists('suchak_package_template_stages');
        Schema::dropIfExists('suchak_package_templates');
    }
};
