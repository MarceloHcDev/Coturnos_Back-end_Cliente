<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'name',
        'slug',
        'product_type',
        'brand',
        'description',
        'base_price',
        'promotional_price',
        'active',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'promotional_price' => 'decimal:2',
        'active' => 'boolean',
    ];
}
