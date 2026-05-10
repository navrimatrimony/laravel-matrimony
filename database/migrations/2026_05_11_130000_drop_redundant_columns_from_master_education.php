<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * {@code master_education}: {@code title} duplicated {@code code}; {@code title_mr} duplicated {@code code_mr};
 * {@code full_form_mr} unused / corrupted in practice. Product uses {@code code}, optional {@code code_mr}, and {@code full_form} (EN).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_education')) {
            return;
        }
        $drop = array_values(array_filter(
            ['title', 'title_mr', 'full_form_mr'],
            fn (string $c) => Schema::hasColumn('master_education', $c)
        ));
        if ($drop === []) {
            return;
        }
        Schema::table('master_education', function (Blueprint $table) use ($drop) {
            $table->dropColumn($drop);
        });
    }

    public function down(): void
    {
        // Intentionally empty: restoring dropped text is not supported.
    }
};
