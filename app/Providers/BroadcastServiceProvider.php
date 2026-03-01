<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load channel definitions
        // Note: Broadcasting auth routes are defined in routes/api.php to ensure Sanctum authentication
        require base_path('routes/channels.php');
    }
}
