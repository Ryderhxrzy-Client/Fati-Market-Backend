<?php

namespace App\Console\Commands;

use App\Services\CheckoutService;
use Illuminate\Console\Command;

/**
 * Releases checkouts that were never paid for.
 *
 * A checkout holds its item so a second buyer cannot take it. Without this
 * sweep an abandoned checkout would keep the item out of the catalog forever.
 * Any points the buyer redeemed are returned as part of the cancellation.
 */
class ExpireAbandonedCheckouts extends Command
{
    protected $signature = 'checkouts:expire';

    protected $description = 'Release item reservations held by checkouts that were not paid in time';

    public function handle(CheckoutService $checkout): int
    {
        $released = $checkout->expireAbandonedCheckouts();

        $this->info("Released {$released} abandoned checkout(s).");

        return self::SUCCESS;
    }
}
