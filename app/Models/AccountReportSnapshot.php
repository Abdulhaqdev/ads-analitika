<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountReportSnapshot extends Model
{
    protected $table = 'account_reports';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'computed_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FacebookAdAccount::class, 'facebook_ad_account_id');
    }

    public function redCount(): int
    {
        return collect($this->payload['recs'] ?? [])->where('level', 'bad')->count();
    }
}
