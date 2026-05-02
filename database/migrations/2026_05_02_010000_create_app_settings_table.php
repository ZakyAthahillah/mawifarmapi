<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'maintenance_enabled'],
            ['value' => '0', 'updated_at' => now(), 'created_at' => now()]
        );

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'maintenance_message'],
            ['value' => '', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
