<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

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
        // This tells Laravel to force HTTPS if the app is in production OR if the request is coming from ngrok.
        if ($this->app->environment('production') || $this->app->request->header('X-Original-Host')) {
            URL::forceScheme('https');
        }
    }
}
