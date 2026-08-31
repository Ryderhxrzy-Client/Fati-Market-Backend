<?php

namespace App\Support;

use App\Models\Transaction;

/**
 * The code inside a buyer's order QR.
 *
 * A walk-in buyer shows this on their phone; Admin scans it and lands on the
 * exact order without typing anything. The code is the transaction id plus an
 * HMAC over it, keyed with the app key, so a code that merely *looks* like an
 * order number cannot be minted by a buyer to pull up someone else's order.
 *
 * Deliberately stateless: nothing is stored, nothing expires. The QR only
 * identifies the order - every rule that matters (payment verified before
 * completion, admin-only actions) is enforced where the action happens.
 */
class OrderQr
{
    private const PREFIX = 'FMQR1';

    public static function codeFor(Transaction $transaction): string
    {
        $id = (string) $transaction->transaction_id;

        return self::PREFIX . '.' . $id . '.' . self::signatureFor($id);
    }

    /** The transaction id inside a scanned code, or null if it is not ours. */
    public static function transactionIdFrom(?string $code): ?int
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

        return substr(hash_hmac('sha256', 'order-qr|' . $id, $key), 0, 16);
    }
}
