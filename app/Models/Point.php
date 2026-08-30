<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single append-only entry in the points ledger.
 *
 * This is the same `points` table the app has always used, extended rather
 * than replaced - there is exactly one points system. Rows are never updated
 * or deleted: a reversal is a new entry with the opposite sign.
 *
 * Nothing may change `users.wallet_points` without writing a row here. Go
 * through App\Services\PointsLedger, which is the only writer.
 */
class Point extends Model
{
    use HasFactory;

    /** Buyer spent points at checkout (negative). */
    public const TYPE_REDEEM = 'redeem';

    /** Buyer earned points on a completed purchase (positive). */
    public const TYPE_REWARD = 'reward';

    /** Redeemed points handed back after a cancellation or rejection. */
    public const TYPE_REFUND = 'refund';

    /** Manual correction by an admin. */
    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Entries created before cash pricing, when points were the currency.
     * Kept distinct so reward reporting can exclude them.
     */
    public const TYPE_LEGACY_PURCHASE = 'legacy_purchase';
    public const TYPE_LEGACY_PAYOUT = 'legacy_payout';
    public const TYPE_LEGACY_MARKUP = 'legacy_markup';

    /** Types that belong to the current loyalty system. */
    public const CURRENT_TYPES = [
        self::TYPE_REDEEM,
        self::TYPE_REWARD,
        self::TYPE_REFUND,
        self::TYPE_ADJUSTMENT,
    ];

    protected $primaryKey = 'point_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'points';

    /**
     * The legacy table had only `created_at`. `updated_at` now exists, but
     * ledger rows are immutable, so Eloquent timestamps stay off and
     * `created_at` is set explicitly on insert.
     */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'transaction_id',
        'item_id',
        'points_change',
        'balance_after',
        'type',
        'reason',
        'idempotency_key',
        'created_at',
        // Legacy column name, kept in sync with item_id for older API readers.
        'related_item_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'transaction_id' => 'integer',
        'item_id' => 'integer',
        'points_change' => 'integer',
        'balance_after' => 'integer',
        'related_item_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /** The peso value this entry represents at the fixed redemption rate. */
    public function pesoValue(): Money
    {
        return \App\Support\LoyaltyRules::discountFor(abs($this->points_change));
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn('type', self::CURRENT_TYPES);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    /** Legacy relationship name, still used by the points history endpoints. */
    public function relatedItem()
    {
        return $this->belongsTo(Item::class, 'related_item_id', 'item_id');
    }
}
