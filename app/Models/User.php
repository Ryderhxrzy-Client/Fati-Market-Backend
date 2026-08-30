<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'user_id';
    public $incrementing = true;
    protected $keyType = 'int';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'wallet_points',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet_points' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public const ROLE_ADMIN = 'admin';
    public const ROLE_STUDENT = 'student';

    /**
     * Only admins may price, verify turnover, publish, verify payments and
     * complete or cancel transactions.
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * The buyer's redeemable loyalty balance.
     *
     * Read this rather than the raw attribute so the intent is explicit at the
     * call sites that gate redemption.
     */
    public function availablePoints(): int
    {
        return (int) ($this->wallet_points ?? 0);
    }

    /** Every movement of this user's points, newest first. */
    public function pointsLedger()
    {
        return $this->hasMany(Point::class, 'user_id', 'user_id');
    }

    /**
     * Get student information
     */
    public function studentInfo()
    {
        return $this->hasOne(StudentInformation::class, 'user_id', 'user_id');
    }

    /**
     * Get student verification
     */
    public function verification()
    {
        return $this->hasOne(StudentVerification::class, 'user_id', 'user_id');
    }

    /**
     * Get transactions where user is buyer
     */
    public function transactionsAsBuyer()
    {
        return $this->hasMany(Transaction::class, 'buyer_id', 'user_id');
    }

    /**
     * Get transactions where user is seller
     */
    public function transactionsAsSeller()
    {
        return $this->hasMany(Transaction::class, 'seller_id', 'user_id');
    }

    /**
     * Get items sold by user
     */
    public function soldItems()
    {
        return $this->hasMany(Item::class, 'seller_id', 'user_id');
    }
}
