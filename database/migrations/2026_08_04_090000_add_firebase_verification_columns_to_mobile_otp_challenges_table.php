<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let `mobile_otp_challenges` record a Firebase Phone Auth verification.
 *
 * The table already owns "a mobile verification event, its channel, purpose and
 * verified_at", so a Firebase verification belongs on it rather than in a second
 * table. Two things had to give:
 *
 *  - `otp_hash` becomes nullable. A Firebase verification never sent a code, so
 *    there is no hash; writing a fake one would be a lie in the audit trail. A
 *    null hash also cannot be matched by the OTP verifier, which is the correct
 *    behaviour — these rows are not OTP challenges anyone may complete.
 *  - `provider_uid` is added to carry the Firebase uid, so a verification can be
 *    traced back to the identity that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mobile_otp_challenges')) {
            return;
        }

        Schema::table('mobile_otp_challenges', function (Blueprint $table): void {
            if (! Schema::hasColumn('mobile_otp_challenges', 'provider_uid')) {
                $table->string('provider_uid', 191)->nullable()->after('channel')->index();
            }

            // Laravel 12 changes columns natively — no doctrine/dbal needed.
            $table->string('otp_hash')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mobile_otp_challenges')) {
            return;
        }

        Schema::table('mobile_otp_challenges', function (Blueprint $table): void {
            if (Schema::hasColumn('mobile_otp_challenges', 'provider_uid')) {
                $table->dropColumn('provider_uid');
            }
        });
    }
};
