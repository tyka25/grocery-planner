<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'instacart_order_id', 'store_id', 'location_id', 'fulfillment_mode', 'ordered_on', 'status',
        'subtotal', 'promotion', 'coupon', 'fees', 'tax', 'total', 'refund_total', 'order_url',
    ];

    protected $casts = ['ordered_on' => 'date'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function lines()
    {
        return $this->hasMany(OrderLine::class);
    }
}
