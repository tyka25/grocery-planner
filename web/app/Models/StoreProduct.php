<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreProduct extends Model
{
    protected $fillable = [
        'store_id', 'instacart_product_id', 'description', 'size_text', 'pack_count',
        'image_url', 'canonical_item_id', 'match_status', 'match_score',
        'first_seen_on', 'last_seen_on',
    ];

    protected $casts = ['first_seen_on' => 'date', 'last_seen_on' => 'date'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function canonicalItem()
    {
        return $this->belongsTo(CanonicalItem::class);
    }

    public function availabilitySnapshots()
    {
        return $this->hasMany(AvailabilitySnapshot::class);
    }
}
