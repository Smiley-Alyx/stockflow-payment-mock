<?php

namespace App\Providers;

use App\Domain\Payment\Services\Sandbox\SandboxCardBehaviorEvaluator;
use App\Domain\Payment\Services\Sandbox\SandboxCardCatalog;
use App\Domain\Payment\Services\Sandbox\SandboxPaymentMethodResolver;
use App\Domain\Payment\Services\Sandbox\SandboxTestCardTokenizer;
use App\Domain\Payment\Services\Sandbox\SensitiveDataMasker;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SandboxCardCatalog::class);
        $this->app->singleton(SensitiveDataMasker::class);
        $this->app->singleton(SandboxPaymentMethodResolver::class);
        $this->app->singleton(SandboxCardBehaviorEvaluator::class);
        $this->app->singleton(SandboxTestCardTokenizer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
