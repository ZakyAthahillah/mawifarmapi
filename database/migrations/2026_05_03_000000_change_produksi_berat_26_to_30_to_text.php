<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (range(26, 30) as $index) {
            if (Schema::hasColumn('produksi', "berat{$index}")) {
                DB::statement(sprintf('ALTER TABLE `produksi` MODIFY `berat%d` TEXT NULL', $index));
            }
        }
    }

    public function down(): void
    {
        //
    }
};
