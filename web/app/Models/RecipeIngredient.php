<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeIngredient extends Model
{
    protected $fillable = ['recipe_id', 'canonical_item_id', 'qty', 'unit'];

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function canonicalItem()
    {
        return $this->belongsTo(CanonicalItem::class);
    }
}
