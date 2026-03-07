<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $primaryKey = 'transaction_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'transactions';

    // Disable timestamps since table only has transaction_date
    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'buyer_id',
        'seller_id',
        'payment_method',
        'points_used',
        'status',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'buyer_id' => 'integer',
        'seller_id' => 'integer',
        'points_used' => 'integer',
        'transaction_date' => 'datetime',
    ];

    /**
     * Get the item for this transaction
     */
    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }

    /**
     * Get the buyer
     */
    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id', 'user_id');
    }

    /**
     * Get the seller
     */
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id', 'user_id');
    }
}
