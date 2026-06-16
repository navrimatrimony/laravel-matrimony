<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_consents', function (Blueprint $table): void {
            if (! Schema::hasColumn('suchak_consents', 'consent_method')) {
                $table->string('consent_method')->nullable()->after('consent_channel')->index();
            }

            if (! Schema::hasColumn('suchak_consents', 'consent_text_version')) {
                $table->string('consent_text_version')->nullable()->after('consent_template_version');
            }

            if (! Schema::hasColumn('suchak_consents', 'consent_giver_relation')) {
                $table->string('consent_giver_relation')->nullable()->after('relationship_to_candidate');
            }

            if (! Schema::hasColumn('suchak_consents', 'intended_mobile')) {
                $table->string('intended_mobile')->nullable()->after('consent_mobile_number')->index();
            }

            if (! Schema::hasColumn('suchak_consents', 'submitted_mobile')) {
                $table->string('submitted_mobile')->nullable()->after('intended_mobile');
            }

            if (! Schema::hasColumn('suchak_consents', 'mobile_match')) {
                $table->boolean('mobile_match')->default(false)->after('submitted_mobile')->index();
            }

            if (! Schema::hasColumn('suchak_consents', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('token_expires_at')->index();
            }

            if (! Schema::hasColumn('suchak_consents', 'public_token_used_at')) {
                $table->timestamp('public_token_used_at')->nullable()->after('used_at');
            }

            if (! Schema::hasColumn('suchak_consents', 'decided_at')) {
                $table->timestamp('decided_at')->nullable()->after('public_token_used_at')->index();
            }

            if (! Schema::hasColumn('suchak_consents', 'proof_file_path')) {
                $table->string('proof_file_path')->nullable()->after('user_agent');
            }

            if (! Schema::hasColumn('suchak_consents', 'proof_original_name')) {
                $table->string('proof_original_name')->nullable()->after('proof_file_path');
            }

            if (! Schema::hasColumn('suchak_consents', 'proof_uploaded_at')) {
                $table->timestamp('proof_uploaded_at')->nullable()->after('proof_original_name');
            }

            if (! Schema::hasColumn('suchak_consents', 'delivery_status')) {
                $table->string('delivery_status')->nullable()->after('proof_uploaded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suchak_consents', function (Blueprint $table): void {
            $columns = [
                'delivery_status',
                'proof_uploaded_at',
                'proof_original_name',
                'proof_file_path',
                'decided_at',
                'public_token_used_at',
                'expires_at',
                'mobile_match',
                'submitted_mobile',
                'intended_mobile',
                'consent_giver_relation',
                'consent_text_version',
                'consent_method',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('suchak_consents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
