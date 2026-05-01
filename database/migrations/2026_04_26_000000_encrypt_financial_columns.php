<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->alterColumnsToText('pakan_terpakai', ['jumlah_kg', 'harga_per_kg', 'total_harga']);
        $this->alterColumnsToText('operasional', ['rak', 'gaji', 'lain']);
        $this->alterColumnsToText(
            'produksi',
            array_merge(['harga_per_kg', 'total_harga'], array_map(fn ($index) => "berat{$index}", range(1, 25)))
        );

        $this->encryptTable(
            'pakan_terpakai',
            'id',
            ['jumlah_kg', 'harga_per_kg', 'total_harga']
        );

        $this->encryptTable(
            'operasional',
            'id_operasional',
            ['rak', 'gaji', 'lain']
        );

        $this->encryptTable(
            'produksi',
            'id',
            array_merge(['harga_per_kg', 'total_harga'], array_map(fn ($index) => "berat{$index}", range(1, 25)))
        );
    }

    public function down(): void
    {
        //
    }

    private function encryptTable(string $table, string $primaryKey, array $columns): void
    {
        $rows = DB::table($table)->get();

        foreach ($rows as $row) {
            $updates = [];

            foreach ($columns as $column) {
                $value = $row->{$column} ?? 0;

                if ($this->isEncrypted($value)) {
                    continue;
                }

                $updates[$column] = Crypt::encryptString(number_format((float) $value, 2, '.', ''));
            }

            if ($updates !== []) {
                DB::table($table)
                    ->where($primaryKey, $row->{$primaryKey})
                    ->update($updates);
            }
        }
    }

    private function alterColumnsToText(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` TEXT NULL', $table, $column));
        }
    }

    private function isEncrypted(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        try {
            Crypt::decryptString($value);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
};
