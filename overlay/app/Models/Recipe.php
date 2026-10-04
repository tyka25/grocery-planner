<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $fillable = ['name', 'notes'];

    public function ingredients()
    {
        return $this->hasMany(RecipeIngredient::class);
    }
}
