<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            $table->string('result_type', 12)->nullable()->after('objective');
        });

        Schema::table('ad_insights', function (Blueprint $table) {
            $table->unsignedInteger('messages')->default(0)->after('leads_web');
            $table->unsignedBigInteger('results')->default(0)->after('messages');
        });
    }

    public function down(): void
    {
        Schema::table('ad_insights', fn (Blueprint $t) => $t->dropColumn(['messages', 'results']));
        Schema::table('ads', fn (Blueprint $t) => $t->dropColumn('result_type'));
    }
};
