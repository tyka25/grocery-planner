<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = ['label', 'address', 'kind', 'import_enabled'];

    protected $casts = ['import_enabled' => 'boolean'];

    public function stores()
    {
        return $this->hasMany(Store::class);
    }
}
