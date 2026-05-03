<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            foreach (range(26, 30) as $index) {
                if (! Schema::hasColumn('produksi', "berat{$index}")) {
                    $table->text("berat{$index}")->nullable()->after('berat25');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('produksi', function (Blueprint $table) {
            foreach (range(26, 30) as $index) {
                if (Schema::hasColumn('produksi', "berat{$index}")) {
                    $table->dropColumn("berat{$index}");
                }
            }
        });
    }
};
