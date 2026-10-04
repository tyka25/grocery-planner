<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_runs', function (Blueprint $table) {
            $table->string('trigger')->nullable();          // requested | scheduled
            $table->json('expected')->nullable();           // store_product ids the run was asked to look for
            $table->json('missing')->nullable();            // expected ids not seen in any search result
            $table->unsignedInteger('searches')->default(0);
            $table->unsignedInteger('snapshots_saved')->default(0);
            $table->unsignedInteger('unknown_products')->default(0); // seen, but not a store_product we know
        });

        Schema::table('availability_snapshots', function (Blueprint $table) {
            $table->foreignId('scrape_run_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('availability_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scrape_run_id');
        });
        Schema::table('scrape_runs', function (Blueprint $table) {
            $table->dropColumn(['trigger', 'expected', 'missing', 'searches', 'snapshots_saved', 'unknown_products']);
        });
    }
};
