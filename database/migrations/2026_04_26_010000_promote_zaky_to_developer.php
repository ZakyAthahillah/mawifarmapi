<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('username', 'zaky')
            ->update(['role' => 'developer']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('username', 'zaky')
            ->update(['role' => 'owner']);
    }
};
