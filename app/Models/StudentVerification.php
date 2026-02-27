<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentVerification extends Model
{
    use HasFactory;

    protected $primaryKey = 'student_verification_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'student_verification';

    protected $fillable = [
        'user_id',
        'verification_use',
        'link',
        'is_verified',
        'status',
        'reason',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
