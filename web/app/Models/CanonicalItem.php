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

    /**
     * The item whose name matches what was typed, ignoring case, spacing and
     * the iPhone keyboard's smart punctuation (curly quotes, en/em dashes).
     * The catalog is a household's worth of items, so compare in PHP.
     */
    public static function findByTypedName(string $typed): ?self
    {
        $key = self::nameKey($typed);

        return self::all()->first(fn (self $item) => self::nameKey($item->name) === $key);
    }

    /** Keep in step with nameKey() in resources/js/Pages/List/Show.jsx. */
    public static function nameKey(string $name): string
    {
        $name = strtr($name, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-']);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }
}
