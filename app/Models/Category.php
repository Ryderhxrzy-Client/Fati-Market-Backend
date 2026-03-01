<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $primaryKey = 'category_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $table = 'categories';

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * Get the items for this category
     */
    public function items()
    {
        return $this->hasMany(Item::class, 'category_id', 'category_id');
    }
}
