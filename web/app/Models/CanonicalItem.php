<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CanonicalItem extends Model
{
    protected $fillable = ['name', 'category', 'is_staple', 'default_qty', 'notes'];

    protected $casts = ['is_staple' => 'boolean'];

    public function storeProducts()
    {
        return $this->hasMany(StoreProduct::class);
    }

    public function storePrefs()
    {
        return $this->hasMany(ItemStorePref::class)->orderBy('rank');
    }
}
