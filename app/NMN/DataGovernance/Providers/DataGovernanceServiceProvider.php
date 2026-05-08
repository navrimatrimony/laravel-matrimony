<?php

namespace NMN\DataGovernance\Providers;

use App\Console\Commands\DataAuditCleanupCommand;
use App\Console\Commands\DataAuditCompareCommand;
use App\Console\Commands\DataAuditNotifyCommand;
use App\Console\Commands\DataAuditSnapshotCommand;
use App\Services\DataAudit\EntityAdapterRegistry;
use App\Services\DataAudit\SnapshotStorageService;
use Illuminate\Support\ServiceProvider;

class DataGovernanceServiceProvider extends ServiceProvider
{
    private function packageBasePath(): string
    {
        return base_path('packages/nmn/data-governance');
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->packageBasePath().'/config/platform.php', 'data-governance.platform');
        $this->mergeConfigFrom($this->packageBasePath().'/config/suppressions.php', 'data-governance.suppressions');
        $this->mergeConfigFrom($this->packageBasePath().'/config/entities.php', 'data-governance.entities');

        $this->app->singleton(EntityAdapterRegistry::class);
        $this->app->singleton(SnapshotStorageService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom($this->packageBasePath().'/resources/views', 'data-governance');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DataAuditSnapshotCommand::class,
                DataAuditCompareCommand::class,
                DataAuditCleanupCommand::class,
                DataAuditNotifyCommand::class,
            ]);
        }
    }
}

