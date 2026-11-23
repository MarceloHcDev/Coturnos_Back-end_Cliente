<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'email' => 'admin@coturnosecia.com.br',
            'role' => 'admin',
            'active' => true,
        ]);

        $this->call(ProductSeeder::class);
    }
}
