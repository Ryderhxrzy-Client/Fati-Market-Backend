<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentInformation extends Model
{
    use HasFactory;

    protected $primaryKey = 'student_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'student_information';

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'profile_picture',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
