<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ad extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_video' => 'boolean', 'targeting' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FacebookAdAccount::class, 'facebook_ad_account_id');
    }

    public function insights(): HasMany
    {
        return $this->hasMany(AdInsight::class);
    }

    public function breakdowns(): HasMany
    {
        return $this->hasMany(AdBreakdown::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(AdAlert::class);
    }

    public function adsManagerUrl(): string
    {
        return 'https://adsmanager.facebook.com/adsmanager/manage/ads?act='.$this->account->account_id.'&selected_ad_ids='.$this->ad_id;
    }

    /** Result type this ad is judged on: leads, messages, traffic, landing or other. */
    public function resultType(): string
    {
        return $this->result_type ?: 'leads';
    }

    public function isActive(): bool
    {
        return $this->effective_status === 'ACTIVE';
    }
}
