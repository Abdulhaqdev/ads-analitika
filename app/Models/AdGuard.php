<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A campaign whose ads are switched off automatically once their lead cost goes over max_cpl. */
class AdGuard extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'max_cpl' => 'float',
            'min_spend' => 'float',
            'base_budget' => 'float',
            'max_budget' => 'float',
            'active' => 'boolean',
            'checked_at' => 'datetime',
            'scaled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FacebookAdAccount::class, 'facebook_ad_account_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AdGuardAction::class);
    }
}
