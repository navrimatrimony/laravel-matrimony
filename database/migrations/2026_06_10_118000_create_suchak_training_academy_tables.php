<?php

use App\Models\SuchakMessageTemplate;
use App\Models\SuchakTrainingCertificate;
use App\Models\SuchakTrainingCompletion;
use App\Models\SuchakTrainingModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_training_modules', function (Blueprint $table): void {
            $table->id();
            $table->string('module_key', 96)->unique('sk_train_module_key_unique');
            $table->string('module_title', 160);
            $table->string('module_category', 64);
            $table->string('module_status', 32)->default(SuchakTrainingModule::STATUS_ACTIVE);
            $table->boolean('is_required_for_certificate')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('summary');
            $table->text('content_outline');
            $table->unsignedBigInteger('created_by_admin_user_id');
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->timestamps();

            $table->index('module_category', 'sk_train_module_category_idx');
            $table->index('module_status', 'sk_train_module_status_idx');
            $table->index('created_by_admin_user_id', 'sk_train_module_admin_idx');
            $table->index('admin_audit_log_id', 'sk_train_module_audit_idx');

            $table->foreign('created_by_admin_user_id', 'sk_train_module_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_train_module_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        Schema::create('suchak_training_completions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('training_module_id');
            $table->string('completion_status', 32)->default(SuchakTrainingCompletion::STATUS_COMPLETED);
            $table->unsignedTinyInteger('score_percent')->nullable();
            $table->text('completion_note');
            $table->unsignedBigInteger('completed_by_admin_user_id');
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['suchak_account_id', 'training_module_id'], 'sk_train_comp_account_module_unique');
            $table->index('suchak_account_id', 'sk_train_comp_account_idx');
            $table->index('training_module_id', 'sk_train_comp_module_idx');
            $table->index('completion_status', 'sk_train_comp_status_idx');
            $table->index('completed_by_admin_user_id', 'sk_train_comp_admin_idx');
            $table->index('admin_audit_log_id', 'sk_train_comp_audit_idx');

            $table->foreign('suchak_account_id', 'sk_train_comp_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('training_module_id', 'sk_train_comp_module_fk')->references('id')->on('suchak_training_modules')->restrictOnDelete();
            $table->foreign('completed_by_admin_user_id', 'sk_train_comp_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_train_comp_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        Schema::create('suchak_training_certificates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('certificate_code', 80)->unique('sk_train_cert_code_unique');
            $table->string('certificate_status', 32)->default(SuchakTrainingCertificate::STATUS_ISSUED);
            $table->string('certificate_scope', 32)->default(SuchakTrainingCertificate::SCOPE_INTERNAL);
            $table->string('public_badge_status', 32)->default(SuchakTrainingCertificate::PUBLIC_BADGE_NOT_PUBLIC);
            $table->json('required_module_ids_json');
            $table->text('certificate_note');
            $table->unsignedBigInteger('issued_by_admin_user_id');
            $table->unsignedBigInteger('issued_admin_audit_log_id')->nullable();
            $table->timestamp('issued_at');
            $table->unsignedBigInteger('revoked_by_admin_user_id')->nullable();
            $table->unsignedBigInteger('revoked_admin_audit_log_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_note')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id', 'sk_train_cert_account_idx');
            $table->index('certificate_status', 'sk_train_cert_status_idx');
            $table->index('certificate_scope', 'sk_train_cert_scope_idx');
            $table->index('public_badge_status', 'sk_train_cert_public_idx');
            $table->index('issued_by_admin_user_id', 'sk_train_cert_issued_by_idx');
            $table->index('issued_admin_audit_log_id', 'sk_train_cert_issued_audit_idx');
            $table->index('revoked_by_admin_user_id', 'sk_train_cert_revoked_by_idx');
            $table->index('revoked_admin_audit_log_id', 'sk_train_cert_revoked_audit_idx');

            $table->foreign('suchak_account_id', 'sk_train_cert_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('issued_by_admin_user_id', 'sk_train_cert_issued_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('issued_admin_audit_log_id', 'sk_train_cert_issued_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
            $table->foreign('revoked_by_admin_user_id', 'sk_train_cert_revoked_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_admin_audit_log_id', 'sk_train_cert_revoked_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        Schema::create('suchak_message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key', 96)->unique('sk_msg_tpl_key_unique');
            $table->string('template_title', 160);
            $table->string('template_category', 64);
            $table->string('template_channel', 40)->default(SuchakMessageTemplate::CHANNEL_WHATSAPP_COPY);
            $table->string('template_status', 32)->default(SuchakMessageTemplate::STATUS_ACTIVE);
            $table->string('policy_status', 32)->default(SuchakMessageTemplate::POLICY_SAFE);
            $table->text('body_text');
            $table->text('usage_guidance')->nullable();
            $table->unsignedBigInteger('created_by_admin_user_id');
            $table->unsignedBigInteger('admin_audit_log_id')->nullable();
            $table->timestamps();

            $table->index('template_category', 'sk_msg_tpl_category_idx');
            $table->index('template_channel', 'sk_msg_tpl_channel_idx');
            $table->index('template_status', 'sk_msg_tpl_status_idx');
            $table->index('policy_status', 'sk_msg_tpl_policy_idx');
            $table->index('created_by_admin_user_id', 'sk_msg_tpl_admin_idx');
            $table->index('admin_audit_log_id', 'sk_msg_tpl_audit_idx');

            $table->foreign('created_by_admin_user_id', 'sk_msg_tpl_admin_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('admin_audit_log_id', 'sk_msg_tpl_audit_fk')->references('id')->on('admin_audit_logs')->restrictOnDelete();
        });

        Schema::create('suchak_message_template_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->unsignedBigInteger('message_template_id');
            $table->unsignedBigInteger('used_by_user_id');
            $table->string('usage_context', 64);
            $table->text('rendered_body');
            $table->json('metadata_json')->nullable();
            $table->timestamp('used_at');
            $table->timestamps();

            $table->index('suchak_account_id', 'sk_msg_tpl_usage_account_idx');
            $table->index('message_template_id', 'sk_msg_tpl_usage_template_idx');
            $table->index('used_by_user_id', 'sk_msg_tpl_usage_user_idx');
            $table->index('usage_context', 'sk_msg_tpl_usage_context_idx');
            $table->index('used_at', 'sk_msg_tpl_usage_used_idx');

            $table->foreign('suchak_account_id', 'sk_msg_tpl_usage_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('message_template_id', 'sk_msg_tpl_usage_template_fk')->references('id')->on('suchak_message_templates')->restrictOnDelete();
            $table->foreign('used_by_user_id', 'sk_msg_tpl_usage_user_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_message_template_usages');
        Schema::dropIfExists('suchak_message_templates');
        Schema::dropIfExists('suchak_training_certificates');
        Schema::dropIfExists('suchak_training_completions');
        Schema::dropIfExists('suchak_training_modules');
    }
};
