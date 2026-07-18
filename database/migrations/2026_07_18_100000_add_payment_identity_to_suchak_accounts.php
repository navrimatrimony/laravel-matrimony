<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goal 3 D1 (PO approved): Track A Suchak payment identity on existing suchak_accounts.
 * Does not touch platform/PayU (Track B) tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suchak_accounts')) {
            return;
        }

        Schema::table('suchak_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('suchak_accounts', 'upi_vpa')) {
                $table->string('upi_vpa', 191)->nullable()->after('profile_photo_path');
            }
            if (! Schema::hasColumn('suchak_accounts', 'payment_qr_path')) {
                $table->string('payment_qr_path')->nullable()->after('upi_vpa');
            }
            if (! Schema::hasColumn('suchak_accounts', 'payment_qr_updated_at')) {
                $table->timestamp('payment_qr_updated_at')->nullable()->after('payment_qr_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('suchak_accounts')) {
            return;
        }

        Schema::table('suchak_accounts', function (Blueprint $table): void {
            $columns = [];
            foreach (['upi_vpa', 'payment_qr_path', 'payment_qr_updated_at'] as $column) {
                if (Schema::hasColumn('suchak_accounts', $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
