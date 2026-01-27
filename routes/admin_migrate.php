<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| ONE-TIME BOOTSTRAP MIGRATION ROUTE
|--------------------------------------------------------------------------
| Purpose: Run migrations on shared hosting (no SSH, no admin yet)
| Security: Secret token in URL
| MUST BE DELETED AFTER SUCCESS
|--------------------------------------------------------------------------
*/

Route::get('/_run_migrations_987654', function () {

    // 🔐 SECRET TOKEN CHECK (temporary)
    $key = request()->query('key');

    if ($key !== 'MIGRATE_ONLY_ONCE_2026') {
        abort(403, 'Unauthorized');
    }

    // 🚀 Run migrations safely
    Artisan::call('migrate', [
        '--force' => true,
    ]);

    return "<pre>MIGRATION COMPLETED SUCCESSFULLY\n\n" . Artisan::output() . "</pre>";
});
