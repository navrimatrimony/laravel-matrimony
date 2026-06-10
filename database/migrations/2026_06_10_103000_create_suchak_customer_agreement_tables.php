<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_customer_agreements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('customer_context_id')->nullable();
            $table->unsignedBigInteger('service_package_id');
            $table->unsignedBigInteger('supersedes_agreement_id')->nullable();
            $table->unsignedInteger('agreement_revision')->default(1);
            $table->string('terms_status', 32)->default('pending');
            $table->string('terms_policy_mode', 32)->default('strict');
            $table->string('agreement_snapshot_hash', 64);
            $table->string('package_name', 160);
            $table->text('package_description')->nullable();
            $table->decimal('price_amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('agreement_title', 160);
            $table->text('agreement_body')->nullable();
            $table->text('invoice_note')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('declined_by_user_id')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->unsignedBigInteger('bypassed_by_user_id')->nullable();
            $table->timestamp('bypassed_at')->nullable();
            $table->text('bypass_reason')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['service_package_id', 'agreement_revision'], 'sk_agreements_pkg_revision_unique');
            $table->index('suchak_account_id', 'sk_agreements_account_idx');
            $table->index('customer_context_id', 'sk_agreements_customer_idx');
            $table->index('service_package_id', 'sk_agreements_package_idx');
            $table->index('supersedes_agreement_id', 'sk_agreements_supersedes_idx');
            $table->index('terms_status', 'sk_agreements_terms_status_idx');
            $table->index('terms_policy_mode', 'sk_agreements_policy_idx');
            $table->index('created_by_user_id', 'sk_agreements_created_by_idx');
            $table->index('accepted_by_user_id', 'sk_agreements_accepted_by_idx');
            $table->index('bypassed_by_user_id', 'sk_agreements_bypassed_by_idx');
            $table->index('created_at', 'sk_agreements_created_idx');
            $table->index([
                'suchak_account_id',
                'service_package_id',
                'terms_status',
            ], 'sk_agreements_account_pkg_status_idx');

            $table->foreign('suchak_account_id', 'sk_agreements_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_context_id', 'sk_agreements_customer_fk')->references('id')->on('suchak_customer_contexts')->restrictOnDelete();
            $table->foreign('service_package_id', 'sk_agreements_package_fk')->references('id')->on('suchak_service_packages')->restrictOnDelete();
            $table->foreign('supersedes_agreement_id', 'sk_agreements_supersedes_fk')->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'sk_agreements_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('accepted_by_user_id', 'sk_agreements_accepted_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('declined_by_user_id', 'sk_agreements_declined_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('bypassed_by_user_id', 'sk_agreements_bypassed_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_customer_agreement_stages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_agreement_id');
            $table->unsignedBigInteger('service_package_stage_id')->nullable();
            $table->string('stage_key', 80);
            $table->string('stage_name', 160);
            $table->text('stage_description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('expected_days')->nullable();
            $table->timestamps();

            $table->unique(['customer_agreement_id', 'stage_key'], 'sk_agr_stage_key_unique');
            $table->index('customer_agreement_id', 'sk_agr_stage_agreement_idx');
            $table->index('service_package_stage_id', 'sk_agr_stage_package_stage_idx');
            $table->index(['customer_agreement_id', 'sort_order'], 'sk_agr_stage_sort_idx');

            $table->foreign('customer_agreement_id', 'sk_agr_stage_agreement_fk')->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
            $table->foreign('service_package_stage_id', 'sk_agr_stage_package_stage_fk')->references('id')->on('suchak_service_package_stages')->restrictOnDelete();
        });

        Schema::create('suchak_customer_agreement_deliverables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_agreement_id');
            $table->unsignedBigInteger('agreement_stage_id')->nullable();
            $table->unsignedBigInteger('service_package_deliverable_id')->nullable();
            $table->string('deliverable_key', 80);
            $table->string('deliverable_name', 160);
            $table->text('deliverable_description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['customer_agreement_id', 'deliverable_key'], 'sk_agr_deliv_key_unique');
            $table->index('customer_agreement_id', 'sk_agr_deliv_agreement_idx');
            $table->index('agreement_stage_id', 'sk_agr_deliv_stage_idx');
            $table->index('service_package_deliverable_id', 'sk_agr_deliv_pkg_deliv_idx');
            $table->index(['customer_agreement_id', 'sort_order'], 'sk_agr_deliv_sort_idx');

            $table->foreign('customer_agreement_id', 'sk_agr_deliv_agreement_fk')->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
            $table->foreign('agreement_stage_id', 'sk_agr_deliv_stage_fk')->references('id')->on('suchak_customer_agreement_stages')->restrictOnDelete();
            $table->foreign('service_package_deliverable_id', 'sk_agr_deliv_pkg_deliv_fk')->references('id')->on('suchak_service_package_deliverables')->restrictOnDelete();
        });

        $now = now();

        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => 'suchak_terms_policy_mode',
                'policy_value' => 'strict',
                'value_type' => 'string',
                'description' => 'Suchak customer agreement terms policy mode.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('suchak_policies')
            ->where('policy_key', 'suchak_terms_policy_mode')
            ->delete();

        Schema::dropIfExists('suchak_customer_agreement_deliverables');
        Schema::dropIfExists('suchak_customer_agreement_stages');
        Schema::dropIfExists('suchak_customer_agreements');
    }
};
