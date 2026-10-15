<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdBreakdown extends Model
{
    protected $guarded = [];

    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class);
    }
}
