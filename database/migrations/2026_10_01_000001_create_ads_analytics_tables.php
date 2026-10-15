<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_ad_accounts', function (Blueprint $table) {
            $table->boolean('is_tracked')->default(false)->after('account_status');
            $table->decimal('target_cpl', 10, 2)->nullable()->after('is_tracked');
            $table->timestamp('last_synced_at')->nullable()->after('target_cpl');
        });

        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_ad_account_id')->constrained()->cascadeOnDelete();
            $table->string('ad_id')->unique();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->string('effective_status')->nullable();
            $table->string('campaign_id')->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('adset_id')->nullable();
            $table->string('adset_name')->nullable();
            $table->string('objective')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->boolean('is_video')->default(false);
            $table->decimal('frequency_7d', 8, 2)->nullable();
            $table->decimal('frequency_total', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('ad_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('link_clicks')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedBigInteger('video_3s')->default(0);
            $table->unsignedBigInteger('thruplays')->default(0);
            $table->unsignedBigInteger('video_p50')->default(0);
            $table->unsignedBigInteger('video_p100')->default(0);
            $table->timestamps();
            $table->unique(['ad_id', 'date']);
            $table->index('date');
        });

        Schema::create('ad_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('age', 10)->nullable();
            $table->string('gender', 10)->nullable();
            $table->unsignedTinyInteger('hour')->nullable();
            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('link_clicks')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->timestamps();
            $table->index(['ad_id', 'kind']);
        });

        Schema::create('ad_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 30);
            $table->string('severity', 10);
            $table->string('title');
            $table->json('reasons')->nullable();
            $table->text('recommendation')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['ad_id', 'date', 'type']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_alerts');
        Schema::dropIfExists('ad_breakdowns');
        Schema::dropIfExists('ad_insights');
        Schema::dropIfExists('ads');
        Schema::table('facebook_ad_accounts', function (Blueprint $table) {
            $table->dropColumn(['is_tracked', 'target_cpl', 'last_synced_at']);
        });
    }
};
