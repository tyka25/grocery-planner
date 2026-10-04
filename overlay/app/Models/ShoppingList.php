<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoppingList extends Model
{
    protected $fillable = ['status'];

    public function items()
    {
        return $this->hasMany(ListItem::class);
    }
}
