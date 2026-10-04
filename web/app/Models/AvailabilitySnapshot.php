<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvailabilitySnapshot extends Model
{
    protected $fillable = ['store_product_id', 'scrape_run_id', 'observed_at', 'available', 'stock_level', 'price', 'size_text', 'query'];

    protected $casts = ['observed_at' => 'datetime', 'available' => 'boolean'];

    public function storeProduct()
    {
        return $this->belongsTo(StoreProduct::class);
    }
}
