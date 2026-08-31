<?php

namespace App\Http\Resources;

use App\Models\Item;
use App\Support\ItemQr;
use App\Support\LoyaltyRules;

/**
 * Turns an Item into the shape each audience is allowed to see.
 *
 * There are three audiences and they differ in more than cosmetics:
 *
 *  - Buyers see the public peso price and the reward they would earn.
 *  - Student sellers see their own asking price and nothing else about
 *    pricing. In particular a pending listing carries no reward-points value,
 *    because points are a buyer-side loyalty concept and showing one to the
 *    seller is what the old points-as-currency model implied.
 *  - Admin sees every figure, including the derived markup.
 *
 * Every payload also carries the legacy `price_points` / `markup_points` keys
 * so older app builds keep working while the mobile release rolls out.
 */
class ItemPresenter
{
    /** Public catalog and item detail for buyers. */
    public static function forBuyer(Item $item): array
    {
        $publicPrice = $item->publicPrice();
        $rewardPoints = $item->reward_points ?: ($publicPrice ? LoyaltyRules::rewardPointsFor($publicPrice) : 0);

        return array_merge(self::common($item), [
            'price' => $publicPrice?->toDecimalString(),
            'public_price' => $publicPrice?->toDecimalString(),
            'price_formatted' => $publicPrice === null ? null : '₱' . $publicPrice->toFormattedString(),
            'reward_points' => $rewardPoints,
            'reward_label' => "Earn {$rewardPoints} point" . ($rewardPoints === 1 ? '' : 's') . ' after completed purchase',
            'points_redemption_value' => LoyaltyRules::PESOS_PER_REDEEMED_POINT,

            // Legacy keys. markup_points was what old buyer builds rendered as
            // the price, so it mirrors the peso price rather than the profit.
            'price_points' => $item->price_points,
            'markup_points' => $publicPrice === null
                ? $item->markup_points
                : intdiv($publicPrice->centavos(), 100),
        ]);
    }

    /** A student seller looking at their own listing. */
    public static function forSeller(Item $item): array
    {
        $asking = $item->askingPrice();

        $payload = array_merge(self::common($item), [
            'seller_asking_price' => $asking->toDecimalString(),
            'seller_asking_price_formatted' => '₱' . $asking->toFormattedString(),
            'seller_payout_status' => $item->seller_payout_status,
            'seller_payout_amount' => $item->seller_payout_amount,
            'acquired_at' => $item->acquired_at,
            'rejected_reason' => $item->rejected_reason,
            'meetup_schedule' => $item->meetup_schedule,

            // Whether Admin has agreed a price - the moment the offer counts
            // as accepted and the turnover QR below starts existing.
            'offer_accepted' => $item->acquisition_price !== null,
            'qr_code' => self::turnoverCode($item),

            // Legacy key, still the seller's own asking figure.
            'price_points' => $item->price_points,
        ]);

        // Withheld from the seller: reward_points and markup are buyer-side
        // and store-side figures respectively. Two are not secrets, though:
        // the acquisition price IS the acceptance offered to this seller, and
        // once the item is listed its public price is on the catalog for
        // anyone to read - hiding it only blanked the price on the seller's
        // own screens the moment their item was reserved.
        if ($item->acquisition_price !== null) {
            $payload['acquisition_price'] = $item->acquisition_price;
        }

        if ($item->public_price !== null) {
            $payload['public_price'] = $item->publicPrice()?->toDecimalString();
        }

        return $payload;
    }

    /** Admin inventory, offers and detail screens. */
    public static function forAdmin(Item $item): array
    {
        $asking = $item->askingPrice();
        $acquisition = $item->acquisitionPrice();
        $public = $item->publicPrice();
        $markup = $item->markup();

        return array_merge(self::common($item), [
            'seller_asking_price' => $asking->toDecimalString(),
            'acquisition_price' => $acquisition?->toDecimalString(),
            'public_price' => $public?->toDecimalString(),
            'markup' => $markup?->toDecimalString(),
            'reward_points' => $item->reward_points,
            'reward_points_preview' => $public === null ? 0 : LoyaltyRules::rewardPointsFor($public),

            'seller_payout_status' => $item->seller_payout_status,
            'seller_payout_amount' => $item->seller_payout_amount,
            'seller_paid_at' => $item->seller_paid_at,
            'seller_paid_by' => $item->seller_paid_by,

            'acquired_at' => $item->acquired_at,
            'acquired_by' => $item->acquired_by,
            'turnover_notes' => $item->turnover_notes,
            'turnover_photo' => $item->turnover_photo,
            'seller_payout_photo' => $item->seller_payout_photo,
            'meetup_schedule' => $item->meetup_schedule,
            'published_at' => $item->published_at,
            'published_by' => $item->published_by,
            'rejected_reason' => $item->rejected_reason,

            'is_turnover_verified' => $item->isTurnoverVerified(),
            'can_be_published' => $item->canBePublished(),
            'price_source' => $item->price_source,
            'is_legacy_priced' => $item->isLegacyPriced(),

            // The seller's turnover code, valid once the offer is priced.
            'qr_code' => self::turnoverCode($item),

            // Legacy keys, preserved for the current admin app build.
            'price_points' => $item->price_points,
            'markup_points' => $item->markup_points,
        ]);
    }

    /**
     * What a seller may always see about their own listing.
     *
     * Which presenter a request lands on depends on the item's status - a
     * published item is purchasable, so its own seller is handed the buyer
     * view. That view has no acquisition price, which left the seller's chat
     * header falling back to the asking price they typed weeks ago. These
     * fields are merged in whenever the requester IS the seller, so their own
     * numbers never disappear behind a status change.
     */
    public static function sellerExtras(Item $item): array
    {
        return [
            'seller_asking_price' => $item->askingPrice()->toDecimalString(),
            'acquisition_price' => $item->acquisition_price,
            'offer_accepted' => $item->acquisition_price !== null,
            'qr_code' => self::turnoverCode($item),
            'meetup_schedule' => $item->meetup_schedule,
            'seller_payout_status' => $item->seller_payout_status,
            'seller_payout_amount' => $item->seller_payout_amount,
            'acquired_at' => $item->acquired_at,
            'rejected_reason' => $item->rejected_reason,
        ];
    }

    /**
     * The turnover QR exists only between acceptance and acquisition: before
     * the price is agreed there is nothing to bring in, and after the item is
     * in the store the code has done its job.
     */
    private static function turnoverCode(Item $item): ?string
    {
        $accepted = $item->acquisition_price !== null && $item->isPending();

        return $accepted ? ItemQr::codeFor($item) : null;
    }

    /** Fields every audience may see. */
    private static function common(Item $item): array
    {
        return [
            'item_id' => $item->item_id,
            'seller_id' => $item->seller_id,
            'seller_email' => $item->relationLoaded('seller') ? $item->seller?->email : null,
            'title' => $item->title,
            'description' => $item->description,
            'category_id' => $item->category_id,
            'status' => $item->status,
            'photos' => $item->relationLoaded('photos')
                ? $item->photos->pluck('photo_url')->toArray()
                : [],
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];
    }
}
