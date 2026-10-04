<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('instacart_order_id')->unique(); // 17-digit string; strip leading apostrophe on import
            $table->foreignId('store_id')->nullable()->constrained();
            $table->foreignId('location_id')->nullable()->constrained();
            $table->enum('fulfillment_mode', ['delivery', 'pickup'])->default('delivery');
            $table->date('ordered_on');
            $table->string('status'); // Delivered, Partial Refund, ... (verbatim from the export)

            // Money columns from the Order History export. These do NOT reliably
            // reconcile against the sum of this order's lines -- see import notes.
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('promotion', 10, 2)->nullable();
            $table->decimal('coupon', 10, 2)->nullable();
            $table->decimal('fees', 10, 2)->nullable();
            $table->decimal('tax', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->decimal('refund_total', 10, 2)->nullable();

            $table->string('order_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
