<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kandang_owner_access')) {
            Schema::create('kandang_owner_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('id_kandang')->constrained('kandang', 'id_kandang')->cascadeOnDelete();
                $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['id_kandang', 'owner_id']);
            });
        }

        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('user_name')->nullable();
                $table->string('user_role')->nullable();
                $table->string('action', 80);
                $table->string('module', 80)->nullable();
                $table->string('subject_type')->nullable();
                $table->string('subject_id')->nullable();
                $table->json('before_data')->nullable();
                $table->json('after_data')->nullable();
                $table->ipAddress('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('kandang_owner_access');
    }
};
