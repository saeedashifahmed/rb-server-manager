<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('domain');
            $table->string('admin_email');
            $table->string('site_title')->default('My WordPress Site');
            $table->enum('status', [
                'pending',
                'installing',
                'success',
                'failed',
            ])->default('pending');
            $table->string('current_step')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('log')->nullable();
            $table->string('wp_admin_url')->nullable();
            $table->string('wp_db_name')->nullable();
            $table->string('wp_db_user')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
    }
};
