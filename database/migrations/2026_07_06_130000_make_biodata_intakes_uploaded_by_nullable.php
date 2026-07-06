<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('biodata_intakes', 'uploaded_by')) {
            return;
        }

        if (! $this->usesSQLite()) {
            Schema::table('biodata_intakes', function (Blueprint $table): void {
                $table->dropForeign(['uploaded_by']);
            });
        }

        Schema::table('biodata_intakes', function (Blueprint $table): void {
            $table->unsignedBigInteger('uploaded_by')->nullable()->change();
        });

        if (! $this->usesSQLite()) {
            Schema::table('biodata_intakes', function (Blueprint $table): void {
                $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('biodata_intakes', 'uploaded_by')) {
            return;
        }

        $unclaimedCount = DB::table('biodata_intakes')->whereNull('uploaded_by')->count();
        if ($unclaimedCount > 0) {
            throw new RuntimeException('Cannot make biodata_intakes.uploaded_by NOT NULL while unclaimed intake rows exist.');
        }

        if (! $this->usesSQLite()) {
            Schema::table('biodata_intakes', function (Blueprint $table): void {
                $table->dropForeign(['uploaded_by']);
            });
        }

        Schema::table('biodata_intakes', function (Blueprint $table): void {
            $table->unsignedBigInteger('uploaded_by')->nullable(false)->change();
        });

        if (! $this->usesSQLite()) {
            Schema::table('biodata_intakes', function (Blueprint $table): void {
                $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    private function usesSQLite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
