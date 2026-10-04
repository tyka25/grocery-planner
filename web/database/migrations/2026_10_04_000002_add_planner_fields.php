<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Null = unknown; the planner falls back to
            // grocery_planner.planner.assumed_delivery_fee.
            $table->decimal('delivery_fee', 8, 2)->nullable()->after('hard_minimum');
        });

        Schema::table('plan_assignments', function (Blueprint $table) {
            // An *estimate* for minimum checks only (latest snapshot price, or
            // last-paid line_total / qty). Never summed into real spend.
            $table->decimal('estimated_unit_price', 10, 2)->nullable();
            $table->string('price_source')->nullable(); // snapshot | history
            $table->boolean('available')->nullable();   // null = no fresh snapshot
            $table->string('note')->nullable();         // human-readable "why this store"
        });

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->timestamp('planned_at')->nullable();
        });

        // Household facts, not schema: the importer's store upsert doesn't
        // touch these columns, so they survive re-imports.
        // Free-delivery thresholds confirmed from each storefront header.
        DB::table('stores')->where('retailer_slug', 'hy-vee')->update(['free_delivery_threshold' => 10]);
        DB::table('stores')->where('retailer_slug', 'costco')->update(['free_delivery_threshold' => 35]);
        // Vacation-only stores (Florida trip): keep their history, never plan to them.
        DB::table('stores')->whereIn('retailer_slug', ['publix', 'abc'])->update(['enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('shopping_lists', fn (Blueprint $t) => $t->dropColumn('planned_at'));
        Schema::table('plan_assignments', fn (Blueprint $t) => $t->dropColumn(['estimated_unit_price', 'price_source', 'available', 'note']));
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn('delivery_fee'));
    }
};
