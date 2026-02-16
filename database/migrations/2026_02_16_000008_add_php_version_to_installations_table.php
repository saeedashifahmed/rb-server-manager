<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installations', function (Blueprint $table) {
            if (! Schema::hasColumn('installations', 'php_version')) {
                $table->string('php_version', 10)->default('8.3')->after('site_title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('installations', function (Blueprint $table) {
            if (Schema::hasColumn('installations', 'php_version')) {
                $table->dropColumn('php_version');
            }
        });
    }
};
