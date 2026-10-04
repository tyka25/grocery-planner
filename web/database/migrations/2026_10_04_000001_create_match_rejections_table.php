<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remembers every (product, item) pairing a human said "no" to, so the
        // matcher never re-suggests it. store_products.match_status = 'rejected'
        // only says the *latest* suggestion was turned down; this is the full
        // history, and lets the matcher still propose a different item later.
        Schema::create('match_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['store_product_id', 'canonical_item_id']);
        });

        Schema::table('canonical_items', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('canonical_items', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::dropIfExists('match_rejections');
    }
};
