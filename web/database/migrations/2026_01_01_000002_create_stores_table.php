<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('retailer_slug')->unique(); // Instacart storefront slug, e.g. "hy-vee"
            $table->string('name');
            $table->enum('fulfillment', ['delivery', 'pickup'])->default('delivery');
            $table->foreignId('location_id')->constrained();
            $table->string('instacart_retailer_id')->nullable();
            // Thresholds are entered by hand; nothing observed in the data is a reliable floor.
            $table->decimal('free_delivery_threshold', 8, 2)->nullable();
            $table->decimal('hard_minimum', 8, 2)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
