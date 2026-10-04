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

    protected $casts = [
        'first_seen_on' => 'date',
        'last_seen_on' => 'date',
        'canonical_item_id' => 'integer',
        'match_score' => 'float',
    ];

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

    public function orderLines()
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * A human said yes: link to $item (the pending suggestion, or any other
     * item they picked instead). Clears any earlier rejection of that pair.
     */
    public function confirmMatch(CanonicalItem $item): void
    {
        MatchRejection::where('store_product_id', $this->id)
            ->where('canonical_item_id', $item->id)
            ->delete();

        $this->forceFill([
            'canonical_item_id' => $item->id,
            'match_status' => 'confirmed',
            'match_score' => $this->canonical_item_id === $item->id ? $this->match_score : null,
        ])->save();
    }

    /**
     * A human said no to the current link -- a pending suggestion or a
     * previously confirmed match. The pair is remembered so the matcher won't
     * propose it again; it may still propose a *different* item later.
     */
    public function rejectMatch(): void
    {
        if ($this->canonical_item_id === null) {
            return;
        }

        MatchRejection::firstOrCreate([
            'store_product_id' => $this->id,
            'canonical_item_id' => $this->canonical_item_id,
        ]);

        $this->forceFill([
            'canonical_item_id' => null,
            'match_status' => 'rejected',
            'match_score' => null,
        ])->save();
    }
}
