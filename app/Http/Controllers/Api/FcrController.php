<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kandang;
use App\Models\KandangPeriode;
use App\Models\Operasional;
use App\Models\PakanTerpakai;
use App\Models\Produksi;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FcrController extends Controller
{
    public function periode(Request $request)
    {
        $request->validate([
            'id_kandang' => ['required_without:id_periode', 'integer'],
            'id_periode' => ['nullable', 'integer'],
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'tahun' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $periode = null;
        if ($request->filled('id_periode')) {
            $periode = KandangPeriode::query()
                ->whereIn('id_kandang', $this->accessibleKandangIds())
                ->findOrFail($request->query('id_periode'));
        }

        $kandang = Kandang::query()
            ->whereIn('id_kandang', $this->accessibleKandangIds())
            ->find($periode?->id_kandang ?? $request->query('id_kandang'));
        if (! $kandang) {
            return response()->json(['status' => false, 'message' => 'Kandang tidak ditemukan']);
        }

        $periode ??= $kandang->periodes()
            ->where('status', 'aktif')
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('id_periode')
            ->first();

        if (! $periode) {
            return response()->json(['status' => false, 'message' => 'Periode kandang tidak ditemukan']);
        }

        $month = (int) $request->query('bulan', now()->month);
        $year = (int) $request->query('tahun', now()->year);
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();

        $kandangStart = $periode->tanggal_mulai?->copy()->startOfDay();
        $kandangEnd = $periode->tanggal_selesai?->copy()->endOfDay() ?: now()->endOfDay();

        $rangeStart = $kandangStart && $kandangStart->greaterThan($monthStart) ? $kandangStart : $monthStart;
        $rangeEnd = $kandangEnd && $kandangEnd->lessThan($monthEnd) ? $kandangEnd : $monthEnd;

        if ($rangeStart->greaterThan($rangeEnd)) {
            return response()->json([
                'status' => false,
                'message' => 'Tidak ada data pada bulan yang dipilih',
                'periode' => [
                    'mulai' => $rangeStart->toDateString(),
                    'sampai' => $rangeEnd->toDateString(),
                    'hari' => 0,
                    'bulan' => $month,
                    'tahun' => $year,
                ],
                'asumsi' => [
                    'butir_per_kolom' => 180,
                ],
                'ringkasan' => [
                    'populasi_awal' => (int) $periode->populasi_awal,
                    'ayam_hidup' => max(0, (int) $periode->populasi_awal - (int) $periode->total_kematian),
                    'total_kematian' => (int) $periode->total_kematian,
                    'total_pakan_kg' => 0,
                    'total_pakan_rp' => 0,
                    'total_produksi_kg' => 0,
                    'total_telur_butir' => 0,
                    'total_pendapatan_rp' => 0,
                    'total_biaya_rp' => 0,
                    'profit_rp' => 0,
                ],
                'kpi' => [
                    'hdp' => null,
                    'hhp' => null,
                    'egg_mass_g_per_hen_day' => null,
                    'avg_egg_weight_g' => null,
                    'feed_intake_g_per_hen_day' => null,
                    'fcr' => null,
                    'feed_cost_per_egg_rp' => null,
                    'mortality_pct' => null,
                    'livability_pct' => null,
                    'culling_rate_pct' => null,
                    'uniformity_pct' => null,
                    'cracked_egg_pct' => null,
                    'dirty_egg_pct' => null,
                    'shell_quality' => null,
                    'egg_grade' => null,
                    'cost_per_egg_rp' => null,
                    'revenue_per_egg_rp' => null,
                    'profit_margin_pct' => null,
                    'bep_egg_count' => null,
                    'notes' => [
                        'hdp' => 'Tidak ada data pada bulan yang dipilih.',
                        'hhp' => 'Tidak ada data pada bulan yang dipilih.',
                        'culling_rate' => 'Belum ada data afkir.',
                        'uniformity' => 'Belum ada data keseragaman berat badan.',
                        'cracked_egg' => 'Belum ada data telur retak.',
                        'dirty_egg' => 'Belum ada data telur kotor.',
                        'shell_quality' => 'Belum ada data kualitas cangkang.',
                        'egg_grade' => 'Belum ada data grade telur.',
                    ],
                ],
            ]);
        }

        $pakanRows = PakanTerpakai::query()
            ->where('id_periode', $periode->id_periode)
            ->whereBetween('tanggal', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get();

        $produksiRows = Produksi::query()
            ->where('id_periode', $periode->id_periode)
            ->whereBetween('tanggal', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get();

        $totalPakanKg = (float) $pakanRows->sum('jumlah_kg');
        $totalPakanCost = (float) $pakanRows->sum('total_harga');
        $totalEggCount = (float) $produksiRows->sum(function (Produksi $record) {
            $filledColumns = array_filter(
                Produksi::BERAT_COLUMNS,
                fn (string $column) => (float) ($record->{$column} ?? 0) > 0
            );

            return count($filledColumns) * 180;
        });

        $totalTelurKg = (float) $produksiRows->sum(function (Produksi $record) {
            return array_reduce(
                Produksi::BERAT_COLUMNS,
                fn (float $carry, string $column) => $carry + (float) ($record->{$column} ?? 0),
                0.0
            );
        });
        $totalRevenue = (float) $produksiRows->sum('total_harga');
        $operasionalRows = Operasional::query()
            ->where('id_periode', $periode->id_periode)
            ->whereBetween('tanggal', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get();
        $totalOperationalCost = (float) $operasionalRows->sum(fn (Operasional $row) => (float) $row->rak + (float) $row->gaji + (float) $row->lain);

        $periodDays = max(1, (int) $rangeStart->copy()->startOfDay()->diffInDays($rangeEnd->copy()->startOfDay()) + 1);
        $initialPopulation = (int) $periode->populasi_awal;
        $mortality = (int) $periode->total_kematian;
        $livePopulation = max(0, $initialPopulation - $mortality);
        $eggsPerColumn = 180;
        $avgEggWeightGrams = $totalEggCount > 0 ? ($totalTelurKg * 1000) / $totalEggCount : null;
        $hdp = $livePopulation > 0 ? ($totalEggCount / $livePopulation) * 100 : null;
        $hhp = $initialPopulation > 0 ? ($totalEggCount / $initialPopulation) * 100 : null;
        $feedCostPerEgg = $totalEggCount > 0 ? $totalPakanCost / $totalEggCount : null;
        $costPerEgg = $totalEggCount > 0 ? ($totalPakanCost + $totalOperationalCost) / $totalEggCount : null;
        $revenuePerEgg = $totalEggCount > 0 ? $totalRevenue / $totalEggCount : null;
        $profit = $totalRevenue - ($totalPakanCost + $totalOperationalCost);
        $profitMargin = $totalRevenue > 0 ? ($profit / $totalRevenue) * 100 : null;
        $breakEvenEggCount = $revenuePerEgg && $revenuePerEgg > 0 ? ($totalPakanCost + $totalOperationalCost) / $revenuePerEgg : null;
        $eggMassGPerHenDay = $livePopulation > 0 ? ($totalTelurKg * 1000) / $livePopulation : null;
        $feedIntakeGPerHenDay = $livePopulation > 0 ? ($totalPakanKg * 1000) / $livePopulation : null;
        $mortalityPct = $initialPopulation > 0 ? ($mortality / $initialPopulation) * 100 : null;
        $livabilityPct = $mortalityPct !== null ? max(0, 100 - $mortalityPct) : null;

        return response()->json([
            'status' => true,
            'id_kandang' => $kandang->id_kandang,
            'id_periode' => $periode->id_periode,
            'nama_kandang' => $kandang->nama_kandang,
            'nama_periode' => $periode->nama_periode,
            'periode' => [
                'mulai' => $rangeStart->toDateString(),
                'sampai' => $rangeEnd->toDateString(),
                'hari' => $periodDays,
                'bulan' => $month,
                'tahun' => $year,
            ],
            'asumsi' => [
                'butir_per_kolom' => $eggsPerColumn,
            ],
            'ringkasan' => [
                'populasi_awal' => $initialPopulation,
                'ayam_hidup' => $livePopulation,
                'total_kematian' => $mortality,
                'total_pakan_kg' => round($totalPakanKg, 2),
                'total_pakan_rp' => round($totalPakanCost, 0),
                'total_produksi_kg' => round($totalTelurKg, 2),
                'total_telur_butir' => round($totalEggCount, 0),
                'total_pendapatan_rp' => round($totalRevenue, 0),
                'total_biaya_rp' => round($totalPakanCost + $totalOperationalCost, 0),
                'profit_rp' => round($profit, 0),
            ],
            'kpi' => [
                'hdp' => $hdp !== null ? round($hdp, 2) : null,
                'hhp' => $hhp !== null ? round($hhp, 2) : null,
                'egg_mass_g_per_hen_day' => $eggMassGPerHenDay !== null ? round($eggMassGPerHenDay, 2) : null,
                'avg_egg_weight_g' => $avgEggWeightGrams !== null ? round($avgEggWeightGrams, 2) : null,
                'feed_intake_g_per_hen_day' => $feedIntakeGPerHenDay !== null ? round($feedIntakeGPerHenDay, 2) : null,
                'fcr' => $totalTelurKg > 0 ? round($totalPakanKg / $totalTelurKg, 3) : null,
                'feed_cost_per_egg_rp' => $feedCostPerEgg !== null ? round($feedCostPerEgg, 2) : null,
                'mortality_pct' => $mortalityPct !== null ? round($mortalityPct, 2) : null,
                'livability_pct' => $livabilityPct !== null ? round($livabilityPct, 2) : null,
                'culling_rate_pct' => null,
                'uniformity_pct' => null,
                'cracked_egg_pct' => null,
                'dirty_egg_pct' => null,
                'shell_quality' => null,
                'egg_grade' => null,
                'cost_per_egg_rp' => $costPerEgg !== null ? round($costPerEgg, 2) : null,
                'revenue_per_egg_rp' => $revenuePerEgg !== null ? round($revenuePerEgg, 2) : null,
                'profit_margin_pct' => $profitMargin !== null ? round($profitMargin, 2) : null,
                'bep_egg_count' => $breakEvenEggCount !== null ? round($breakEvenEggCount, 2) : null,
                'notes' => [
                    'hdp' => 'Estimasi memakai 180 butir per kolom berat yang terisi.',
                    'hhp' => 'Estimasi memakai 180 butir per kolom berat yang terisi.',
                    'culling_rate' => 'Belum ada data afkir.',
                    'uniformity' => 'Belum ada data keseragaman berat badan.',
                    'cracked_egg' => 'Belum ada data telur retak.',
                    'dirty_egg' => 'Belum ada data telur kotor.',
                    'shell_quality' => 'Belum ada data kualitas cangkang.',
                    'egg_grade' => 'Belum ada data grade telur.',
                ],
            ],
        ]);
    }
}
