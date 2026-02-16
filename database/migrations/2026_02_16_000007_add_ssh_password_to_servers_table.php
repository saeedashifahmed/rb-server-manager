<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'ssh_password_encrypted')) {
                $table->text('ssh_password_encrypted')->nullable()->after('ssh_private_key_encrypted');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE servers MODIFY ssh_private_key_encrypted TEXT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (Schema::hasColumn('servers', 'ssh_password_encrypted')) {
                $table->dropColumn('ssh_password_encrypted');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE servers MODIFY ssh_private_key_encrypted TEXT NOT NULL');
        }
    }
};
