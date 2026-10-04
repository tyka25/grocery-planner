<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchRejection extends Model
{
    protected $fillable = ['store_product_id', 'canonical_item_id'];
}
