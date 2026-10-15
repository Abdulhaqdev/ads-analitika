<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_ad_accounts', function (Blueprint $table) {
            $table->boolean('pinned')->default(false);
            $table->decimal('spend_30d', 12, 2)->default(0);
            $table->unsignedSmallInteger('active_ads')->default(0);
            $table->timestamp('activity_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('facebook_ad_accounts', fn (Blueprint $t) => $t->dropColumn(['pinned', 'spend_30d', 'active_ads', 'activity_checked_at']));
    }
};
