<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrapeRun extends Model
{
    protected $fillable = [
        'started_at', 'finished_at', 'status', 'error', 'trigger',
        'expected', 'missing', 'searches', 'snapshots_saved', 'unknown_products',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expected' => 'array',
        'missing' => 'array',
    ];

    public function snapshots()
    {
        return $this->hasMany(AvailabilitySnapshot::class);
    }

    public function isStalled(): bool
    {
        return $this->status === 'running'
            && $this->started_at->lt(now()->subMinutes((int) config('grocery_planner.sidecar.stalled_after_minutes')));
    }
}
