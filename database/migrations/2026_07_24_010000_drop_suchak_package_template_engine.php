<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the dormant Suchak package TEMPLATE engine.
 *
 * The template tables (suchak_package_templates / _stages / _deliverables) were a
 * duplicate of the live "custom package" engine and carried zero rows. This drops
 * the template-linking columns from the live suchak_service_package* tables and then
 * drops the three template tables. The live SuchakServicePackage engine is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // [table, column, foreign-key name, index name] — names come from
        // 2026_06_10_102000_create_suchak_package_rate_card_tables.php (all custom).
        $links = [
            ['suchak_service_packages', 'source_template_id', 'sk_service_pkg_template_fk', 'sk_service_pkg_template_idx'],
            ['suchak_service_package_stages', 'template_stage_id', 'sk_service_pkg_stage_tpl_fk', 'sk_service_pkg_stage_tpl_idx'],
            ['suchak_service_package_deliverables', 'template_deliverable_id', 'sk_service_pkg_deliv_tpl_fk', 'sk_service_pkg_deliv_tpl_idx'],
        ];

        foreach ($links as [$table, $column, $fkName, $indexName]) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($driver, $column, $fkName, $indexName): void {
                if ($driver === 'sqlite') {
                    // SQLite cannot drop a foreign key by name and rejects a column drop
                    // while an index still references it: drop the index, then let the
                    // by-column dropForeign trigger the table rebuild that removes the FK.
                    $blueprint->dropIndex($indexName);
                    $blueprint->dropForeign([$column]);
                    $blueprint->dropColumn($column);
                } else {
                    // MySQL: the FK depends on the index, so the FK must go first. Dropping
                    // the (single-column) column then removes its index automatically.
                    $blueprint->dropForeign($fkName);
                    $blueprint->dropColumn($column);
                }
            });
        }

        // FK-safe order: deliverables reference stages + templates, stages reference templates.
        Schema::dropIfExists('suchak_package_template_deliverables');
        Schema::dropIfExists('suchak_package_template_stages');
        Schema::dropIfExists('suchak_package_templates');
    }

    public function down(): void
    {
        // Best-effort reversal: recreate the three template tables and the nullable
        // linking columns. Foreign keys/indexes are intentionally omitted (the engine
        // is dead code; this only restores enough schema for the migration to reverse).
        if (! Schema::hasTable('suchak_package_templates')) {
            Schema::create('suchak_package_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('template_name', 160);
                $table->string('template_name_mr', 160)->nullable();
                $table->text('template_description')->nullable();
                $table->text('template_description_mr')->nullable();
                $table->decimal('base_price_amount', 12, 2)->nullable();
                $table->string('currency', 3)->nullable();
                $table->string('template_status', 32)->default('approved');
                $table->unsignedBigInteger('created_by_admin_user_id');
                $table->unsignedBigInteger('approved_by_admin_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('suchak_package_template_stages')) {
            Schema::create('suchak_package_template_stages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('package_template_id');
                $table->string('stage_key', 80);
                $table->string('stage_name', 160);
                $table->string('stage_name_mr', 160)->nullable();
                $table->text('stage_description')->nullable();
                $table->text('stage_description_mr')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_required')->default(true);
                $table->unsignedSmallInteger('expected_days')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('suchak_package_template_deliverables')) {
            Schema::create('suchak_package_template_deliverables', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('package_template_id');
                $table->unsignedBigInteger('template_stage_id')->nullable();
                $table->string('deliverable_key', 80);
                $table->string('deliverable_name', 160);
                $table->string('deliverable_name_mr', 160)->nullable();
                $table->text('deliverable_description')->nullable();
                $table->text('deliverable_description_mr')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_required')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('suchak_service_packages') && ! Schema::hasColumn('suchak_service_packages', 'source_template_id')) {
            Schema::table('suchak_service_packages', function (Blueprint $table): void {
                $table->unsignedBigInteger('source_template_id')->nullable();
            });
        }

        if (Schema::hasTable('suchak_service_package_stages') && ! Schema::hasColumn('suchak_service_package_stages', 'template_stage_id')) {
            Schema::table('suchak_service_package_stages', function (Blueprint $table): void {
                $table->unsignedBigInteger('template_stage_id')->nullable();
            });
        }

        if (Schema::hasTable('suchak_service_package_deliverables') && ! Schema::hasColumn('suchak_service_package_deliverables', 'template_deliverable_id')) {
            Schema::table('suchak_service_package_deliverables', function (Blueprint $table): void {
                $table->unsignedBigInteger('template_deliverable_id')->nullable();
            });
        }
    }
};
