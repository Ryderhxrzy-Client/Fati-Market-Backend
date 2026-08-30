<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcmDeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'device_id', 'platform', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];
}
