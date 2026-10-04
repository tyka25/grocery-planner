<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('address');
            // home: your delivery address. pickup_site: a store you drive to (e.g. Fareway/Riverside).
            // travel: anywhere incidental (vacation) that shouldn't factor into store preferences.
            $table->enum('kind', ['home', 'pickup_site', 'travel'])->default('home');
            $table->boolean('import_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
