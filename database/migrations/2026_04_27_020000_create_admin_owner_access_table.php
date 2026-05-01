<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_owner_access')) {
            Schema::create('admin_owner_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['admin_id', 'owner_id']);
            });
        }

        if (Schema::hasColumn('users', 'owner_id')) {
            $adminOwners = DB::table('users')
                ->select('id', 'owner_id')
                ->where('role', 'admin')
                ->whereNotNull('owner_id')
                ->get();

            foreach ($adminOwners as $row) {
                DB::table('admin_owner_access')->updateOrInsert(
                    ['admin_id' => $row->id, 'owner_id' => $row->owner_id],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_owner_access');
    }
};
