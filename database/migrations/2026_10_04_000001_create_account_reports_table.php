<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_ad_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('days')->default(7);
            $table->json('payload');
            $table->timestamp('computed_at');
            $table->timestamps();
            $table->unique(['facebook_ad_account_id', 'days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_reports');
    }
};
