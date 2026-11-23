<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProdutoColeturnoSeeder extends Seeder
{
    public function run(): void
    {
        Product::updateOrCreate(
            ['slug' => 'coturno-tatico-x1'],
            [
                'name' => 'Coturno Tático X1',
                'product_type' => 'Coturno',
                'brand' => 'Tático',
                'collection' => 'classico',
                'badge' => null,
                'description' => 'Coturno tático de exemplo.',
                'image_public_id' => 'coturno2',
                'hover_public_id' => null,
                'base_price' => 199.90,
                'promotional_price' => null,
                'active' => true,
            ]
        );
    }
}