<?php

namespace App\Services\Planner;

use App\Models\PlanAssignment;
use App\Models\ShoppingList;
use Illuminate\Support\Facades\DB;

/** Runs the planner for a list and saves the result as plan_assignments. */
class PlanService
{
    public function __construct(
        private PlanInputBuilder $builder,
        private Planner $planner,
    ) {}

    public static function fromConfig(): self
    {
        $cfg = config('grocery_planner.planner');

        return new self(
            new PlanInputBuilder((int) $cfg['snapshot_max_age_hours']),
            new Planner((float) $cfg['pickup_trip_cost'], (float) $cfg['assumed_delivery_fee']),
        );
    }

    public function planner(): Planner
    {
        return $this->planner;
    }

    public function builder(): PlanInputBuilder
    {
        return $this->builder;
    }

    /**
     * Re-plans from scratch. Hand-chosen stores are carried over (the
     * builder reads them from the existing assignments before they're
     * replaced).
     */
    public function plan(ShoppingList $list): array
    {
        $input = $this->builder->build($list);
        $result = $this->planner->plan($input['lines'], $input['stores']);

        DB::transaction(function () use ($list, $result) {
            PlanAssignment::whereIn('list_item_id', $list->items()->select('id'))->delete();

            foreach ($result['assignments'] as $listItemId => $a) {
                PlanAssignment::create([
                    'list_item_id' => $listItemId,
                    'store_id' => $a['store_id'],
                    'store_product_id' => $a['store_product_id'],
                    'reason' => $a['reason'],
                    'note' => $a['note'],
                    'estimated_unit_price' => $a['unit_price'],
                    'price_source' => $a['price_source'],
                    'available' => $a['available'],
                ]);
            }

            $list->forceFill(['status' => 'planned', 'planned_at' => now()])->save();
        });

        return $result;
    }
}
