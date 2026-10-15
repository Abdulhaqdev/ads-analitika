<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdGuardAction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['spend' => 'float', 'cpl' => 'float'];
    }

    public function adGuard(): BelongsTo
    {
        return $this->belongsTo(AdGuard::class, 'ad_guard_id');
    }
}
