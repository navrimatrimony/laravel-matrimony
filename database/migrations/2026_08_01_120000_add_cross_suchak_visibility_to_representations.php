<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-field control over what OTHER Suchaks are allowed to see about a
 * represented candidate.
 *
 * Sits on the representation rather than on the profile because the decision
 * belongs to the relationship, not to the person: the same candidate may be
 * represented by two Suchaks who were told different things, and a profile-level
 * flag would silently let one Suchak's choice leak the other's data.
 *
 * Every flag defaults to false, so a representation that predates this decision
 * — or whose Suchak has not answered yet — shares nothing. Sharing must always
 * be something somebody switched on, never something they forgot to switch off.
 *
 * shared_display_name is the name to show when shares_name is off: it lets a
 * Suchak circulate a candidate under an alias instead of forcing an all-or-
 * nothing choice between the real name and no listing at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_profile_representations', function (Blueprint $table): void {
            if (! Schema::hasColumn('suchak_profile_representations', 'shares_name')) {
                $table->boolean('shares_name')
                    ->default(false)
                    ->after('consent_is_suchak_declared');
            }

            if (! Schema::hasColumn('suchak_profile_representations', 'shares_village')) {
                $table->boolean('shares_village')
                    ->default(false)
                    ->after('shares_name');
            }

            if (! Schema::hasColumn('suchak_profile_representations', 'shares_detailed_address')) {
                $table->boolean('shares_detailed_address')
                    ->default(false)
                    ->after('shares_village');
            }

            if (! Schema::hasColumn('suchak_profile_representations', 'shares_mobile')) {
                $table->boolean('shares_mobile')
                    ->default(false)
                    ->after('shares_detailed_address');
            }

            if (! Schema::hasColumn('suchak_profile_representations', 'shared_display_name')) {
                $table->string('shared_display_name', 120)
                    ->nullable()
                    ->after('shares_mobile');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suchak_profile_representations', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'shares_name',
                'shares_village',
                'shares_detailed_address',
                'shares_mobile',
                'shared_display_name',
            ], static fn (string $column): bool => Schema::hasColumn('suchak_profile_representations', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
