<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('qr_print_batches')) {
            return;
        }

        Schema::create('qr_print_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('tanggal');
            $table->string('nomor_batch');

            foreach (range(1, 30) as $index) {
                $table->decimal("berat{$index}", 10, 2)->default(0);
            }

            $table->timestamps();
            $table->unique(['user_id', 'nomor_batch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_print_batches');
    }
};
