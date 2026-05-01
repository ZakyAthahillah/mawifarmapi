<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kandang_periode')) {
            Schema::create('kandang_periode', function (Blueprint $table) {
                $table->id('id_periode');
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('id_kandang')->constrained('kandang', 'id_kandang')->cascadeOnUpdate()->cascadeOnDelete();
                $table->string('nama_periode');
                $table->unsignedInteger('populasi_awal')->default(0);
                $table->unsignedInteger('total_kematian')->default(0);
                $table->date('tanggal_mulai')->nullable();
                $table->date('tanggal_selesai')->nullable();
                $table->string('status')->default('aktif');
                $table->timestamps();
                $table->unique(['user_id', 'id_kandang', 'nama_periode']);
            });
        }

        foreach (['produksi', 'pakan_terpakai', 'operasional'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'id_periode')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('id_periode')
                        ->nullable()
                        ->after('id_kandang')
                        ->constrained('kandang_periode', 'id_periode')
                        ->cascadeOnUpdate()
                        ->nullOnDelete();
                });
            }
        }

        $kandangRows = DB::table('kandang')->get();
        foreach ($kandangRows as $kandang) {
            $exists = DB::table('kandang_periode')
                ->where('id_kandang', $kandang->id_kandang)
                ->where('nama_periode', 'Periode 1')
                ->exists();

            if ($exists) {
                continue;
            }

            $periodId = DB::table('kandang_periode')->insertGetId([
                'user_id' => $kandang->user_id,
                'id_kandang' => $kandang->id_kandang,
                'nama_periode' => 'Periode 1',
                'populasi_awal' => (int) ($kandang->populasi ?? 0),
                'total_kematian' => (int) ($kandang->total_kematian ?? 0),
                'tanggal_mulai' => $kandang->tanggal_mulai,
                'tanggal_selesai' => $kandang->tanggal_selesai,
                'status' => $kandang->tanggal_selesai ? 'selesai' : 'aktif',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (['produksi', 'pakan_terpakai', 'operasional'] as $tableName) {
                DB::table($tableName)
                    ->where('id_kandang', $kandang->id_kandang)
                    ->whereNull('id_periode')
                    ->update(['id_periode' => $periodId]);
            }
        }
    }

    public function down(): void
    {
        foreach (['produksi', 'pakan_terpakai', 'operasional'] as $tableName) {
            if (Schema::hasColumn($tableName, 'id_periode')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('id_periode');
                });
            }
        }

        Schema::dropIfExists('kandang_periode');
    }
};
