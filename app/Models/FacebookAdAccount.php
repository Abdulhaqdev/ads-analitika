<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['facebook_connection_id', 'account_id', 'name', 'currency', 'timezone_name', 'account_status', 'is_tracked', 'target_cpl', 'last_synced_at', 'pinned', 'spend_30d', 'active_ads', 'activity_checked_at'])]
class FacebookAdAccount extends Model
{
    protected function casts(): array
    {
        return ['is_tracked' => 'boolean', 'pinned' => 'boolean', 'last_synced_at' => 'datetime', 'activity_checked_at' => 'datetime'];
    }

    /** Telegram hashtag, e.g. "quvvat dori" -> "#quvvat_dori". */
    public function hashtag(): string
    {
        $slug = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '_', mb_strtolower($this->name)), '_');

        return '#'.($slug ?: 'akkaunt_'.$this->id);
    }

    public function windows(): HasMany
    {
        return $this->hasMany(AccountWindow::class);
    }

    public function adsManagerUrl(): string
    {
        return 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act='.$this->account_id;
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FacebookConnection::class, 'facebook_connection_id');
    }

    public function statusLabel(): string
    {
        return match ($this->account_status) {
            1 => 'Faol',
            2 => 'O\'chirilgan',
            3 => 'To\'lov muammosi',
            7 => 'Ko\'rib chiqilmoqda',
            9 => 'Grace period',
            101 => 'Yopilgan',
            default => 'Noma\'lum',
        };
    }
}
