<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_items', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // "whole milk", "greek yogurt", "blueberries"
            $table->string('category')->nullable();
            $table->boolean('is_staple')->default(false);
            $table->decimal('default_qty', 8, 2)->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_items');
    }
};
