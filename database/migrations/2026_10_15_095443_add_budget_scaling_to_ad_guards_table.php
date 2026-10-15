<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // the campaign budget grows while leads stay cheap: from base_budget up to max_budget, once a day
        Schema::table('ad_guards', function (Blueprint $table) {
            $table->decimal('base_budget', 8, 2)->nullable()->after('min_spend');
            $table->decimal('max_budget', 8, 2)->nullable()->after('base_budget');
            $table->timestamp('scaled_at')->nullable()->after('checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('ad_guards', function (Blueprint $table) {
            $table->dropColumn(['base_budget', 'max_budget', 'scaled_at']);
        });
    }
};
