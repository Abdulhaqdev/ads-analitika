<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facebook_connection_id')->constrained()->cascadeOnDelete();
            $table->string('account_id')->unique();
            $table->string('name')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('timezone_name')->nullable();
            $table->unsignedSmallInteger('account_status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_ad_accounts');
    }
};
