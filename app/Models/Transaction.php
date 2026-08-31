<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    /**
     * Buyer order lifecycle.
     *
     * PENDING_PAYMENT         - checkout created, item held, nothing paid yet.
     * PAYMENT_PROOF_SUBMITTED - buyer uploaded a GCash proof, awaiting Admin.
     * PAYMENT_VERIFIED        - Admin accepted the payment, or the bill was
     *                           fully covered by points.
     * RESERVED                - payment settled, item held for the buyer.
     * READY_FOR_PICKUP        - Admin has the item staged for handover.
     * COMPLETED               - paid and physically handed to the buyer. Only
     *                           Admin sets this, and only here are reward
     *                           points credited.
     * CANCELLED / REJECTED    - terminal; any points taken are given back.
     */
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAYMENT_PROOF_SUBMITTED = 'payment_proof_submitted';
    public const STATUS_PAYMENT_VERIFIED = 'payment_verified';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REJECTED = 'rejected';

    public const ALL_STATUSES = [
        self::STATUS_PENDING_PAYMENT,
        self::STATUS_PAYMENT_PROOF_SUBMITTED,
        self::STATUS_PAYMENT_VERIFIED,
        self::STATUS_RESERVED,
        self::STATUS_READY_FOR_PICKUP,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_REJECTED,
    ];

    /** States in which the item is still held for this buyer. */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING_PAYMENT,
        self::STATUS_PAYMENT_PROOF_SUBMITTED,
        self::STATUS_PAYMENT_VERIFIED,
        self::STATUS_RESERVED,
        self::STATUS_READY_FOR_PICKUP,
    ];

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PROOF_SUBMITTED = 'proof_submitted';
    public const PAYMENT_VERIFIED = 'verified';
    public const PAYMENT_REJECTED = 'rejected';

    public const PICKUP_NOT_READY = 'not_ready';
    public const PICKUP_READY = 'ready';
    public const PICKUP_PICKED_UP = 'picked_up';

    /** Cash and GCash both settle in person; POINTS_FULL owes nothing. */
    public const METHOD_CASH = 'cash';
    public const METHOD_GCASH = 'gcash';
    public const METHOD_POINTS_FULL = 'points_full';

    protected $primaryKey = 'transaction_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'transactions';

    /**
     * The legacy table had only `transaction_date`; created_at/updated_at were
     * added by migration, so Eloquent timestamps are enabled again.
     */
    public $timestamps = true;

    protected $fillable = [
        'item_id',
        'buyer_id',
        'seller_id',
        'subtotal',
        'points_used',
        'points_discount_amount',
        'amount_due',
        'reward_points_earned',
        'payment_method',
        'payment_proof',
        'payment_reference',
        'payment_proof_submitted_at',
        'payment_status',
        'payment_verified_at',
        'payment_verified_by',
        'handover_photo',
        'pickup_status',
        'reserved_until',
        'status',
        'completed_at',
        'completed_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'is_seller_payout',
        'transaction_date',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'buyer_id' => 'integer',
        'seller_id' => 'integer',
        'points_used' => 'integer',
        'reward_points_earned' => 'integer',
        'payment_verified_by' => 'integer',
        'completed_by' => 'integer',
        'cancelled_by' => 'integer',
        'is_seller_payout' => 'boolean',
        'subtotal' => 'decimal:2',
        'points_discount_amount' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'transaction_date' => 'datetime',
        'payment_proof_submitted_at' => 'datetime',
        'payment_verified_at' => 'datetime',
        'reserved_until' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // ── Money accessors ──────────────────────────────────────────────────

    public function subtotalMoney(): Money
    {
        return Money::fromPesos($this->subtotal);
    }

    public function discountMoney(): Money
    {
        return Money::fromPesos($this->points_discount_amount);
    }

    public function amountDueMoney(): Money
    {
        return Money::fromPesos($this->amount_due);
    }

    // ── Predicates ───────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_REJECTED,
        ], true);
    }

    /** A bill fully covered by points needs no cash and no GCash proof. */
    public function isFullPointsCheckout(): bool
    {
        return $this->amountDueMoney()->isZero();
    }

    public function hasExpired(): bool
    {
        return $this->reserved_until !== null
            && $this->status === self::STATUS_PENDING_PAYMENT
            && $this->reserved_until->isPast();
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    /**
     * Buyer orders only.
     *
     * The old "Send Points & Finalize" flow wrote seller payouts into this same
     * table. Those rows are flagged and must never appear as buyer orders.
     */
    public function scopeBuyerOrders(Builder $query): Builder
    {
        return $query->where('is_seller_payout', false);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id', 'user_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id', 'user_id');
    }

    public function ledgerEntries()
    {
        return $this->hasMany(Point::class, 'transaction_id', 'transaction_id');
    }
}
