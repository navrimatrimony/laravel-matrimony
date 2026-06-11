<?php

use App\Models\SuchakFeatureSuspension;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_feature_suspensions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('feature_key', 64);
            $table->string('suspension_status', 32)->default(SuchakFeatureSuspension::STATUS_ACTIVE);
            $table->text('reason');
            $table->unsignedBigInteger('created_by_admin_user_id');
            $table->unsignedBigInteger('created_admin_audit_log_id')->nullable();
            $table->unsignedBigInteger('released_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('released_admin_audit_log_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'sk_feature_susp_account_idx');
            $table->index('feature_key', 'sk_feature_susp_feature_idx');
            $table->index('suspension_status', 'sk_feature_susp_status_idx');
            $table->index('created_by_admin_user_id', 'sk_feature_susp_created_by_idx');
            $table->index('released_by_admin_user_id', 'sk_feature_susp_released_by_idx');
            $table->index([
                'suchak_account_id',
                'feature_key',
                'suspension_status',
            ], 'sk_feature_susp_account_feature_status_idx');

            $table->foreign('suchak_account_id', 'sk_feature_susp_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('created_by_admin_user_id', 'sk_feature_susp_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_admin_audit_log_id', 'sk_feature_susp_created_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
            $table->foreign('released_by_admin_user_id', 'sk_feature_susp_released_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_admin_audit_log_id', 'sk_feature_susp_released_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_feature_suspensions');
    }
};
