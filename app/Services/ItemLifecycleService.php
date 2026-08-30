<?php

namespace App\Services;

use App\Models\Item;
use App\Models\User;
use App\Support\LoyaltyRules;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Admin-side item lifecycle: negotiation, physical turnover, seller payout and
 * publication.
 *
 * The three prices stay strictly separate throughout - what the student asked,
 * what Admin agreed to pay, and what a buyer pays - and the markup is always
 * derived from the last two rather than stored.
 *
 * Seller payout and buyer reward points never meet in this class. A seller is
 * paid cash for handing an item over; a buyer earns points for completing a
 * purchase. They are different people, different money, different triggers.
 */
class ItemLifecycleService
{
    /**
     * Record the price Admin and the seller settled on in chat.
     *
     * Kept separate from the asking price so the negotiation is auditable:
     * a student may have asked PHP 200 and agreed to PHP 180.
     */
    public function recordAcquisitionPrice(Item $item, Money $acquisitionPrice): Item
    {
        if ($acquisitionPrice->isNegative()) {
            throw new RuntimeException('Acquisition price cannot be negative.');
        }

        if ($item->status === Item::STATUS_SOLD) {
            throw new RuntimeException('This item has already been sold.');
        }

        $item->update(['acquisition_price' => $acquisitionPrice->toDecimalString()]);

        return $item->fresh();
    }

    /** Note the agreed meeting / physical turnover schedule. */
    public function setMeetupSchedule(Item $item, ?string $schedule): Item
    {
        $item->update(['meetup_schedule' => $schedule]);

        return $item->fresh();
    }

    /**
     * Ofelia/Admin has physically received and verified the item.
     *
     * This is what entitles the student seller to their cash, so it records
     * who verified it, when, and the exact amount payable. The payout itself
     * is marked separately once the cash actually changes hands.
     */
    public function verifyTurnover(Item $item, User $admin, ?Money $payoutAmount = null, ?string $notes = null): Item
    {
        if ($item->status === Item::STATUS_SOLD) {
            throw new RuntimeException('This item has already been sold.');
        }

        $acquisition = $item->acquisitionPrice();

        if ($acquisition === null) {
            throw new RuntimeException(
                'Record the negotiated acquisition price before verifying turnover.'
            );
        }

        // Absent an explicit figure, the seller is owed what was agreed.
        $payout = $payoutAmount ?? $acquisition;

        if ($payout->isNegative()) {
            throw new RuntimeException('Seller payout cannot be negative.');
        }

        $item->update([
            'status' => Item::STATUS_ACQUIRED,
            'acquired_at' => now(),
            'acquired_by' => $admin->user_id,
            'turnover_notes' => $notes,
            'seller_payout_amount' => $payout->toDecimalString(),
            'seller_payout_status' => $item->seller_payout_status === Item::PAYOUT_PAID
                ? Item::PAYOUT_PAID
                : Item::PAYOUT_UNPAID,
        ]);

        return $item->fresh();
    }

    /**
     * Mark the student seller as paid in cash.
     *
     * Deliberately not a points transfer: this replaces the old
     * "Send Points & Finalize" flow, which moved wallet points from the admin
     * to the seller and polluted the buyer transaction table.
     */
    public function recordSellerPayout(Item $item, User $admin, ?Money $amount = null): Item
    {
        if (!$item->isTurnoverVerified()) {
            throw new RuntimeException(
                'The item must be received and verified before the seller is paid.'
            );
        }

        if ($item->seller_payout_status === Item::PAYOUT_PAID) {
            // Already settled - returning as-is keeps a repeated tap harmless.
            return $item;
        }

        $payout = $amount
            ?? ($item->seller_payout_amount === null ? null : Money::fromPesos($item->seller_payout_amount))
            ?? $item->acquisitionPrice();

        if ($payout === null) {
            throw new RuntimeException('No payout amount is on record for this item.');
        }

        $item->update([
            'seller_payout_status' => Item::PAYOUT_PAID,
            'seller_payout_amount' => $payout->toDecimalString(),
            'seller_paid_at' => now(),
            'seller_paid_by' => $admin->user_id,
        ]);

        return $item->fresh();
    }

    /**
     * What publishing at a given price would mean, without committing to it.
     *
     * Backs the Admin reward preview, so the number shown before publishing is
     * produced by the same code that computes the real one.
     *
     * @return array{
     *     public_price: Money, acquisition_price: ?Money, markup: ?Money,
     *     reward_points: int, can_publish: bool, blockers: list<string>
     * }
     */
    public function previewPublication(Item $item, Money $publicPrice): array
    {
        $acquisition = $item->acquisitionPrice();
        $blockers = [];

        if (!$item->isTurnoverVerified()) {
            $blockers[] = 'Physical turnover has not been verified yet.';
        }

        if ($acquisition === null) {
            $blockers[] = 'No acquisition price has been recorded.';
        }

        if (!$publicPrice->isPositive()) {
            $blockers[] = 'The public selling price must be greater than zero.';
        }

        if ($item->status === Item::STATUS_SOLD) {
            $blockers[] = 'This item has already been sold.';
        }

        return [
            'public_price' => $publicPrice,
            'acquisition_price' => $acquisition,
            'markup' => $acquisition === null ? null : $publicPrice->minus($acquisition),
            'reward_points' => LoyaltyRules::rewardPointsFor($publicPrice),
            'can_publish' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Publish to the buyer catalog.
     *
     * Gated on turnover being verified and an acquisition price being on
     * record, so an item can never go on sale before it physically exists in
     * Admin's hands at a known cost.
     */
    public function publish(Item $item, Money $publicPrice, User $admin): Item
    {
        $preview = $this->previewPublication($item, $publicPrice);

        if (!$preview['can_publish']) {
            throw new RuntimeException(implode(' ', $preview['blockers']));
        }

        return DB::transaction(function () use ($item, $publicPrice, $admin, $preview) {
            $item->update([
                'public_price' => $publicPrice->toDecimalString(),
                'reward_points' => $preview['reward_points'],
                'status' => Item::STATUS_PUBLIC,
                'published_at' => $item->published_at ?? now(),
                'published_by' => $admin->user_id,
                // Once Admin has set a real peso price the legacy point value
                // is no longer the source of truth for this row.
                'price_source' => 'cash',
                // Keep the legacy column meaningful for older app builds: it
                // always served as the buyer-facing number, so mirror the peso
                // price into it rather than leaving a stale point value behind.
                'markup_points' => intdiv($publicPrice->centavos(), 100),
            ]);

            return $item->fresh();
        });
    }

    /** Change the price of an already published item. */
    public function updatePublicPrice(Item $item, Money $publicPrice, User $admin): Item
    {
        if ($item->status !== Item::STATUS_PUBLIC) {
            throw new RuntimeException('Only a published item can be repriced.');
        }

        return $this->publish($item, $publicPrice, $admin);
    }

    /** Take an item off the catalog without deleting it. */
    public function unpublish(Item $item): Item
    {
        if ($item->status !== Item::STATUS_PUBLIC) {
            throw new RuntimeException('Only a published item can be unpublished.');
        }

        $item->update(['status' => Item::STATUS_ACQUIRED]);

        return $item->fresh();
    }

    /** Admin declines a pending submission. */
    public function reject(Item $item, string $reason): Item
    {
        if (!$item->isPending()) {
            throw new RuntimeException('Only a pending item can be rejected.');
        }

        $item->update([
            'status' => Item::STATUS_REJECTED,
            'rejected_reason' => $reason,
        ]);

        return $item->fresh();
    }
}
