<?php

use App\Models\SuchakPlatformPayoutSettlement;
use App\Models\SuchakPlatformPayoutSettlementLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_platform_payout_settlements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('statement_number', 96);
            $table->string('statement_month', 7);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('statement_status', 32)->default(SuchakPlatformPayoutSettlement::STATUS_GENERATED);
            $table->unsignedInteger('payout_count')->default(0);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('deduction_amount', 12, 2)->default(0);
            $table->decimal('reversal_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('statement_hash', 64);
            $table->unsignedBigInteger('generated_by_admin_user_id');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique('statement_number', 'sk_payout_settlements_number_unique');
            $table->unique(['suchak_account_id', 'statement_month'], 'sk_payout_settlements_account_month_unique');
            $table->index('suchak_account_id', 'sk_payout_settlements_account_idx');
            $table->index('statement_month', 'sk_payout_settlements_month_idx');
            $table->index('statement_status', 'sk_payout_settlements_status_idx');

            $table->foreign('suchak_account_id', 'sk_payout_settlements_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('generated_by_admin_user_id', 'sk_payout_settlements_admin_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('suchak_platform_payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('settlement_statement_id')->nullable()->after('payout_status');
            $table->decimal('deduction_amount', 12, 2)->default(0)->after('amount');
            $table->decimal('reversal_amount', 12, 2)->default(0)->after('deduction_amount');
            $table->decimal('net_amount', 12, 2)->nullable()->after('reversal_amount');
            $table->unsignedBigInteger('paid_by_user_id')->nullable()->after('approved_at');
            $table->timestamp('paid_at')->nullable()->after('paid_by_user_id');
            $table->string('payout_reference_number', 160)->nullable()->after('paid_at');
            $table->text('payout_reference_note')->nullable()->after('payout_reference_number');

            $table->index('settlement_statement_id', 'sk_platform_payouts_settlement_idx');
            $table->index('paid_by_user_id', 'sk_platform_payouts_paid_by_idx');
            $table->index('paid_at', 'sk_platform_payouts_paid_at_idx');
            $table->unique('payout_reference_number', 'sk_platform_payouts_reference_unique');

            $table->foreign('settlement_statement_id', 'sk_platform_payouts_settlement_fk')->references('id')->on('suchak_platform_payout_settlements')->restrictOnDelete();
            $table->foreign('paid_by_user_id', 'sk_platform_payouts_paid_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('suchak_platform_payout_settlement_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('settlement_statement_id');
            $table->unsignedBigInteger('platform_payout_id');
            $table->unsignedBigInteger('suchak_account_id');
            $table->string('line_type', 32)->default(SuchakPlatformPayoutSettlementLine::TYPE_PAYOUT);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('deduction_amount', 12, 2)->default(0);
            $table->decimal('reversal_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->text('line_note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['settlement_statement_id', 'platform_payout_id'], 'sk_payout_lines_statement_payout_unique');
            $table->index('settlement_statement_id', 'sk_payout_lines_statement_idx');
            $table->index('platform_payout_id', 'sk_payout_lines_payout_idx');
            $table->index('suchak_account_id', 'sk_payout_lines_account_idx');
            $table->index('line_type', 'sk_payout_lines_type_idx');

            $table->foreign('settlement_statement_id', 'sk_payout_lines_statement_fk')->references('id')->on('suchak_platform_payout_settlements')->restrictOnDelete();
            $table->foreign('platform_payout_id', 'sk_payout_lines_payout_fk')->references('id')->on('suchak_platform_payouts')->restrictOnDelete();
            $table->foreign('suchak_account_id', 'sk_payout_lines_account_fk')->references('id')->on('suchak_accounts')->restrictOnDelete();
        });

        Schema::table('suchak_platform_payout_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('settlement_statement_id')->nullable()->after('platform_payout_id');
            $table->index('settlement_statement_id', 'sk_platform_payout_events_statement_idx');
            $table->foreign('settlement_statement_id', 'sk_platform_payout_events_statement_fk')->references('id')->on('suchak_platform_payout_settlements')->restrictOnDelete();
        });

        DB::table('suchak_platform_payouts')
            ->whereNull('net_amount')
            ->orderBy('id')
            ->chunkById(100, function ($payouts): void {
                foreach ($payouts as $payout) {
                    DB::table('suchak_platform_payouts')
                        ->where('id', $payout->id)
                        ->update([
                            'net_amount' => number_format(max(0, (float) $payout->amount - (float) $payout->deduction_amount - (float) $payout->reversal_amount), 2, '.', ''),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('suchak_platform_payout_events', function (Blueprint $table): void {
            $table->dropForeign('sk_platform_payout_events_statement_fk');
            $table->dropIndex('sk_platform_payout_events_statement_idx');
            $table->dropColumn('settlement_statement_id');
        });

        Schema::dropIfExists('suchak_platform_payout_settlement_lines');

        Schema::table('suchak_platform_payouts', function (Blueprint $table): void {
            $table->dropForeign('sk_platform_payouts_paid_by_fk');
            $table->dropForeign('sk_platform_payouts_settlement_fk');
            $table->dropUnique('sk_platform_payouts_reference_unique');
            $table->dropIndex('sk_platform_payouts_paid_at_idx');
            $table->dropIndex('sk_platform_payouts_paid_by_idx');
            $table->dropIndex('sk_platform_payouts_settlement_idx');
            $table->dropColumn([
                'settlement_statement_id',
                'deduction_amount',
                'reversal_amount',
                'net_amount',
                'paid_by_user_id',
                'paid_at',
                'payout_reference_number',
                'payout_reference_note',
            ]);
        });

        Schema::dropIfExists('suchak_platform_payout_settlements');
    }
};
