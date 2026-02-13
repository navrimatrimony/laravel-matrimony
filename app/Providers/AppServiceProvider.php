<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::bind('profile', function ($value) {
            return \App\Models\MatrimonyProfile::withTrashed()->findOrFail($value);
        });

        /*
        |----------------------------------------------------------------------
        | Notification Count View Composer (SSOT Hygiene)
        |----------------------------------------------------------------------
        | Provides $unreadNotificationCount to navigation layout.
        | Removes direct DB queries from Blade views.
        */
        View::composer('layouts.navigation', function ($view) {
            $count = 0;
            if (auth()->check()) {
                $count = auth()->user()->unreadNotifications()->count();
            }
            $view->with('unreadNotificationCount', $count);
        });

        /*
        |----------------------------------------------------------------------
        | Admin Layout View Composer (SSOT — capability resolution)
        |----------------------------------------------------------------------
        | Provides admin capability variables to layouts.admin.
        */
        View::composer('layouts.admin', function ($view) {
            $adminUser = auth()->user();
            $isAdminUser = $adminUser && (method_exists($adminUser, 'isAnyAdmin') ? $adminUser->isAnyAdmin() : $adminUser->is_admin === true);
            $isSuperAdmin = $isAdminUser && method_exists($adminUser, 'isSuperAdmin') && $adminUser->isSuperAdmin();
            $adminCapabilities = null;
            if ($isAdminUser && !$isSuperAdmin) {
                $adminCapabilities = DB::table('admin_capabilities')->where('admin_id', $adminUser->id)->first();
            }
            $canManageVerificationTags = $isAdminUser && ($isSuperAdmin || ($adminCapabilities && $adminCapabilities->can_manage_verification_tags));
            $canManageSeriousIntents = $isAdminUser && ($isSuperAdmin || ($adminCapabilities && $adminCapabilities->can_manage_serious_intents));
            $view->with(compact('adminUser', 'isAdminUser', 'isSuperAdmin', 'canManageVerificationTags', 'canManageSeriousIntents'));
        });
    }
}
