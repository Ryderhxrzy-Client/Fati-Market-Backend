<?php

namespace App\Support;

/**
 * The two loyalty constants and every calculation that depends on them.
 *
 * Kept in one place so the mobile app, the admin screens and the checkout all
 * agree, and so a rate change is a single edit. These values are never taken
 * from the client - the client may preview them, but the server recomputes.
 */
final class LoyaltyRules
{
    /** A buyer earns 1 reward point per PHP 100.00 of public selling price. */
    public const PESOS_PER_REWARD_POINT = 100;

    /** A redeemed point is worth a PHP 5.00 discount. */
    public const PESOS_PER_REDEEMED_POINT = 5;

    private const CENTAVOS_PER_REWARD_POINT = self::PESOS_PER_REWARD_POINT * 100;

    private const CENTAVOS_PER_REDEEMED_POINT = self::PESOS_PER_REDEEMED_POINT * 100;

    /**
     * rewardPoints = floor(publicSellingPrice / 100)
     *
     * PHP 90 -> 0, PHP 150 -> 1, PHP 250 -> 2, PHP 1,000 -> 10.
     */
    public static function rewardPointsFor(Money $publicPrice): int
    {
        if (!$publicPrice->isPositive()) {
            return 0;
        }

        return intdiv($publicPrice->centavos(), self::CENTAVOS_PER_REWARD_POINT);
    }

    /** pointsDiscount = pointsUsed * PHP 5.00 */
    public static function discountFor(int $pointsUsed): Money
    {
        if ($pointsUsed <= 0) {
            return Money::zero();
        }

        return Money::fromCentavos($pointsUsed * self::CENTAVOS_PER_REDEEMED_POINT);
    }

    /** finalAmountDue = max(itemPrice - pointsDiscount, 0) */
    public static function amountDue(Money $itemPrice, int $pointsUsed): Money
    {
        return $itemPrice->minus(self::discountFor($pointsUsed))->clampAtZero();
    }

    /**
     * The largest number of points that is actually useful for this item -
     * spending more would only discard the buyer's points for no extra
     * reduction, since the bill is clamped at zero.
     */
    public static function maxUsefulPoints(Money $itemPrice): int
    {
        if (!$itemPrice->isPositive()) {
            return 0;
        }

        return (int) ceil($itemPrice->centavos() / self::CENTAVOS_PER_REDEEMED_POINT);
    }
}
