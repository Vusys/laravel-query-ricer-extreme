<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class QueryRicerExtremeServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/query-ricer-extreme.php', 'query-ricer-extreme');

        $this->app->singleton(IdentityMapStore::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/query-ricer-extreme.php' => config_path('query-ricer-extreme.php'),
            ], 'query-ricer-extreme-config');
        }

        $this->registerLifecycleHooks();
    }

    private function registerLifecycleHooks(): void
    {
        if ($this->app->bound(HttpKernel::class)) {
            $this->app->terminating(function (): void {
                $this->app->make(IdentityMapStore::class)->flush();
            });
        }

        Event::listen(JobProcessing::class, function (): void {
            $this->app->make(IdentityMapStore::class)->flush();
        });

        Event::listen(JobProcessed::class, function (): void {
            $this->app->make(IdentityMapStore::class)->flush();
        });

        Event::listen(JobFailed::class, function (): void {
            $this->app->make(IdentityMapStore::class)->flush();
        });
    }
}
