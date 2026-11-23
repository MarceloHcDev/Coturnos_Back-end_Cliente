<?php

/**
 * IMAGENS DOS PRODUTOS.
 *
 * Cada linha é uma imagem de um produto no Cloudinary. O banco guarda o
 * public_id (e a version) devolvidos no upload; a URL de entrega é montada
 * pelo backend a partir desses valores.
 *
 * type:     'main' (vitrine), 'hover' (ao passar o mouse) ou 'gallery'
 * position: ordem de exibição (0 a 5 no formulário do admin)
 * version:  número de versão devolvido pelo Cloudinary (evita cache antigo
 *           quando uma imagem é substituída mantendo o mesmo public_id)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('public_id', 255);
            $table->string('type', 20)->default('gallery'); // main | hover | gallery
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedBigInteger('version')->nullable();

            $table->timestamps();

            // Uma posição por produto e leitura ordenada das imagens
            $table->unique(['product_id', 'position']);
            $table->index(['product_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};