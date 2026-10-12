<?php

namespace App\Providers;

use App\Models\Store;
use App\Services\Shopify\SessionToken;
use App\Services\Usage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Usage::class);

        $this->app->singleton(SessionToken::class, fn () => new SessionToken(
            (string) config('shopify.api_key'),
            (string) config('shopify.api_secret'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Plans and platform settings edited in the Internal Admin override config.
        \Illuminate\Support\Facades\Mail::extend('platform', fn () => new \App\Services\Mail\ProviderTransport(app(\App\Services\Mail\EmailSender::class)));
        \App\Support\Plans::boot();
        \App\Support\PlatformSettings::boot();

        $this->registerUsageMeters();
    }

    /**
     * Plan meters counted from the store's own records.
     */
    private function registerUsageMeters(): void
    {
        $usage = $this->app->make(Usage::class);
        $usage->register('team_members', fn (Store $store) => $store->users()->whereNull('disabled_at')->count());
    }
}
