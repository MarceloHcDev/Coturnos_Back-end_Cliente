<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasFactory;

    public const TYPE_MAIN    = 'main';
    public const TYPE_HOVER   = 'hover';
    public const TYPE_GALLERY = 'gallery';

    public const TYPES = [
        self::TYPE_MAIN,
        self::TYPE_HOVER,
        self::TYPE_GALLERY,
    ];

    protected $table = 'product_images';

    protected $fillable = [
        'product_id',
        'public_id',   // public_id devolvido pelo Cloudinary no upload
        'type',        // main | hover | gallery
        'position',    // ordem de exibição
        'version',     // version devolvida pelo Cloudinary
    ];

    protected $casts = [
        'position' => 'integer',
        'version'  => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}