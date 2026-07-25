<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-Suchak REUSABLE customer plan presets (mutable, Suchak-owned).
 *
 * This is NOT the send-time model. On send, a chosen plan still materializes
 * into suchak_service_packages via SuchakPackageCatalogService::createCustomPackage
 * with NO FK back to this table. It is also NOT the platform subscription catalog
 * `suchak_plans` (unrelated — same word, different meaning).
 *
 * A NULL preset_key row is a fully custom reusable plan. A 'basic'/'premium'
 * preset_key row is only an OVERRIDE for a code-defined preset in
 * App\Modules\Suchak\Support\SuchakDefaultPlans (price / visibility / order).
 * The presets themselves stay code-defined; DB rows only override or add.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suchak_customer_plans')) {
            return;
        }

        Schema::create('suchak_customer_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('suchak_account_id');
            // NULL = full custom plan; 'basic'/'premium' = override row for a code preset.
            $table->string('preset_key', 32)->nullable();
            $table->string('name', 160)->nullable();
            $table->string('name_mr', 160)->nullable();
            $table->decimal('price_amount', 12, 2)->nullable();
            $table->char('currency', 3)->nullable()->default('INR');
            // App enum via PHP consts (six_months / one_year / till_marriage) — NOT a DB enum.
            $table->string('duration', 32)->nullable();
            // The ONLY json column: a list of services stored as [{name, name_mr}] objects.
            $table->json('services_json')->nullable();
            $table->decimal('per_meeting_fee_amount', 12, 2)->nullable();
            // consts: as_wished / fixed / none
            $table->string('post_marriage_fee_mode', 16)->nullable();
            $table->decimal('post_marriage_fee_amount', 12, 2)->nullable();
            // Optional discount "was" price.
            $table->decimal('original_price_amount', 12, 2)->nullable();
            // Suchak-only note — NEVER sent to a customer / customer-facing carousel.
            $table->text('private_note')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('suchak_account_id')
                ->references('id')
                ->on('suchak_accounts')
                ->cascadeOnDelete();

            // One override row per (suchak, preset_key). Multiple custom rows
            // (preset_key NULL) per suchak are allowed — NULLs are distinct.
            $table->unique(['suchak_account_id', 'preset_key'], 'suchak_customer_plans_suchak_preset_unique');
            $table->index(['suchak_account_id', 'is_visible', 'sort_order'], 'suchak_customer_plans_visible_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_customer_plans');
    }
};
