<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_qr_tokens', function (Blueprint $table): void {
            $table->timestamp('revoked_at')->nullable()->after('last_scanned_at');
            $table->string('revoked_reason', 120)->nullable()->after('revoked_at');
            $table->unsignedBigInteger('replaced_by_token_id')->nullable()->after('revoked_reason');

            $table->index('revoked_at', 'suchak_qr_revoked_at_idx');
            $table->index('replaced_by_token_id', 'suchak_qr_replaced_by_token_idx');
        });
    }

    public function down(): void
    {
        Schema::table('suchak_qr_tokens', function (Blueprint $table): void {
            $table->dropIndex('suchak_qr_revoked_at_idx');
            $table->dropIndex('suchak_qr_replaced_by_token_idx');
            $table->dropColumn([
                'revoked_at',
                'revoked_reason',
                'replaced_by_token_id',
            ]);
        });
    }
};
