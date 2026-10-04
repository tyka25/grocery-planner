<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = [
        'retailer_slug', 'name', 'fulfillment', 'location_id',
        'instacart_retailer_id', 'free_delivery_threshold', 'hard_minimum', 'enabled',
    ];

    protected $casts = ['enabled' => 'boolean'];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function products()
    {
        return $this->hasMany(StoreProduct::class);
    }
}
