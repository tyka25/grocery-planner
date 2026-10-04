<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoppingList extends Model
{
    protected $fillable = ['status'];

    protected $casts = ['planned_at' => 'datetime'];

    /** The list being worked on: the newest one not marked done (created if none). */
    public static function current(): self
    {
        return static::where('status', '!=', 'done')->latest('id')->first()
            ?? static::create(['status' => 'draft']);
    }

    public function items()
    {
        return $this->hasMany(ListItem::class);
    }
}
