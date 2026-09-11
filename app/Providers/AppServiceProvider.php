<?php

namespace App\Providers;

use App\Services\FcmCredentials;
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
        // A fresh container has no storage file. Materialize it from the
        // deployment secret before FCM has a chance to send a notification.
        app(FcmCredentials::class)->path();
    }
}
