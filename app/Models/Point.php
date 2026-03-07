<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Point extends Model
{
    use HasFactory;

    protected $primaryKey = 'point_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'points';

    protected $fillable = [
        'user_id',
        'points_change',
        'reason',
        'related_item_id',
    ];

    protected $casts = [
        'points_change' => 'integer',
        'related_item_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Get the user that owns the point record
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Get the related item (if any)
     */
    public function relatedItem()
    {
        return $this->belongsTo(Item::class, 'related_item_id', 'item_id');
    }
}
