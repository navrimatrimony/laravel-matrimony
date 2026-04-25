<?php

namespace App\Providers;

use App\Models\MatrimonyProfile;
use App\Models\SystemRule;
use App\Observers\MatrimonyProfileObserver;
use App\Observers\SystemRuleObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->singleton(\App\Services\ProfileCompletionEngine::class);
        $this->app->singleton(\App\Services\MatchingEngine::class);
        $this->app->singleton(\App\Services\Matching\MatchingPresenter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        MatrimonyProfile::observe(MatrimonyProfileObserver::class);
        SystemRule::observe(SystemRuleObserver::class);
        // Guard against misconfiguration: Sarvam structured parser must use Sarvam M only.

        $envSarvamStructured = strtolower(trim((string) env('INTAKE_SARVAM_STRUCTURED_MODEL', '')));
        if ($envSarvamStructured !== '' && $envSarvamStructured !== 'sarvam-m') {
            Log::error('Invalid Sarvam structured model configured: '.$envSarvamStructured.'. Expected: sarvam-m');
        }
        $cfgSarvamStructured = strtolower(trim((string) config('intake.sarvam_structured.model', 'sarvam-m')));
        if ($cfgSarvamStructured !== 'sarvam-m') {
            Log::error('Invalid Sarvam structured model in config: '.$cfgSarvamStructured.'. Expected: sarvam-m');
        }

        RateLimiter::for('location-gps', function (Request $request) {
            return Limit::perMinute(2)->by($request->user()?->id ?: $request->ip());
        });

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
            $memberActivityCounts = [
                'interests_pending' => 0,
                'who_viewed_count' => 0,
            ];
            if (auth()->check()) {
                try {
                    $count = auth()->user()->unreadNotifications()->count();
                    $memberActivityCounts = app(\App\Services\MemberQuickHubService::class)
                        ->buildActivityCountsForUser(auth()->user());
                } catch (\Throwable $e) {
                    Log::warning('Navigation view composer fallback due to runtime exception', [
                        'user_id' => (int) auth()->id(),
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
            $view->with('unreadNotificationCount', $count);
            $view->with('memberActivityCounts', $memberActivityCounts);
        });

        /*
        |----------------------------------------------------------------------
        | Plan usage summary (member layouts — dashboard strip + full card)
        |----------------------------------------------------------------------
        */
        View::composer(['layouts.app', 'dashboard'], function ($view) {
            $planUsageSummary = null;
            $chatDockData = null;
            $memberActivityCounts = [
                'interests_pending' => 0,
                'who_viewed_count' => 0,
            ];
            if (auth()->check() && auth()->user()->matrimonyProfile) {
                try {
                    $planUsageSummary = app(\App\Services\QuotaEngineService::class)
                        ->getUserQuotaSummary(auth()->user());
                    $chatDockData = app(\App\Services\MemberQuickHubService::class)
                        ->buildChatDockForUser(auth()->user());
                    $memberActivityCounts = app(\App\Services\MemberQuickHubService::class)
                        ->buildActivityCountsForUser(auth()->user());
                } catch (\Throwable $e) {
                    Log::warning('Member layout composer fallback due to runtime exception', [
                        'user_id' => (int) auth()->id(),
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
            $view->with('planUsageSummary', $planUsageSummary);
            $view->with('chatDockData', $chatDockData);
            $view->with('memberActivityCounts', $memberActivityCounts);
        });

        /*
        |----------------------------------------------------------------------
        | Admin Layout View Composer (SSOT — capability resolution)
        |----------------------------------------------------------------------
        | Provides admin capability variables to layouts.admin.
        */
        View::composer('public.welcome', function ($view) {
            try {
                $service = app(\App\Services\Admin\HomepageImageService::class);
                $urls = [];
                foreach (array_keys(\App\Models\HomepageSectionImage::SECTIONS) as $key) {
                    $urls[$key] = $service->url($key);
                }
                $view->with('homepageImages', $urls);
            } catch (\Throwable $e) {
                $view->with('homepageImages', []);
            }
        });

        View::composer('layouts.admin', function ($view) {
            $adminUser = auth()->user();
            $isAdminUser = $adminUser && (method_exists($adminUser, 'isAnyAdmin') ? $adminUser->isAnyAdmin() : $adminUser->is_admin === true);
            $isSuperAdmin = $isAdminUser && method_exists($adminUser, 'isSuperAdmin') && $adminUser->isSuperAdmin();
            $adminCapabilities = null;
            if ($isAdminUser && ! $isSuperAdmin) {
                $adminCapabilities = DB::table('admin_capabilities')->where('admin_id', $adminUser->id)->first();
            }
            $canManageVerificationTags = $isAdminUser && ($isSuperAdmin || ($adminCapabilities && $adminCapabilities->can_manage_verification_tags));
            $canManageSeriousIntents = $isAdminUser && ($isSuperAdmin || ($adminCapabilities && $adminCapabilities->can_manage_serious_intents));
            $view->with(compact('adminUser', 'isAdminUser', 'isSuperAdmin', 'canManageVerificationTags', 'canManageSeriousIntents'));
        });

    }
}
