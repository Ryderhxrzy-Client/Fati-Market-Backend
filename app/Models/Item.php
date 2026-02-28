<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;

    protected $primaryKey = 'item_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'items';

    protected $fillable = [
        'seller_id',
        'title',
        'description',
        'category',
        'status',
        'price_points',
        'markup_points',
    ];

    /**
     * Get the seller user
     */
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id', 'user_id');
    }

    /**
     * Get the item photos
     */
    public function photos()
    {
        return $this->hasMany(ItemPhoto::class, 'item_id', 'item_id');
    }

    /**
     * Get the messages for this item
     */
    public function messages()
    {
        return $this->hasMany(Message::class, 'item_id', 'item_id');
    }
}
