<?php

namespace App\Models;

use App\Support\LoyaltyRules;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;

    /**
     * Item lifecycle.
     *
     * PENDING    - uploaded by a student, awaiting Admin. Students cannot move
     *              an item out of this state themselves.
     * ACQUIRED   - Admin has physically received and verified the item and
     *              recorded the acquisition price.
     * PUBLIC     - published to the buyer catalog at a peso price.
     * RESERVED   - a buyer has an open checkout holding the item.
     * SOLD       - a completed transaction.
     * REJECTED   - Admin declined the item.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACQUIRED = 'acquired';
    public const STATUS_PUBLIC = 'public';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_SOLD = 'sold';
    public const STATUS_REJECTED = 'rejected';

    /**
     * 'private' is the legacy name for what is now 'pending'. It is still
     * accepted on input so older app builds keep working.
     */
    public const STATUS_LEGACY_PRIVATE = 'private';

    public const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACQUIRED,
        self::STATUS_PUBLIC,
        self::STATUS_RESERVED,
        self::STATUS_SOLD,
        self::STATUS_REJECTED,
    ];

    public const PAYOUT_UNPAID = 'unpaid';
    public const PAYOUT_PAID = 'paid';

    protected $primaryKey = 'item_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'items';

    protected $fillable = [
        'seller_id',
        'title',
        'description',
        'category_id',
        'status',
        'seller_asking_price',
        'acquisition_price',
        'public_price',
        'reward_points',
        'seller_payout_status',
        'seller_payout_amount',
        'seller_paid_at',
        'seller_paid_by',
        'acquired_at',
        'acquired_by',
        'turnover_notes',
        'meetup_schedule',
        'published_at',
        'published_by',
        'price_source',
        'rejected_reason',
        // Legacy point columns. Still written on create so older API consumers
        // and the historical reports keep reading sane values.
        'price_points',
        'markup_points',
    ];

    protected $casts = [
        'seller_id' => 'integer',
        'category_id' => 'integer',
        'reward_points' => 'integer',
        'price_points' => 'integer',
        'markup_points' => 'integer',
        'seller_paid_by' => 'integer',
        'acquired_by' => 'integer',
        'published_by' => 'integer',
        // decimal:2 keeps these as exact strings rather than floats. Do the
        // arithmetic through the Money helpers below, never on these directly.
        'seller_asking_price' => 'decimal:2',
        'acquisition_price' => 'decimal:2',
        'public_price' => 'decimal:2',
        'seller_payout_amount' => 'decimal:2',
        'acquired_at' => 'datetime',
        'seller_paid_at' => 'datetime',
        'meetup_schedule' => 'datetime',
        'published_at' => 'datetime',
    ];

    // ── Money accessors ──────────────────────────────────────────────────

    public function askingPrice(): Money
    {
        return Money::fromPesos($this->seller_asking_price);
    }

    public function acquisitionPrice(): ?Money
    {
        return $this->acquisition_price === null ? null : Money::fromPesos($this->acquisition_price);
    }

    public function publicPrice(): ?Money
    {
        return $this->public_price === null ? null : Money::fromPesos($this->public_price);
    }

    /**
     * Admin markup = public price - acquisition price.
     *
     * Always derived, never stored: the old `markup_points` column tried to be
     * both the catalog price and the profit, and the two drifted apart.
     */
    public function markup(): ?Money
    {
        $public = $this->publicPrice();
        $acquisition = $this->acquisitionPrice();

        if ($public === null || $acquisition === null) {
            return null;
        }

        return $public->minus($acquisition);
    }

    /** The reward a buyer would earn, recomputed from the current price. */
    public function calculatedRewardPoints(): int
    {
        $public = $this->publicPrice();

        return $public === null ? 0 : LoyaltyRules::rewardPointsFor($public);
    }

    // ── Lifecycle predicates ─────────────────────────────────────────────

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_LEGACY_PRIVATE], true);
    }

    public function isTurnoverVerified(): bool
    {
        return $this->acquired_at !== null;
    }

    /** Admin may only publish once turnover and acquisition price are on record. */
    public function canBePublished(): bool
    {
        return $this->isTurnoverVerified()
            && $this->acquisitionPrice() !== null
            && in_array($this->status, [self::STATUS_ACQUIRED, self::STATUS_PUBLIC], true);
    }

    /** Whether a buyer may start a checkout against this item. */
    public function isPurchasable(): bool
    {
        return $this->status === self::STATUS_PUBLIC;
    }

    public function isLegacyPriced(): bool
    {
        return $this->price_source === 'legacy_points';
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id', 'user_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    public function photos()
    {
        return $this->hasMany(ItemPhoto::class, 'item_id', 'item_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class, 'item_id', 'item_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'item_id', 'item_id');
    }

    public function acquiredBy()
    {
        return $this->belongsTo(User::class, 'acquired_by', 'user_id');
    }

    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by', 'user_id');
    }
}
