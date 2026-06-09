<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('suchak_name');
            $table->string('office_name')->nullable();
            $table->string('business_type')->default('individual');
            $table->string('mobile_number')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('email')->nullable();
            $table->text('address_line')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('taluka_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->string('verification_status')->default('pending');
            $table->string('public_status')->default('hidden');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('verification_status');
            $table->index('public_status');
            $table->index('district_id');
            $table->index('city_id');
            $table->index('created_at');

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

            if (Schema::hasTable('cities')) {
                $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            }

            if (Schema::hasTable('talukas')) {
                $table->foreign('taluka_id')->references('id')->on('talukas')->nullOnDelete();
            }

            if (Schema::hasTable('districts')) {
                $table->foreign('district_id')->references('id')->on('districts')->nullOnDelete();
            }

            if (Schema::hasTable('states')) {
                $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            }
        });

        Schema::create('suchak_verification_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('verification_type');
            $table->string('document_path')->nullable();
            $table->string('admin_status')->default('pending');
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index('suchak_account_id');
            $table->index('admin_status');
            $table->index('verification_type');
            $table->index('admin_user_id');
            $table->index('created_at');

            $table->foreign('suchak_account_id')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('admin_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('suchak_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('policy_key');
            $table->text('policy_value');
            $table->string('value_type')->default('string');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('policy_key');
            $table->index('is_active');
        });

        $now = now();

        DB::table('suchak_policies')->upsert([
            [
                'policy_key' => 'default_consent_validity_months',
                'policy_value' => '12',
                'value_type' => 'integer',
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'allow_two_year_consent',
                'policy_value' => 'true',
                'value_type' => 'boolean',
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'allow_until_revoked_consent',
                'policy_value' => 'true',
                'value_type' => 'boolean',
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'request_action_sla_hours',
                'policy_value' => '48',
                'value_type' => 'integer',
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'collaboration_sla_days',
                'policy_value' => '7',
                'value_type' => 'integer',
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'pdf_download_limit_per_day',
                'policy_value' => '20',
                'value_type' => 'integer',
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'qr_token_expiry_days',
                'policy_value' => '30',
                'value_type' => 'integer',
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'suchak_upload_daily_limit',
                'policy_value' => '25',
                'value_type' => 'integer',
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'policy_key' => 'suchak_active_profile_limit_by_plan',
                'policy_value' => '0',
                'value_type' => 'integer',
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['policy_key'], ['policy_value', 'value_type', 'description', 'is_active', 'updated_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_policies');
        Schema::dropIfExists('suchak_verification_records');
        Schema::dropIfExists('suchak_accounts');
    }
};
