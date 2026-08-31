<?php

namespace App\Support;

use App\Models\Item;

/**
 * The code inside a seller's turnover QR.
 *
 * Once Admin accepts an offer, the seller's listing gains this code. At the
 * store, the seller shows it, Admin scans it, and lands on the exact item to
 * mark it acquired - the seller-side mirror of the buyer's pickup QR.
 *
 * Same construction as [OrderQr] with its own prefix, so a scanner can tell
 * the two apart at a glance, and an order code can never be replayed as an
 * item code or vice versa (the HMAC input differs even for equal ids).
 */
class ItemQr
{
    private const PREFIX = 'FMITEM1';

    public static function codeFor(Item $item): string
    {
        $id = (string) $item->item_id;

        return self::PREFIX . '.' . $id . '.' . self::signatureFor($id);
    }

    /** The item id inside a scanned code, or null if it is not ours. */
    public static function itemIdFrom(?string $code): ?int
    {
        if (!is_string($code) || $code === '') {
            return null;
        }

        $parts = explode('.', trim($code));

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || !ctype_digit($parts[1])) {
            return null;
        }

        if (!hash_equals(self::signatureFor($parts[1]), $parts[2])) {
            return null;
        }

        return (int) $parts[1];
    }

    private static function signatureFor(string $id): string
    {
        $key = (string) config('app.key');

        return substr(hash_hmac('sha256', 'item-qr|' . $id, $key), 0, 16);
    }
}
