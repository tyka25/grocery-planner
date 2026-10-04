<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_product_id')->constrained();
            $table->timestamp('observed_at');
            $table->boolean('available');
            // Free text, not an enum: observed values differ by store
            // ("highlyInStock", "inStock" seen so far; no out-of-stock example seen yet).
            $table->string('stock_level')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('size_text')->nullable();
            $table->string('query')->nullable(); // the search term that surfaced this item, for debugging
            $table->timestamps();

            $table->index(['store_product_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_snapshots');
    }
};
