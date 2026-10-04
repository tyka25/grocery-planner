<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanAssignment extends Model
{
    protected $fillable = [
        'list_item_id', 'store_product_id', 'store_id', 'reason',
        'note', 'estimated_unit_price', 'price_source', 'available',
    ];

    protected $casts = ['available' => 'boolean', 'estimated_unit_price' => 'float'];

    public function listItem()
    {
        return $this->belongsTo(ListItem::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function storeProduct()
    {
        return $this->belongsTo(StoreProduct::class);
    }
}
