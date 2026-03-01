<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    use HasFactory;

    protected $primaryKey = 'favorite_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'favorites';

    protected $fillable = [
        'user_id',
        'item_id',
    ];

    public $timestamps = false;

    /**
     * Get the user who favorited the item
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Get the favorited item
     */
    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }
}
