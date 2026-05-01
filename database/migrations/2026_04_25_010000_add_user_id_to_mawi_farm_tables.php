<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kandang')) {
            Schema::table('kandang', function (Blueprint $table) {
                if (! Schema::hasColumn('kandang', 'user_id')) {
                    $table->foreignId('user_id')->nullable()->after('id_kandang')->constrained('users')->cascadeOnDelete();
                }
            });

            $uniqueIndex = DB::table('information_schema.statistics')
                ->select('INDEX_NAME')
                ->where('TABLE_SCHEMA', DB::raw('database()'))
                ->where('TABLE_NAME', 'kandang')
                ->where('NON_UNIQUE', 0)
                ->groupBy('INDEX_NAME')
                ->havingRaw('GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = ?', ['nama_kandang'])
                ->value('INDEX_NAME');

            if ($uniqueIndex) {
                Schema::table('kandang', function (Blueprint $table) use ($uniqueIndex) {
                    $table->dropUnique($uniqueIndex);
                });
            }

            $hasCompositeUnique = DB::table('information_schema.statistics')
                ->select('INDEX_NAME')
                ->where('TABLE_SCHEMA', DB::raw('database()'))
                ->where('TABLE_NAME', 'kandang')
                ->where('NON_UNIQUE', 0)
                ->groupBy('INDEX_NAME')
                ->havingRaw('GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = ?', ['user_id,nama_kandang'])
                ->exists();

            if (! $hasCompositeUnique) {
                Schema::table('kandang', function (Blueprint $table) {
                    try {
                        $table->unique(['user_id', 'nama_kandang'], 'kandang_user_id_nama_kandang_unique');
                    } catch (\Throwable $e) {
                        //
                    }
                });
            }
        }

        foreach (['pakan_terpakai', 'produksi', 'operasional'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'user_id')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['operasional', 'produksi', 'pakan_terpakai', 'kandang'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'user_id')) {
                    try {
                        $table->dropConstrainedForeignId('user_id');
                    } catch (\Throwable $e) {
                        //
                    }
                }
            });
        }
    }
};
