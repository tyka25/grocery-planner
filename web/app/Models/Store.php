<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = [
        'retailer_slug', 'name', 'fulfillment', 'location_id',
        'instacart_retailer_id', 'free_delivery_threshold', 'hard_minimum', 'delivery_fee', 'enabled',
    ];

    protected $appends = ['label'];

    protected $casts = ['enabled' => 'boolean'];

    /**
     * Display name. `name` is the Instacart retailer slug (the importer
     * rewrites it on every run), so the label is derived rather than stored.
     */
    public function getLabelAttribute(): string
    {
        $slug = preg_replace('/-(corp|meat-grocery|farmers-market)$/', '', $this->retailer_slug ?? $this->name);

        return match ($slug) {
            'hy-vee' => 'Hy-Vee',
            'sams-club' => "Sam's Club",
            default => ucwords(str_replace('-', ' ', $slug)),
        };
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function products()
    {
        return $this->hasMany(StoreProduct::class);
    }
}
