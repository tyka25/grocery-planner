<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['draft', 'planned', 'shopping', 'done'])->default('draft');
            $table->timestamps();
        });

        Schema::create('list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_item_id')->nullable()->constrained();
            $table->string('free_text')->nullable(); // for items not yet matched to a canonical item
            $table->decimal('qty', 8, 2)->default(1);
            $table->enum('source', ['recipe', 'staple', 'adhoc'])->default('adhoc');
            $table->timestamps();
        });

        Schema::create('plan_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_product_id')->nullable()->constrained();
            $table->foreignId('store_id')->constrained();
            $table->enum('reason', ['preferred', 'fallback', 'manual', 'minimum_repair'])->default('preferred');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_assignments');
        Schema::dropIfExists('list_items');
        Schema::dropIfExists('shopping_lists');
    }
};
