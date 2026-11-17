<?php

namespace App\Providers;

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, fn (): PixelforgeClient => PixelforgeClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind a reverse proxy whose Host is rewritten (Codespaces/Render),
        // generated URLs must come from APP_URL, not the inbound Host header.
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceRootUrl((string) config('app.url'));
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
