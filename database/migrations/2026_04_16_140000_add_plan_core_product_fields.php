<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'applies_to_gender')) {
                $table->string('applies_to_gender', 16)->default('all')->after('highlight');
            }
            if (! Schema::hasColumn('plans', 'marketing_badge')) {
                $table->string('marketing_badge', 80)->nullable()->after('applies_to_gender');
            }
            if (! Schema::hasColumn('plans', 'duration_quantity')) {
                $table->unsignedSmallInteger('duration_quantity')->nullable()->after('duration_days');
            }
            if (! Schema::hasColumn('plans', 'duration_unit')) {
                $table->string('duration_unit', 16)->nullable()->after('duration_quantity');
            }
            if (! Schema::hasColumn('plans', 'list_price_rupees')) {
                $table->decimal('list_price_rupees', 12, 2)->nullable()->after('price');
            }
            if (! Schema::hasColumn('plans', 'gst_inclusive')) {
                $table->boolean('gst_inclusive')->default(true)->after('list_price_rupees');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            foreach ([
                'applies_to_gender',
                'marketing_badge',
                'duration_quantity',
                'duration_unit',
                'gst_inclusive',
                'list_price_rupees',
            ] as $col) {
                if (Schema::hasColumn('plans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
