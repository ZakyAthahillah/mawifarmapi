<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_print_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('qr_print_batches', 'id_kandang')) {
                $table->foreignId('id_kandang')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('kandang', 'id_kandang')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('qr_print_batches', function (Blueprint $table) {
            if (Schema::hasColumn('qr_print_batches', 'id_kandang')) {
                $table->dropConstrainedForeignId('id_kandang');
            }
        });
    }
};
