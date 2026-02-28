<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItemPhoto extends Model
{
    use HasFactory;

    protected $primaryKey = 'photo_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'item_photos';
    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'photo_url',
    ];

    /**
     * Get the item
     */
    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }
}
