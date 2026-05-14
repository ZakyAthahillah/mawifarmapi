<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kandang;
use App\Models\KandangMortalityLog;
use App\Models\KandangPeriode;
use App\Models\Operasional;
use App\Models\PakanTerpakai;
use App\Models\Produksi;
use Illuminate\Http\Request;

class InsightController extends Controller
{
    public function prediksi(Request $request)
    {
        $kandangIds = $this->accessibleKandangIds();

        if (empty($kandangIds)) {
            return response()->json([
                'status' => true,
                'message' => 'Belum ada kandang yang bisa dihitung.',
                'data' => $this->emptyInsight(),
            ]);
        }

        $today = now()->startOfDay();
        $from60 = $today->copy()->subDays(59)->toDateString();
        $from30 = $today->copy()->subDays(29)->toDateString();
        $from14 = $today->copy()->subDays(13)->toDateString();
        $from7 = $today->copy()->subDays(6)->toDateString();
        $prev7Start = $today->copy()->subDays(13)->toDateString();
        $prev7End = $today->copy()->subDays(7)->toDateString();

        $produksiRows = Produksi::query()
            ->whereIn('id_kandang', $kandangIds)
            ->whereDate('tanggal', '>=', $from60)
            ->orderBy('tanggal')
            ->get();
        $pakanRows = PakanTerpakai::query()
            ->whereIn('id_kandang', $kandangIds)
            ->whereDate('tanggal', '>=', $from30)
            ->get();
        $operasionalRows = Operasional::query()
            ->whereIn('id_kandang', $kandangIds)
            ->whereDate('tanggal', '>=', $from30)
            ->get();
        $mortalityRows = KandangMortalityLog::query()
            ->whereIn('id_kandang', $kandangIds)
            ->whereDate('tanggal', '>=', $prev7Start)
            ->get();

        $activePeriods = KandangPeriode::query()
            ->whereIn('id_kandang', $kandangIds)
            ->where('status', 'aktif')
            ->get();
        $kandangRows = Kandang::query()
            ->whereIn('id_kandang', $kandangIds)
            ->get();
        $liveBirds = $activePeriods->isNotEmpty()
            ? $activePeriods->sum(fn (KandangPeriode $row) => max(0, (int) $row->populasi_awal - (int) $row->total_kematian))
            : $kandangRows->sum(fn (Kandang $row) => max(0, (int) $row->populasi - (int) $row->total_kematian));

        $productionLast30 = (float) $produksiRows
            ->filter(fn (Produksi $row) => $row->tanggal->toDateString() >= $from30)
            ->sum(fn (Produksi $row) => $this->productionWeightTotal($row));
        $productionLast14 = (float) $produksiRows
            ->filter(fn (Produksi $row) => $row->tanggal->toDateString() >= $from14)
            ->sum(fn (Produksi $row) => $this->productionWeightTotal($row));
        $productionLast7 = (float) $produksiRows
            ->filter(fn (Produksi $row) => $row->tanggal->toDateString() >= $from7)
            ->sum(fn (Produksi $row) => $this->productionWeightTotal($row));
        $productionPrev7 = (float) $produksiRows
            ->filter(fn (Produksi $row) => $row->tanggal->toDateString() >= $prev7Start && $row->tanggal->toDateString() <= $prev7End)
            ->sum(fn (Produksi $row) => $this->productionWeightTotal($row));

        $dailyProductionAvg = $productionLast14 > 0 ? $productionLast14 / 14 : ($productionLast30 > 0 ? $productionLast30 / 30 : 0);
        $trendPct = $productionPrev7 > 0 ? (($productionLast7 - $productionPrev7) / $productionPrev7) * 100 : null;

        $feedLast30 = (float) $pakanRows->sum('jumlah_kg');
        $feedCostLast30 = (float) $pakanRows->sum('total_harga');
        $dailyFeedAvg = $feedLast30 > 0 ? $feedLast30 / 30 : 0;
        $operationalCostLast30 = (float) $operasionalRows->sum(fn (Operasional $row) => (float) $row->rak + (float) $row->gaji + (float) $row->lain);
        $revenueLast30 = (float) $produksiRows
            ->filter(fn (Produksi $row) => $row->tanggal->toDateString() >= $from30)
            ->sum('total_harga');
        $profitLast30 = $revenueLast30 - ($feedCostLast30 + $operationalCostLast30);
        $dailyProfitAvg = $profitLast30 !== 0.0 ? $profitLast30 / 30 : 0;
        $fcr = $productionLast30 > 0 ? $feedLast30 / $productionLast30 : null;

        $mortalityLast7 = (int) $mortalityRows
            ->filter(fn (KandangMortalityLog $row) => $row->tanggal->toDateString() >= $from7)
            ->sum('jumlah_kematian');
        $mortalityPrev7 = (int) $mortalityRows
            ->filter(fn (KandangMortalityLog $row) => $row->tanggal->toDateString() >= $prev7Start && $row->tanggal->toDateString() <= $prev7End)
            ->sum('jumlah_kematian');
        $mortalityPct7 = $liveBirds > 0 ? ($mortalityLast7 / $liveBirds) * 100 : null;
        $profitMargin = $revenueLast30 > 0 ? ($profitLast30 / $revenueLast30) * 100 : null;

        $anomalies = [];
        if ($trendPct !== null && $trendPct <= -12) {
            $anomalies[] = [
                'tone' => 'rose',
                'title' => 'Produksi turun',
                'text' => 'Produksi 7 hari terakhir turun '.$this->formatPercent(abs($trendPct)).' dibanding 7 hari sebelumnya.',
            ];
        }
        if ($fcr !== null && $fcr > 2.4) {
            $anomalies[] = [
                'tone' => 'amber',
                'title' => 'FCR tinggi',
                'text' => 'FCR 30 hari terakhir '.$this->formatNumber($fcr, 3).'. Pakan belum sebanding dengan produksi telur.',
            ];
        }
        if ($mortalityPrev7 > 0 && $mortalityLast7 > $mortalityPrev7 * 1.5) {
            $anomalies[] = [
                'tone' => 'rose',
                'title' => 'Kematian naik',
                'text' => 'Kematian 7 hari terakhir lebih tinggi dari minggu sebelumnya.',
            ];
        }
        if ($profitMargin !== null && $profitMargin < 15) {
            $anomalies[] = [
                'tone' => 'amber',
                'title' => 'Margin tipis',
                'text' => 'Margin profit 30 hari terakhir '.$this->formatPercent($profitMargin).'.',
            ];
        }

        $healthScore = max(0, min(100,
            100
            - max(0, (($fcr ?? 0) - 2.4) * 18)
            - max(0, (($mortalityPct7 ?? 0) - 0.8) * 18)
            - max(0, 20 - ($profitMargin ?? 0)) * 0.8
            - ($productionLast30 <= 0 ? 18 : 0)
            - ($trendPct !== null && $trendPct < 0 ? min(12, abs($trendPct) * 0.35) : 0)
        ));

        $recommendations = [];
        if ($dailyProductionAvg > 0) {
            $recommendations[] = [
                'tone' => $trendPct !== null && $trendPct < -8 ? 'amber' : 'green',
                'title' => 'Prediksi produksi',
                'text' => 'Estimasi produksi 7 hari ke depan '.$this->formatNumber($dailyProductionAvg * 7, 2).' kg'
                    .($trendPct !== null ? ', tren '.($trendPct >= 0 ? 'naik ' : 'turun ').$this->formatPercent(abs($trendPct)).'.' : '.'),
            ];
        }
        if ($dailyFeedAvg > 0) {
            $recommendations[] = [
                'tone' => 'green',
                'title' => 'Kebutuhan pakan',
                'text' => 'Siapkan sekitar '.$this->formatNumber($dailyFeedAvg * 7, 2).' kg pakan untuk 7 hari jika pola konsumsi tetap.',
            ];
        }
        if ($fcr !== null && $fcr > 2.4) {
            $recommendations[] = [
                'tone' => 'amber',
                'title' => 'Evaluasi efisiensi pakan',
                'text' => 'Cek kualitas pakan, jadwal pemberian, dan akurasi input produksi karena FCR melewati batas pantau.',
            ];
        }
        if ($mortalityPct7 !== null && $mortalityPct7 > 0.8) {
            $recommendations[] = [
                'tone' => 'rose',
                'title' => 'Pantau kesehatan kandang',
                'text' => 'Mortalitas 7 hari terakhir '.$this->formatPercent($mortalityPct7).' dari estimasi ayam hidup.',
            ];
        }
        if ($profitMargin !== null && $profitMargin < 15) {
            $recommendations[] = [
                'tone' => 'amber',
                'title' => 'Perbaiki margin',
                'text' => 'Tekan biaya pakan dan operasional, lalu cek kandang dengan FCR paling tinggi agar margin tidak semakin turun.',
            ];
        }
        if (empty($recommendations)) {
            $recommendations[] = [
                'tone' => 'green',
                'title' => 'Data belum cukup',
                'text' => 'Tambahkan data produksi, pakan, operasional, dan kematian harian agar prediksi lebih tajam.',
            ];
        }

        $kandangInsights = $this->buildKandangInsights(
            $kandangRows,
            $activePeriods,
            $produksiRows,
            $pakanRows,
            $operasionalRows,
            $mortalityRows,
            [
                'from30' => $from30,
                'from14' => $from14,
                'from7' => $from7,
                'prev7Start' => $prev7Start,
                'prev7End' => $prev7End,
            ],
        );
        $riskKandang = collect($kandangInsights)
            ->sortByDesc('risk_score')
            ->values()
            ->take(5)
            ->all();
        $championKandang = collect($kandangInsights)
            ->filter(fn (array $row) => ($row['metrics']['production_30_days_kg'] ?? 0) > 0)
            ->sortByDesc(fn (array $row) => $row['health']['score'])
            ->values()
            ->take(3)
            ->all();
        $rootCauses = collect($riskKandang)
            ->flatMap(fn (array $row) => collect($row['root_causes'])->map(fn (array $cause) => $cause + [
                'id_kandang' => $row['id_kandang'],
                'nama_kandang' => $row['nama_kandang'],
            ]))
            ->values()
            ->take(6)
            ->all();

        return response()->json([
            'status' => true,
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'summary' => [
                    'kandang_count' => count($kandangIds),
                    'active_period_count' => $activePeriods->count(),
                    'live_birds' => (int) $liveBirds,
                    'production_30_days_kg' => round($productionLast30, 2),
                    'feed_30_days_kg' => round($feedLast30, 2),
                    'profit_30_days_rp' => round($profitLast30, 0),
                    'fcr_30_days' => $fcr !== null ? round($fcr, 3) : null,
                ],
                'predictions' => [
                    'production_daily_kg' => round($dailyProductionAvg, 2),
                    'production_7_days_kg' => round($dailyProductionAvg * 7, 2),
                    'production_30_days_kg' => round($dailyProductionAvg * 30, 2),
                    'production_trend_pct' => $trendPct !== null ? round($trendPct, 2) : null,
                    'feed_daily_kg' => round($dailyFeedAvg, 2),
                    'feed_7_days_kg' => round($dailyFeedAvg * 7, 2),
                    'profit_7_days_rp' => round($dailyProfitAvg * 7, 0),
                    'profit_30_days_rp' => round($dailyProfitAvg * 30, 0),
                ],
                'health' => [
                    'score' => round($healthScore, 0),
                    'status' => $healthScore >= 85 ? 'Sehat' : ($healthScore >= 60 ? 'Perlu dipantau' : 'Perlu tindakan'),
                ],
                'anomalies' => $anomalies,
                'recommendations' => array_slice($recommendations, 0, 6),
                'early_warning' => [
                    'level' => $healthScore >= 85 ? 'aman' : ($healthScore >= 60 ? 'pantau' : 'tindakan'),
                    'label' => $healthScore >= 85 ? 'Aman' : ($healthScore >= 60 ? 'Pantau' : 'Tindakan'),
                    'text' => $this->warningText($healthScore, $riskKandang),
                ],
                'kandang_rankings' => [
                    'risk' => $riskKandang,
                    'champion' => $championKandang,
                ],
                'root_causes' => $rootCauses,
            ],
        ]);
    }

    private function emptyInsight(): array
    {
        return [
            'summary' => ['kandang_count' => 0, 'active_period_count' => 0, 'live_birds' => 0],
            'predictions' => [],
            'health' => ['score' => 0, 'status' => 'Belum ada data'],
            'anomalies' => [],
            'recommendations' => [[
                'tone' => 'green',
                'title' => 'Belum ada kandang',
                'text' => 'Tambahkan kandang dan mulai input data harian agar prediksi otomatis bisa dibuat.',
            ]],
            'early_warning' => [
                'level' => 'aman',
                'label' => 'Belum ada data',
                'text' => 'Tambahkan data kandang untuk mulai membuat peringatan otomatis.',
            ],
            'kandang_rankings' => ['risk' => [], 'champion' => []],
            'root_causes' => [],
        ];
    }

    private function buildKandangInsights($kandangRows, $activePeriods, $produksiRows, $pakanRows, $operasionalRows, $mortalityRows, array $ranges): array
    {
        return $kandangRows->map(function (Kandang $kandang) use ($activePeriods, $produksiRows, $pakanRows, $operasionalRows, $mortalityRows, $ranges) {
            $id = (int) $kandang->id_kandang;
            $period = $activePeriods->firstWhere('id_kandang', $id);
            $liveBirds = $period
                ? max(0, (int) $period->populasi_awal - (int) $period->total_kematian)
                : max(0, (int) $kandang->populasi - (int) $kandang->total_kematian);

            $kProduksi = $produksiRows->where('id_kandang', $id);
            $kPakan = $pakanRows->where('id_kandang', $id);
            $kOperasional = $operasionalRows->where('id_kandang', $id);
            $kMortality = $mortalityRows->where('id_kandang', $id);

            $production30 = (float) $kProduksi
                ->filter(fn (Produksi $row) => $row->tanggal->toDateString() >= $ranges['from30'])
                ->sum(fn (Produksi $row) => $this->productionWeightTotal($row));
            $production14 = (float) $kProduksi
                ->filter(fn (Produksi $row) => $row->tanggal->toDateString() >= $ranges['from14'])
                ->sum(fn (Produksi $row) => $this->productionWeightTotal($row));
            $production7 = (float) $kProduksi
                ->filter(fn (Produksi $row) => $row->tanggal->toDateString() >= $ranges['from7'])
                ->sum(fn (Produksi $row) => $this->productionWeightTotal($row));
            $productionPrev7 = (float) $kProduksi
                ->filter(fn (Produksi $row) => $row->tanggal->toDateString() >= $ranges['prev7Start'] && $row->tanggal->toDateString() <= $ranges['prev7End'])
                ->sum(fn (Produksi $row) => $this->productionWeightTotal($row));
            $trendPct = $productionPrev7 > 0 ? (($production7 - $productionPrev7) / $productionPrev7) * 100 : null;
            $dailyProductionAvg = $production14 > 0 ? $production14 / 14 : ($production30 > 0 ? $production30 / 30 : 0);

            $feed30 = (float) $kPakan->sum('jumlah_kg');
            $feed7 = (float) $kPakan
                ->filter(fn (PakanTerpakai $row) => $row->tanggal->toDateString() >= $ranges['from7'])
                ->sum('jumlah_kg');
            $feedCost30 = (float) $kPakan->sum('total_harga');
            $revenue30 = (float) $kProduksi
                ->filter(fn (Produksi $row) => $row->tanggal->toDateString() >= $ranges['from30'])
                ->sum('total_harga');
            $operationalCost30 = (float) $kOperasional->sum(fn (Operasional $row) => (float) $row->rak + (float) $row->gaji + (float) $row->lain);
            $profit30 = $revenue30 - ($feedCost30 + $operationalCost30);
            $profitMargin = $revenue30 > 0 ? ($profit30 / $revenue30) * 100 : null;
            $fcr = $production30 > 0 ? $feed30 / $production30 : null;
            $feedPerBird7 = $liveBirds > 0 ? ($feed7 * 1000) / $liveBirds : null;
            $productionPerBird30 = $liveBirds > 0 ? $production30 / $liveBirds : null;

            $mortality7 = (int) $kMortality
                ->filter(fn (KandangMortalityLog $row) => $row->tanggal->toDateString() >= $ranges['from7'])
                ->sum('jumlah_kematian');
            $mortalityPct7 = $liveBirds > 0 ? ($mortality7 / $liveBirds) * 100 : null;

            $score = max(0, min(100,
                100
                - max(0, (($fcr ?? 0) - 2.4) * 18)
                - max(0, (($mortalityPct7 ?? 0) - 0.8) * 18)
                - max(0, 18 - ($profitMargin ?? 0)) * 0.75
                - ($production30 <= 0 ? 18 : 0)
                - ($trendPct !== null && $trendPct < 0 ? min(15, abs($trendPct) * 0.4) : 0)
            ));

            $rootCauses = $this->rootCausesForKandang($trendPct, $fcr, $mortalityPct7, $profitMargin, $feed7, $production7, $productionPrev7);
            $action = $this->actionForKandang($rootCauses, $score, $dailyProductionAvg, $feed30);

            return [
                'id_kandang' => $id,
                'nama_kandang' => (string) $kandang->nama_kandang,
                'risk_score' => round(100 - $score, 0),
                'health' => [
                    'score' => round($score, 0),
                    'status' => $score >= 85 ? 'Sehat' : ($score >= 60 ? 'Perlu dipantau' : 'Perlu tindakan'),
                ],
                'metrics' => [
                    'live_birds' => $liveBirds,
                    'production_30_days_kg' => round($production30, 2),
                    'production_7_days_kg' => round($production7, 2),
                    'production_trend_pct' => $trendPct !== null ? round($trendPct, 2) : null,
                    'feed_30_days_kg' => round($feed30, 2),
                    'fcr_30_days' => $fcr !== null ? round($fcr, 3) : null,
                    'mortality_7_days_pct' => $mortalityPct7 !== null ? round($mortalityPct7, 2) : null,
                    'profit_30_days_rp' => round($profit30, 0),
                    'profit_margin_pct' => $profitMargin !== null ? round($profitMargin, 2) : null,
                    'feed_per_bird_7_days_g' => $feedPerBird7 !== null ? round($feedPerBird7, 2) : null,
                    'production_per_bird_30_days_kg' => $productionPerBird30 !== null ? round($productionPerBird30, 3) : null,
                ],
                'prediction' => [
                    'production_7_days_kg' => round($dailyProductionAvg * 7, 2),
                    'feed_7_days_kg' => round(($feed30 > 0 ? $feed30 / 30 : 0) * 7, 2),
                    'profit_7_days_rp' => round(($profit30 !== 0.0 ? $profit30 / 30 : 0) * 7, 0),
                ],
                'root_causes' => $rootCauses,
                'suggestion' => $action,
            ];
        })->values()->all();
    }

    private function rootCausesForKandang(?float $trendPct, ?float $fcr, ?float $mortalityPct7, ?float $profitMargin, float $feed7, float $production7, float $productionPrev7): array
    {
        $causes = [];

        if ($trendPct !== null && $trendPct <= -12 && $feed7 > 0) {
            $causes[] = [
                'tone' => 'amber',
                'title' => 'Produksi turun saat pakan tetap tercatat',
                'text' => 'Kemungkinan terkait efisiensi pakan, kesehatan ayam, stres kandang, atau kualitas pakan.',
            ];
        } elseif ($trendPct !== null && $trendPct <= -12) {
            $causes[] = [
                'tone' => 'amber',
                'title' => 'Produksi turun',
                'text' => 'Bandingkan catatan pakan dan kondisi kandang pada 7 hari terakhir.',
            ];
        }

        if ($fcr !== null && $fcr > 2.4) {
            $causes[] = [
                'tone' => 'amber',
                'title' => 'Konversi pakan melemah',
                'text' => 'Pakan yang digunakan belum berubah menjadi produksi telur secara efisien.',
            ];
        }

        if ($mortalityPct7 !== null && $mortalityPct7 > 0.8) {
            $causes[] = [
                'tone' => 'rose',
                'title' => 'Risiko kesehatan naik',
                'text' => 'Mortalitas 7 hari terakhir melewati batas pantau otomatis.',
            ];
        }

        if ($profitMargin !== null && $profitMargin < 15) {
            $causes[] = [
                'tone' => 'amber',
                'title' => 'Margin operasional rendah',
                'text' => 'Biaya pakan dan operasional perlu dibandingkan dengan kandang yang lebih efisien.',
            ];
        }

        if ($production7 <= 0 && $productionPrev7 <= 0) {
            $causes[] = [
                'tone' => 'green',
                'title' => 'Data produksi belum cukup',
                'text' => 'Prediksi akan lebih akurat setelah produksi harian terisi rutin.',
            ];
        }

        return array_slice($causes, 0, 3);
    }

    private function actionForKandang(array $rootCauses, float $score, float $dailyProductionAvg, float $feed30): array
    {
        if (collect($rootCauses)->contains(fn (array $cause) => $cause['tone'] === 'rose')) {
            return [
                'tone' => 'rose',
                'title' => 'Prioritaskan pengecekan kandang',
                'text' => 'Cek kematian, kondisi air, ventilasi, kepadatan, dan perubahan perilaku ayam hari ini.',
            ];
        }

        if ($score < 60) {
            return [
                'tone' => 'amber',
                'title' => 'Audit data dan pakan',
                'text' => 'Cocokkan input pakan, produksi, dan biaya. Cari selisih yang membuat performa turun.',
            ];
        }

        if ($dailyProductionAvg > 0 && $feed30 > 0) {
            return [
                'tone' => 'green',
                'title' => 'Pertahankan pola terbaik',
                'text' => 'Gunakan kandang ini sebagai pembanding untuk kandang lain dengan FCR atau produksi lebih lemah.',
            ];
        }

        return [
            'tone' => 'green',
            'title' => 'Lengkapi data harian',
            'text' => 'Input produksi, pakan, operasional, dan kematian secara rutin agar saran lebih presisi.',
        ];
    }

    private function warningText(float $healthScore, array $riskKandang): string
    {
        $topRisk = $riskKandang[0]['nama_kandang'] ?? null;

        if ($healthScore < 60) {
            return $topRisk
                ? "Perlu tindakan. Prioritaskan pengecekan {$topRisk} karena menjadi sumber risiko tertinggi."
                : 'Perlu tindakan. Ada indikator produksi, pakan, kesehatan, atau margin yang melemah.';
        }

        if ($healthScore < 85) {
            return $topRisk
                ? "Perlu dipantau. Mulai dari {$topRisk}, lalu bandingkan dengan kandang yang skornya lebih baik."
                : 'Perlu dipantau. Beberapa indikator mulai melemah dan perlu dicek sebelum membesar.';
        }

        return 'Kondisi umum aman. Tetap pantau kandang dengan tren turun agar masalah tidak terlambat terlihat.';
    }

    private function productionWeightTotal(Produksi $row): float
    {
        return array_reduce(
            Produksi::BERAT_COLUMNS,
            fn (float $carry, string $column) => $carry + (float) ($row->{$column} ?? 0),
            0.0
        );
    }

    private function formatNumber(float $value, int $digits = 0): string
    {
        return number_format($value, $digits, ',', '.');
    }

    private function formatPercent(float $value): string
    {
        return $this->formatNumber($value, 1).'%';
    }
}
