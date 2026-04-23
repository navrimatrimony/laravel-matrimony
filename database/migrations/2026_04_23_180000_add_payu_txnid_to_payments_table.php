<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'payu_txnid')) {
                $table->string('payu_txnid', 128)->nullable()->after('txnid');
                $table->index('payu_txnid');
            }
        });

        if (Schema::hasColumn('payments', 'payu_txnid') && Schema::hasColumn('payments', 'txnid')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::table('payments')->whereNull('payu_txnid')->whereNotNull('txnid')->update([
                    'payu_txnid' => DB::raw('txnid'),
                ]);
            } else {
                foreach (DB::table('payments')->whereNull('payu_txnid')->whereNotNull('txnid')->cursor() as $row) {
                    DB::table('payments')->where('id', $row->id)->update(['payu_txnid' => (string) $row->txnid]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'payu_txnid')) {
                $table->dropIndex(['payu_txnid']);
                $table->dropColumn('payu_txnid');
            }
        });
    }
};
