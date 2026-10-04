<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_store_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canonical_item_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->unsignedTinyInteger('rank'); // 1 = first choice
            $table->enum('source', ['inferred', 'manual'])->default('inferred');
            $table->boolean('pinned')->default(false); // manual rank survives re-inference
            $table->timestamps();

            $table->unique(['canonical_item_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_store_prefs');
    }
};
