<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['facebook_user_id', 'name', 'access_token', 'token_expires_at', 'last_synced_at'])]
#[Hidden(['access_token'])]
class FacebookConnection extends Model
{
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function adAccounts(): HasMany
    {
        return $this->hasMany(FacebookAdAccount::class);
    }
}
