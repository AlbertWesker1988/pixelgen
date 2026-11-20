<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CostCalculator::class, function () {
            return new CostCalculator((string) config('services.pixelforge.markup'));
        });

        $this->app->singleton(PixelforgeClient::class, function () {
            return new PixelforgeClient(
                baseUrl: (string) config('services.pixelforge.base_url'),
                apiKey: (string) config('services.pixelforge.api_key'),
            );
        });

        $this->app->singleton(WebhookSignature::class, function () {
            return new WebhookSignature((string) config('services.pixelforge.webhook_secret'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::define('access-admin', fn (User $user): bool => (bool) $user->is_admin);
    }
}
