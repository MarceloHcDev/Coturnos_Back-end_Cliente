<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $nomes = [
            1 => 'Coturno Tático Airstrike',
            2 => 'Bota Anfíbia X',
            3 => 'Coturno Urbano Ghost',
            4 => 'Tênis Tático Fast',
            5 => 'Coturno Policial Force',
            6 => 'Bota Impermeável Desert',
            7 => 'Coturno Tático Black Ops',
            8 => 'Bota Resgate Firefighter',
            9 => 'Coturno Selva Camo',
            10 => 'Bota Militar Defender',
        ];

        foreach ($nomes as $id => $nome) {
            Product::updateOrCreate(
                ['id' => $id],
                [
                    'name' => $nome,
                    'slug' => \Illuminate\Support\Str::slug($nome),
                    'product_type' => 'coturno',
                    'brand' => 'tacforce',
                    'description' => 'Coturno militar profissional de alta performance e durabilidade.',
                    'base_price' => 499.90,
                    'promotional_price' => null,
                    'active' => true,
                ]
            );
        }
    }
}
