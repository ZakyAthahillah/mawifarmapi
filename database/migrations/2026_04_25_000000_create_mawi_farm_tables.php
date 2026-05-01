<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username')->unique()->after('email');
            }

            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('user')->after('password');
            }
        });

        if (! Schema::hasTable('kandang')) {
            Schema::create('kandang', function (Blueprint $table) {
                $table->id('id_kandang');
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('nama_kandang');
                $table->unsignedInteger('kapasitas')->default(0);
                $table->unsignedInteger('populasi')->default(0);
                $table->unsignedInteger('total_kematian')->default(0);
                $table->date('tanggal_mulai')->nullable();
                $table->date('tanggal_selesai')->nullable();
                $table->unique(['user_id', 'nama_kandang']);
            });
        }

        if (! Schema::hasTable('pakan_terpakai')) {
            Schema::create('pakan_terpakai', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('id_kandang')->constrained('kandang', 'id_kandang')->cascadeOnUpdate()->restrictOnDelete();
                $table->date('tanggal');
                $table->decimal('jumlah_kg', 12, 2)->default(0);
                $table->decimal('harga_per_kg', 12, 2)->default(0);
                $table->decimal('total_harga', 14, 2)->default(0);
            });
        }

        if (! Schema::hasTable('produksi')) {
            Schema::create('produksi', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('id_kandang')->constrained('kandang', 'id_kandang')->cascadeOnUpdate()->restrictOnDelete();
                $table->date('tanggal');

                foreach (range(1, 25) as $index) {
                    $table->decimal("berat$index", 10, 2)->default(0);
                }

                $table->decimal('harga_per_kg', 12, 2)->default(0);
                $table->decimal('total_harga', 14, 2)->default(0);
            });
        }

        if (! Schema::hasTable('operasional')) {
            Schema::create('operasional', function (Blueprint $table) {
                $table->id('id_operasional');
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('id_kandang')->constrained('kandang', 'id_kandang')->cascadeOnUpdate()->restrictOnDelete();
                $table->date('tanggal');
                $table->decimal('rak', 14, 2)->default(0);
                $table->decimal('gaji', 14, 2)->default(0);
                $table->decimal('lain', 14, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('operasional');
        Schema::dropIfExists('produksi');
        Schema::dropIfExists('pakan_terpakai');
        Schema::dropIfExists('kandang');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'username')) {
                $table->dropUnique(['username']);
                $table->dropColumn('username');
            }

            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};
