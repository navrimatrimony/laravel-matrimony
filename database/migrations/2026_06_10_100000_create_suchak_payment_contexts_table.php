<?php

use App\Models\SuchakPaymentContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_payment_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('matrimony_profile_id');
            $table->unsignedBigInteger('pipeline_id')->nullable();
            $table->unsignedBigInteger('collaboration_request_id')->nullable();
            $table->string('source_owner');
            $table->string('payment_collector');
            $table->string('context_status')->default(SuchakPaymentContext::STATUS_ACTIVE);
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'suchak_pay_context_account_idx');
            $table->index('matrimony_profile_id', 'suchak_pay_context_profile_idx');
            $table->index('pipeline_id', 'suchak_pay_context_pipeline_idx');
            $table->index('collaboration_request_id', 'suchak_pay_context_collab_idx');
            $table->index('source_owner', 'suchak_pay_context_owner_idx');
            $table->index('payment_collector', 'suchak_pay_context_collector_idx');
            $table->index('context_status', 'suchak_pay_context_status_idx');
            $table->index('created_at', 'suchak_pay_context_created_idx');
            $table->index([
                'suchak_account_id',
                'matrimony_profile_id',
                'context_status',
            ], 'suchak_pay_context_account_profile_status_idx');

            $table->foreign('suchak_account_id', 'suchak_pay_context_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('matrimony_profile_id', 'suchak_pay_context_profile_fk')->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('pipeline_id', 'suchak_pay_context_pipeline_fk')->references('id')->on('suchak_pipelines')->restrictOnDelete();
            $table->foreign('collaboration_request_id', 'suchak_pay_context_collab_fk')->references('id')->on('suchak_collaboration_requests')->restrictOnDelete();
            $table->foreign('resolved_by_user_id', 'suchak_pay_context_resolver_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('suchak_ledger_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('payment_context_id')->nullable()->after('collaboration_request_id');
            $table->index('payment_context_id', 'suchak_ledger_payment_context_idx');
            $table->foreign('payment_context_id', 'suchak_ledger_payment_context_fk')->references('id')->on('suchak_payment_contexts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suchak_ledger_entries', function (Blueprint $table): void {
            $table->dropForeign('suchak_ledger_payment_context_fk');
            $table->dropIndex('suchak_ledger_payment_context_idx');
            $table->dropColumn('payment_context_id');
        });

        Schema::dropIfExists('suchak_payment_contexts');
    }
};
