<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Message extends Model
{
    use HasFactory;

    protected $primaryKey = 'message_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'messages';
    public $timestamps = false;

    /**
     * What a message is, which is what lets a client draw an order card
     * instead of a paragraph.
     *
     * TEXT             - somebody typed it.
     * ORDER_PLACED     - the buyer checked out; the card shows the item, the
     *                    payment method and whether it is paid yet.
     * PAYMENT_SUBMITTED- the buyer sent a GCash reference and a screenshot.
     * ORDER_UPDATE     - Admin decided something, in the buyer's thread.
     */
    public const KIND_TEXT = 'text';
    public const KIND_ORDER_PLACED = 'order_placed';
    public const KIND_PAYMENT_SUBMITTED = 'payment_submitted';
    public const KIND_ORDER_UPDATE = 'order_update';

    /**
     * A seller just listed an item. Drawn as an offer card - the item, its
     * photo and asking price - with the review actions on Admin's side.
     * Carries no transaction; the message's own item_id is the subject.
     */
    public const KIND_ITEM_LISTED = 'item_listed';

    /** Kinds the apps render as a card rather than a chat bubble. */
    public const ORDER_KINDS = [
        self::KIND_ORDER_PLACED,
        self::KIND_PAYMENT_SUBMITTED,
        self::KIND_ORDER_UPDATE,
    ];

    protected $fillable = [
        'item_id',
        'sender_id',
        'receiver_id',
        'message',
        'kind',
        'transaction_id',
        'payment_status_at',
        'order_status_at',
        'sent_at',
        'is_read',
    ];

    protected $dates = [
        'sent_at',
    ];

    protected $casts = [
        'message' => 'encrypted',
    ];

    /**
     * Get the sender user
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id', 'user_id');
    }

    /**
     * Get the receiver user
     */
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id', 'user_id');
    }

    /**
     * Get the item
     */
    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }

    /** The order this message describes, for the order kinds only. */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    /** True when a client should draw this as an order card. */
    public function isOrderCard(): bool
    {
        return in_array($this->kind, self::ORDER_KINDS, true);
    }
}
