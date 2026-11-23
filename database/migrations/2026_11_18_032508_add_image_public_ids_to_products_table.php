<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
            Schema::table('products', function (Blueprint $table) {
            $table->string('image_public_id', 255)->nullable()->after('description');
            $table->string('hover_public_id', 255)->nullable()->after('image_public_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
            Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['image_public_id', 'hover_public_id']);
        });
    }
};
