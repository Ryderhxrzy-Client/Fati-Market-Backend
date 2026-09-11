<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAuthIdentity extends Model
{
    protected $primaryKey = 'auth_identity_id';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_subject',
        'provider_email',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
