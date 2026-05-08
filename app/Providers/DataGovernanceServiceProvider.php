<?php

namespace App\Providers;

use App\Console\Commands\DataAuditCleanupCommand;
use App\Console\Commands\DataAuditCompareCommand;
use App\Console\Commands\DataAuditNotifyCommand;
use App\Console\Commands\DataAuditSnapshotCommand;
use App\Services\DataAudit\EntityAdapterRegistry;
use App\Services\DataAudit\SnapshotStorageService;
use Illuminate\Support\ServiceProvider;

class DataGovernanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('data-governance/platform.php'), 'data-governance.platform');
        $this->mergeConfigFrom(config_path('data-governance/suppressions.php'), 'data-governance.suppressions');
        $this->mergeConfigFrom(config_path('data-governance/entities.php'), 'data-governance.entities');

        $this->app->singleton(EntityAdapterRegistry::class);
        $this->app->singleton(SnapshotStorageService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(resource_path('views/admin/data-engine'), 'data-governance');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DataAuditSnapshotCommand::class,
                DataAuditCompareCommand::class,
                DataAuditCleanupCommand::class,
                DataAuditNotifyCommand::class,
            ]);

            $this->publishes([
                config_path('data-governance/platform.php') => config_path('data-governance/platform.php'),
                config_path('data-governance/suppressions.php') => config_path('data-governance/suppressions.php'),
                config_path('data-governance/entities.php') => config_path('data-governance/entities.php'),
            ], 'data-governance-config');
        }
    }
}

