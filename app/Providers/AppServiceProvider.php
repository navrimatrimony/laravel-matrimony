<?php

namespace App\Providers;

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
    }
}
