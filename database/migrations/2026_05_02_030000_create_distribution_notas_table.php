<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_notas')) {
            return;
        }

        Schema::create('distribution_notas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('tanggal');
            $table->string('kandang');
            $table->string('nomor_nota');

            foreach (range(1, 50) as $index) {
                $table->decimal("berat{$index}", 10, 2)->default(0);
            }

            $table->timestamps();
            $table->unique(['user_id', 'nomor_nota']);
            $table->index(['user_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_notas');
    }
};
