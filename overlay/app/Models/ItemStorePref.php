<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemStorePref extends Model
{
    protected $fillable = ['canonical_item_id', 'store_id', 'rank', 'source', 'pinned'];

    protected $casts = ['pinned' => 'boolean'];

    public function canonicalItem()
    {
        return $this->belongsTo(CanonicalItem::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
