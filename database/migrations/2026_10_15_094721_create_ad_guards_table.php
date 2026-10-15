<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // campaigns whose ads are switched off automatically once their lead cost goes over the limit
        Schema::create('ad_guards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_ad_account_id')->constrained()->cascadeOnDelete();
            $table->string('campaign_id')->unique();
            $table->string('campaign_name')->nullable();
            $table->decimal('max_cpl', 8, 2)->default(1.5);
            $table->decimal('min_spend', 8, 2)->default(3);
            $table->boolean('active')->default(true);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_guard_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_guard_id')->constrained()->cascadeOnDelete();
            $table->string('ad_id');
            $table->string('ad_name')->nullable();
            $table->string('adset_name')->nullable();
            $table->decimal('spend', 10, 2);
            $table->unsignedInteger('leads');
            $table->decimal('cpl', 8, 2)->nullable();
            $table->string('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_guard_actions');
        Schema::dropIfExists('ad_guards');
    }
};
