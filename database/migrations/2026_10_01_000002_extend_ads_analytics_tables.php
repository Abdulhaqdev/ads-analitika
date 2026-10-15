<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            $table->text('creative_body')->nullable();
            $table->string('creative_title')->nullable();
            $table->string('cta_type')->nullable();
            $table->text('link_url')->nullable();
            $table->decimal('adset_daily_budget', 12, 2)->nullable();
            $table->string('optimization_goal')->nullable();
            $table->string('bid_strategy')->nullable();
            $table->json('targeting')->nullable();
            $table->string('quality_ranking')->nullable();
            $table->string('engagement_ranking')->nullable();
            $table->string('conversion_ranking')->nullable();
        });

        Schema::table('ad_insights', function (Blueprint $table) {
            $table->unsignedInteger('leads_form')->default(0)->after('leads');
            $table->unsignedInteger('leads_web')->default(0)->after('leads_form');
            $table->unsignedBigInteger('engagements')->default(0);
        });

        Schema::table('ad_breakdowns', function (Blueprint $table) {
            $table->string('platform', 30)->nullable();
            $table->string('position', 40)->nullable();
            $table->string('device', 40)->nullable();
            $table->string('region', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ad_breakdowns', fn (Blueprint $t) => $t->dropColumn(['platform', 'position', 'device', 'region']));
        Schema::table('ad_insights', fn (Blueprint $t) => $t->dropColumn(['leads_form', 'leads_web', 'engagements']));
        Schema::table('ads', fn (Blueprint $t) => $t->dropColumn([
            'creative_body', 'creative_title', 'cta_type', 'link_url', 'adset_daily_budget', 'optimization_goal',
            'bid_strategy', 'targeting', 'quality_ranking', 'engagement_ranking', 'conversion_ranking',
        ]));
    }
};
