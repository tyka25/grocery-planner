<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->string('instacart_product_id'); // keep as text; never cast to float
            $table->string('description');
            $table->string('size_text')->nullable();
            $table->unsignedInteger('pack_count')->nullable();
            $table->string('image_url')->nullable();

            $table->foreignId('canonical_item_id')->nullable()->constrained();
            $table->enum('match_status', ['unmatched', 'auto', 'confirmed', 'rejected'])->default('unmatched');
            $table->decimal('match_score', 5, 4)->nullable();

            $table->date('first_seen_on')->nullable();
            $table->date('last_seen_on')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'instacart_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_products');
    }
};
