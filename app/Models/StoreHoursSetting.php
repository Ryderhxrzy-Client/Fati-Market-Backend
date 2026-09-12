<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreHoursSetting extends Model
{
    protected $fillable = [
        'open_time', 'close_time', 'open_days', 'slot_minutes', 'updated_by',
    ];
}
