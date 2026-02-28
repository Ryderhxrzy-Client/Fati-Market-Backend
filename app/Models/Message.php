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

    protected $fillable = [
        'item_id',
        'sender_id',
        'receiver_id',
        'message',
        'sent_at',
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
}
