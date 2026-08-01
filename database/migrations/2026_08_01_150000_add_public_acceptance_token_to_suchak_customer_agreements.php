<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a customer accept a price agreement from a link, with no account.
 *
 * The families these agreements are quoted to are not platform users and will
 * not create an account to say yes, so acceptance has to be provable without an
 * identity to attach it to. Possession of a single-use secret link becomes the
 * proof, which is why the hash — never the token — is stored: a leaked database
 * dump must not yield a working acceptance link.
 *
 * accepted_by_user_id deliberately stays NULL on this path. There is no verified
 * identity behind a public acceptance, and filling it with the Suchak or any
 * other convenient user would record a person as having accepted terms they
 * never saw. What actually happened is all that is kept here: the typed name,
 * the IP, the user agent, and the moment the link was spent.
 *
 * Same expiry-and-single-use shape as suchak_consents on purpose — one idea, one
 * mechanism — so the two public links cannot drift apart in how they age out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_customer_agreements', function (Blueprint $table): void {
            if (! Schema::hasColumn('suchak_customer_agreements', 'acceptance_token_hash')) {
                $table->string('acceptance_token_hash', 64)
                    ->nullable()
                    ->after('accepted_by_user_id');
                $table->unique('acceptance_token_hash', 'sk_agreements_acceptance_token_unique');
            }

            if (! Schema::hasColumn('suchak_customer_agreements', 'acceptance_token_expires_at')) {
                $table->timestamp('acceptance_token_expires_at')
                    ->nullable()
                    ->after('acceptance_token_hash');
                $table->index('acceptance_token_expires_at', 'sk_agreements_acceptance_expiry_idx');
            }

            if (! Schema::hasColumn('suchak_customer_agreements', 'acceptance_token_used_at')) {
                $table->timestamp('acceptance_token_used_at')
                    ->nullable()
                    ->after('acceptance_token_expires_at');
            }

            if (! Schema::hasColumn('suchak_customer_agreements', 'accepted_ip_address')) {
                $table->string('accepted_ip_address', 45)
                    ->nullable()
                    ->after('acceptance_token_used_at');
            }

            if (! Schema::hasColumn('suchak_customer_agreements', 'accepted_user_agent')) {
                $table->string('accepted_user_agent', 512)
                    ->nullable()
                    ->after('accepted_ip_address');
            }

            if (! Schema::hasColumn('suchak_customer_agreements', 'accepted_by_name')) {
                $table->string('accepted_by_name', 160)
                    ->nullable()
                    ->after('accepted_user_agent');
            }
        });
    }

    public function down(): void
    {
        // Indexes go before their columns: SQLite rebuilds the table on a column
        // drop and would carry a dangling index definition into the copy.
        Schema::table('suchak_customer_agreements', function (Blueprint $table): void {
            if (Schema::hasColumn('suchak_customer_agreements', 'acceptance_token_hash')) {
                $table->dropUnique('sk_agreements_acceptance_token_unique');
            }

            if (Schema::hasColumn('suchak_customer_agreements', 'acceptance_token_expires_at')) {
                $table->dropIndex('sk_agreements_acceptance_expiry_idx');
            }
        });

        Schema::table('suchak_customer_agreements', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'acceptance_token_hash',
                'acceptance_token_expires_at',
                'acceptance_token_used_at',
                'accepted_ip_address',
                'accepted_user_agent',
                'accepted_by_name',
            ], static fn (string $column): bool => Schema::hasColumn('suchak_customer_agreements', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
