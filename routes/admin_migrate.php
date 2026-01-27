<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/_run_migrations_987654', function () {

    // 🔐 BASIC SAFETY CHECK (logged-in admin only)
    if (!auth()->check() || !auth()->user()->is_admin) {
        abort(403, 'Unauthorized');
    }

    // 🧠 Run migrations safely
    Artisan::call('migrate', [
        '--force' => true,
    ]);

    return "<pre>Migration completed successfully.\n\n" . Artisan::output() . "</pre>";
});

