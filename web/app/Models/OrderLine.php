<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderLine extends Model
{
    protected $fillable = [
        'order_id', 'store_product_id', 'line_type', 'occurrence',
        'qty', 'line_total', 'paid_raw', 'outcome',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function storeProduct()
    {
        return $this->belongsTo(StoreProduct::class);
    }
}
