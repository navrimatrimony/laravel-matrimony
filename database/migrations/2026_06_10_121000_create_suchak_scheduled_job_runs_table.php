<?php

use App\Models\SuchakScheduledJobRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_scheduled_job_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_key', 160);
            $table->string('job_key', 80);
            $table->string('job_status', 32)->default(SuchakScheduledJobRun::STATUS_RUNNING);
            $table->string('triggered_by', 32)->default(SuchakScheduledJobRun::TRIGGER_SYSTEM);
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->unsignedBigInteger('account_scope_id')->nullable();
            $table->date('run_for_date');
            $table->string('run_month', 7)->nullable();
            $table->json('metrics_json')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('run_key', 'sk_scheduled_job_runs_key_unique');
            $table->index('job_key', 'sk_scheduled_job_runs_job_idx');
            $table->index('job_status', 'sk_scheduled_job_runs_status_idx');
            $table->index('triggered_by_user_id', 'sk_scheduled_job_runs_user_idx');
            $table->index('admin_audit_log_id', 'sk_scheduled_job_runs_audit_idx');
            $table->index('account_scope_id', 'sk_scheduled_job_runs_account_idx');
            $table->index('run_for_date', 'sk_scheduled_job_runs_date_idx');
            $table->index('run_month', 'sk_scheduled_job_runs_month_idx');
            $table->index(['job_key', 'run_for_date'], 'sk_scheduled_job_runs_job_date_idx');

            $table->foreign('triggered_by_user_id', 'sk_scheduled_job_runs_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_scheduled_job_runs_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
            $table->foreign('account_scope_id', 'sk_scheduled_job_runs_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_scheduled_job_runs');
    }
};
