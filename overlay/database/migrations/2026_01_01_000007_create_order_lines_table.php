<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_product_id')->constrained();
            $table->enum('line_type', ['delivered', 'refund'])->default('delivered');
            $table->unsignedInteger('occurrence')->default(1); // disambiguates repeat lines of the same item in one order
            $table->decimal('qty', 8, 2); // fractional for weighted items (0.25, 0.5 observed)
            $table->decimal('line_total', 10, 2); // "Product Price" column
            $table->decimal('paid_raw', 10, 2); // "Price Paid (Before-Tax)" verbatim, sign and all

            // Derived at import, not trusted as accounting:
            //   refund line_type           -> 'refunded'
            //   paid_raw < 0 on a delivered line -> 'negative_unverified' (NOT assumed to mean unfulfilled --
            //       verified against real orders that this does not reliably hold)
            //   otherwise                   -> 'ok'
            $table->enum('outcome', ['ok', 'refunded', 'negative_unverified'])->default('ok');

            $table->timestamps();

            $table->unique(['order_id', 'store_product_id', 'line_type', 'occurrence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
