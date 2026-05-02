<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kandang_mortality_logs')) {
            Schema::create('kandang_mortality_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('id_kandang')->constrained('kandang', 'id_kandang')->cascadeOnDelete();
                $table->foreignId('id_periode')->nullable()->constrained('kandang_periode', 'id_periode')->nullOnDelete();
                $table->date('tanggal');
                $table->unsignedInteger('jumlah_kematian');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kandang_mortality_logs');
    }
};
