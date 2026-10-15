<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_insights', function (Blueprint $table) {
            $table->unsignedBigInteger('landing_views')->default(0);
            $table->unsignedBigInteger('outbound_clicks')->default(0);
            $table->unsignedBigInteger('video_plays')->default(0);
            $table->unsignedBigInteger('video_p25')->default(0);
            $table->unsignedBigInteger('video_p75')->default(0);
            $table->unsignedBigInteger('video_p95')->default(0);
            $table->double('watch_seconds')->default(0);
            $table->unsignedBigInteger('reactions')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('saves')->default(0);
        });

        Schema::table('ad_breakdowns', function (Blueprint $table) {
            $table->unsignedBigInteger('landing_views')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
        });

        Schema::create('account_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_ad_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('days');
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('frequency', 8, 2)->nullable();
            $table->timestamps();
            $table->unique(['facebook_ad_account_id', 'days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_windows');
        Schema::table('ad_breakdowns', fn (Blueprint $t) => $t->dropColumn(['landing_views', 'clicks']));
        Schema::table('ad_insights', fn (Blueprint $t) => $t->dropColumn([
            'landing_views', 'outbound_clicks', 'video_plays', 'video_p25', 'video_p75', 'video_p95',
            'watch_seconds', 'reactions', 'comments', 'shares', 'saves',
        ]));
    }
};
