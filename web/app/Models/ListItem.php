<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListItem extends Model
{
    protected $fillable = ['shopping_list_id', 'canonical_item_id', 'free_text', 'qty', 'source'];

    public function shoppingList()
    {
        return $this->belongsTo(ShoppingList::class);
    }

    public function canonicalItem()
    {
        return $this->belongsTo(CanonicalItem::class);
    }

    public function assignment()
    {
        return $this->hasOne(PlanAssignment::class);
    }
}
