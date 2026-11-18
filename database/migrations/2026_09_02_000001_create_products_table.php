<?php

/**
 * PRODUTOS (catálogo).
 *
 * As imagens NÃO ficam no banco: o ProductsController monta a URL do
 * Cloudinary a partir do id (produtos/{id}_{n}).
 * Dimensões e peso são usados no cálculo de frete; quando nulos, vale o
 * padrão da tabela packed_products.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Identificação
            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->string('product_type', 60)->nullable();
            $table->string('brand', 60)->nullable();
            $table->text('description')->nullable();

            // Vitrine (home)
            $table->string('collection', 60)->nullable(); // 'classico', 'elite'...
            $table->string('badge', 40)->nullable();

            // Preço: promotional_price (se menor que base_price) vira o preço atual
            $table->decimal('base_price', 10, 2);
            $table->decimal('promotional_price', 10, 2)->nullable();

            // Frete (kg / cm)
            $table->decimal('weight', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('length', 8, 2)->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Consulta da home: where active + collection, order by id
            $table->index(['active', 'collection']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};