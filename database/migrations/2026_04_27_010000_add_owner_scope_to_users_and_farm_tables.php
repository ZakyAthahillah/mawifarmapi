<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'owner_id')) {
                $table->foreignId('owner_id')
                    ->nullable()
                    ->after('role')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        foreach (['kandang', 'kandang_periode', 'produksi', 'pakan_terpakai', 'operasional'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'created_by')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('created_by')
                        ->nullable()
                        ->after('user_id')
                        ->constrained('users')
                        ->nullOnDelete();
                });
            }

            DB::table($tableName)
                ->whereNull('created_by')
                ->update(['created_by' => DB::raw('user_id')]);
        }
    }

    public function down(): void
    {
        foreach (['operasional', 'pakan_terpakai', 'produksi', 'kandang_periode', 'kandang'] as $tableName) {
            if (Schema::hasColumn($tableName, 'created_by')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('created_by');
                });
            }
        }

        if (Schema::hasColumn('users', 'owner_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('owner_id');
            });
        }
    }
};
