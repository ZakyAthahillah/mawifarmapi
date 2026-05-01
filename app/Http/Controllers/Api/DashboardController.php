<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Produksi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function kandangSummary(Request $request)
    {
        $row = DB::table('kandang')
            ->where('user_id', $this->dataOwnerId())
            ->selectRaw('SUM(populasi) as total_populasi, SUM(total_kematian) as total_kematian')
            ->first();

        $totalAyam = (int) ($row->total_populasi ?? 0);
        $totalKematian = (int) ($row->total_kematian ?? 0);

        return response()->json([
            'status' => true,
            'data' => [
                'total_ayam' => $totalAyam - $totalKematian,
                'total_kematian' => $totalKematian,
            ],
        ]);
    }

    public function produksiSummary(Request $request)
    {
        $ids = $this->kandangIds();

        if ($ids->isEmpty()) {
            return response()->json(['status' => true, 'data' => ['mtd' => 0, 'ytd' => 0]]);
        }

        $year = now()->year;
        $month = now()->month;

        $mtd = Produksi::query()
            ->where('user_id', $this->dataOwnerId())
            ->whereIn('id_kandang', $ids)
            ->whereYear('tanggal', $year)
            ->whereMonth('tanggal', $month)
            ->get()
            ->sum(fn (Produksi $row) => $this->productionWeightTotal($row));

        $ytd = Produksi::query()
            ->where('user_id', $this->dataOwnerId())
            ->whereIn('id_kandang', $ids)
            ->whereYear('tanggal', $year)
            ->get()
            ->sum(fn (Produksi $row) => $this->productionWeightTotal($row));

        return response()->json(['status' => true, 'data' => ['mtd' => (float) $mtd, 'ytd' => (float) $ytd]]);
    }

    public function monthlyProduction(Request $request)
    {
        $ids = $this->kandangIds();

        if ($ids->isEmpty()) {
            return response()->json(['status' => false, 'message' => 'Tidak ada kandang untuk user ini']);
        }

        $data = Produksi::query()
            ->where('user_id', $this->dataOwnerId())
            ->whereIn('id_kandang', $ids)
            ->whereYear('tanggal', now()->year)
            ->whereMonth('tanggal', now()->month)
            ->orderBy('tanggal')
            ->get()
            ->groupBy(fn (Produksi $row) => $row->tanggal->toDateString())
            ->map(fn ($rows, $date) => [
                'tanggal' => $date,
                'total_berat' => (float) $rows->sum(fn (Produksi $row) => $this->productionWeightTotal($row)),
            ])
            ->keyBy('tanggal');

        $today = now();
        $data = collect(range(1, $today->daysInMonth))->map(function (int $day) use ($today, $data) {
            $date = $today->copy()->day($day)->toDateString();

            return [
                'tanggal' => $date,
                'total_berat' => (float) ($data[$date]['total_berat'] ?? 0),
            ];
        });

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function yearlyProduction(Request $request)
    {
        $ids = $this->kandangIds();

        if ($ids->isEmpty()) {
            return response()->json(['status' => false, 'message' => 'Tidak ada kandang']);
        }

        $year = (int) $request->query('year', date('Y'));
        $rows = Produksi::query()
            ->where('user_id', $this->dataOwnerId())
            ->whereIn('id_kandang', $ids)
            ->whereYear('tanggal', $year)
            ->get()
            ->groupBy(fn (Produksi $row) => (int) $row->tanggal->format('n'))
            ->map(fn ($monthRows) => (float) $monthRows->sum(fn (Produksi $row) => $this->productionWeightTotal($row)));

        $names = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $data = collect(range(1, 12))->map(fn ($month) => [
            'bulan_ke' => $month,
            'nama_bulan' => $names[$month],
            'total_berat' => (float) ($rows[$month] ?? 0),
        ]);

        return response()->json(['status' => true, 'data' => $data]);
    }

    private function kandangIds()
    {
        return DB::table('kandang')
            ->where('user_id', $this->dataOwnerId())
            ->pluck('id_kandang');
    }

    private function productionWeightTotal(Produksi $row): float
    {
        return array_reduce(
            Produksi::BERAT_COLUMNS,
            fn (float $carry, string $column) => $carry + (float) ($row->{$column} ?? 0),
            0.0
        );
    }
}
