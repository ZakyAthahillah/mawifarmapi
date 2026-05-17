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

class FinanceController extends Controller
{
    public function summary(Request $request)
    {
        if (! in_array((string) auth()->user()?->role, ['developer', 'owner'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Menu Finansial hanya untuk Developer dan Owner.',
            ], 403);
        }

        $request->validate([
            'tanggal_mulai' => ['nullable', 'date'],
            'tanggal_selesai' => ['nullable', 'date'],
            'id_kandang' => ['nullable', 'integer'],
        ]);

        $end = $request->filled('tanggal_selesai')
            ? Carbon::parse($request->query('tanggal_selesai'))->endOfDay()
            : now()->endOfDay();
        $start = $request->filled('tanggal_mulai')
            ? Carbon::parse($request->query('tanggal_mulai'))->startOfDay()
            : $end->copy()->startOfMonth();

        if ($end->lessThan($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $kandangIds = collect($this->accessibleKandangIds());
        if ($request->filled('id_kandang')) {
            $selected = (int) $request->query('id_kandang');
            $kandangIds = $kandangIds->contains($selected) ? collect([$selected]) : collect();
        }

        if ($kandangIds->isEmpty()) {
            return response()->json([
                'status' => true,
                'data' => $this->emptyData($start, $end),
            ]);
        }

        $ids = $kandangIds->values()->all();
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $activeKandangIds = KandangPeriode::query()
            ->whereIn('id_kandang', $ids)
            ->where('status', 'aktif')
            ->pluck('id_kandang')
            ->map(fn ($id) => (int) $id)
            ->all();

        $produksiRows = Produksi::query()
            ->with('kandang:id_kandang,nama_kandang')
            ->whereIn('id_kandang', $ids)
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->orderBy('tanggal')
            ->get();
        $pakanRows = PakanTerpakai::query()
            ->with('kandang:id_kandang,nama_kandang')
            ->whereIn('id_kandang', $ids)
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->orderBy('tanggal')
            ->get();
        $operasionalRows = Operasional::query()
            ->with('kandang:id_kandang,nama_kandang')
            ->whereIn('id_kandang', $ids)
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->orderBy('tanggal')
            ->get();

        $revenue = (float) $produksiRows->sum('total_harga');
        $feedCost = (float) $pakanRows->sum('total_harga');
        $rackCost = (float) $operasionalRows->sum('rak');
        $salaryCost = (float) $operasionalRows->sum('gaji');
        $otherCost = (float) $operasionalRows->sum('lain');
        $expense = $feedCost + $rackCost + $salaryCost + $otherCost;
        $netCash = $revenue - $expense;
        $productionKg = (float) $produksiRows->sum(fn (Produksi $row) => $this->productionWeightTotal($row));
        $feedKg = (float) $pakanRows->sum('jumlah_kg');
        $days = max(1, (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
        $margin = $revenue > 0 ? ($netCash / $revenue) * 100 : null;
        $costRatio = $revenue > 0 ? ($expense / $revenue) * 100 : null;
        $fcr = $productionKg > 0 ? $feedKg / $productionKg : null;
        $avgPricePerKg = $productionKg > 0 ? $revenue / $productionKg : null;
        $costPerKg = $productionKg > 0 ? $expense / $productionKg : null;
        $feedCostPerKgEgg = $productionKg > 0 ? $feedCost / $productionKg : null;
        $profitPerKg = $productionKg > 0 ? $netCash / $productionKg : null;
        $breakEvenPricePerKg = $productionKg > 0 ? $expense / $productionKg : null;
        $breakEvenProductionKg = $avgPricePerKg && $avgPricePerKg > 0 ? $expense / $avgPricePerKg : null;
        $comparison = $this->periodComparison($ids, $start, $days, $revenue, $expense, $netCash);

        $trend = collect();
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateKey = $date->toDateString();
            $dayRevenue = (float) $produksiRows
                ->filter(fn (Produksi $row) => $row->tanggal->toDateString() === $dateKey)
                ->sum('total_harga');
            $dayFeed = (float) $pakanRows
                ->filter(fn (PakanTerpakai $row) => $row->tanggal->toDateString() === $dateKey)
                ->sum('total_harga');
            $dayOps = (float) $operasionalRows
                ->filter(fn (Operasional $row) => $row->tanggal->toDateString() === $dateKey)
                ->sum(fn (Operasional $row) => (float) $row->rak + (float) $row->gaji + (float) $row->lain);

            $trend->push([
                'tanggal' => $dateKey,
                'cash_in' => round($dayRevenue, 0),
                'cash_out' => round($dayFeed + $dayOps, 0),
                'net_cash' => round($dayRevenue - ($dayFeed + $dayOps), 0),
            ]);
        }

        $kandangRows = Kandang::query()
            ->whereIn('id_kandang', $ids)
            ->orderBy('nama_kandang')
            ->get()
            ->map(function (Kandang $kandang) use ($produksiRows, $pakanRows, $operasionalRows, $activeKandangIds) {
                $id = (int) $kandang->id_kandang;
                $isActivePeriod = in_array($id, $activeKandangIds, true);
                $kProduksi = $produksiRows->where('id_kandang', $id);
                $kPakan = $pakanRows->where('id_kandang', $id);
                $kOperasional = $operasionalRows->where('id_kandang', $id);
                $cashIn = (float) $kProduksi->sum('total_harga');
                $feedCost = (float) $kPakan->sum('total_harga');
                $operationalCost = (float) $kOperasional->sum(fn (Operasional $row) => (float) $row->rak + (float) $row->gaji + (float) $row->lain);
                $cashOut = $feedCost + $operationalCost;
                $productionKg = (float) $kProduksi->sum(fn (Produksi $row) => $this->productionWeightTotal($row));
                $feedKg = (float) $kPakan->sum('jumlah_kg');
                $profit = $cashIn - $cashOut;
                $avgPricePerKg = $productionKg > 0 ? $cashIn / $productionKg : null;
                $costPerKg = $productionKg > 0 ? $cashOut / $productionKg : null;
                $feedCostPerKgEgg = $productionKg > 0 ? $feedCost / $productionKg : null;
                $margin = $cashIn > 0 ? ($profit / $cashIn) * 100 : null;
                $fcr = $productionKg > 0 ? $feedKg / $productionKg : null;
                $riskScore = $this->kandangRiskScore($margin, $fcr, $profit, $cashIn, $productionKg);

                return [
                    'id_kandang' => $id,
                    'nama_kandang' => $kandang->nama_kandang,
                    'cash_in' => round($cashIn, 0),
                    'cash_out' => round($cashOut, 0),
                    'net_cash' => round($profit, 0),
                    'margin_pct' => $margin !== null ? round($margin, 2) : null,
                    'production_kg' => round($productionKg, 2),
                    'fcr' => $fcr !== null ? round($fcr, 3) : null,
                    'avg_price_per_kg' => $avgPricePerKg !== null ? round($avgPricePerKg, 0) : null,
                    'cost_per_kg' => $costPerKg !== null ? round($costPerKg, 0) : null,
                    'feed_cost_per_kg_egg' => $feedCostPerKgEgg !== null ? round($feedCostPerKgEgg, 0) : null,
                    'profit_per_kg' => $productionKg > 0 ? round($profit / $productionKg, 0) : null,
                    'break_even_price_per_kg' => $costPerKg !== null ? round($costPerKg, 0) : null,
                    'risk_level' => $this->riskLevel($riskScore),
                    'risk_score' => round($riskScore, 0),
                    'root_causes' => $this->kandangRootCauses($margin, $fcr, $profit, $cashIn, $productionKg, $feedCost, $cashOut),
                    'is_active_period' => $isActivePeriod,
                    'status' => $isActivePeriod ? $this->kandangStatus($profit, $cashIn, $productionKg) : 'Periode selesai',
                ];
            })
            ->values();

        $healthScore = $this->healthScore($margin, $costRatio, $fcr, $revenue, $netCash);
        $dailyNetAvg = $netCash / $days;
        $recommendations = $this->recommendations($margin, $costRatio, $fcr, $netCash, $feedCost, $expense, $kandangRows->all());
        $benchmark = $this->benchmark($kandangRows->all());

        return response()->json([
            'status' => true,
            'data' => [
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate,
                    'days' => $days,
                ],
                'summary' => [
                    'cash_in' => round($revenue, 0),
                    'cash_out' => round($expense, 0),
                    'net_cash' => round($netCash, 0),
                    'profit_margin_pct' => $margin !== null ? round($margin, 2) : null,
                    'cost_ratio_pct' => $costRatio !== null ? round($costRatio, 2) : null,
                    'production_kg' => round($productionKg, 2),
                    'feed_kg' => round($feedKg, 2),
                    'fcr' => $fcr !== null ? round($fcr, 3) : null,
                    'kandang_count' => count($ids),
                    'avg_price_per_kg' => $avgPricePerKg !== null ? round($avgPricePerKg, 0) : null,
                    'cost_per_kg' => $costPerKg !== null ? round($costPerKg, 0) : null,
                    'feed_cost_per_kg_egg' => $feedCostPerKgEgg !== null ? round($feedCostPerKgEgg, 0) : null,
                    'profit_per_kg' => $profitPerKg !== null ? round($profitPerKg, 0) : null,
                ],
                'comparison' => $comparison,
                'break_even' => [
                    'price_per_kg' => $breakEvenPricePerKg !== null ? round($breakEvenPricePerKg, 0) : null,
                    'production_kg' => $breakEvenProductionKg !== null ? round($breakEvenProductionKg, 2) : null,
                    'price_gap_per_kg' => $avgPricePerKg !== null && $breakEvenPricePerKg !== null ? round($avgPricePerKg - $breakEvenPricePerKg, 0) : null,
                    'production_gap_kg' => $breakEvenProductionKg !== null ? round($productionKg - $breakEvenProductionKg, 2) : null,
                ],
                'sensitivity' => [
                    'feed_price' => $this->feedSensitivity($revenue, $expense, $feedCost),
                    'selling_price' => $this->sellingPriceSensitivity($revenue, $expense),
                ],
                'safety_buffer' => $this->safetyBuffer($revenue, $expense, $netCash, $productionKg, $avgPricePerKg, $breakEvenProductionKg, $breakEvenPricePerKg, $feedCost),
                'benchmark' => $benchmark,
                'categories' => [
                    ['label' => 'Pakan', 'value' => round($feedCost, 0)],
                    ['label' => 'Rak', 'value' => round($rackCost, 0)],
                    ['label' => 'Gaji', 'value' => round($salaryCost, 0)],
                    ['label' => 'Lain', 'value' => round($otherCost, 0)],
                ],
                'forecast' => [
                    'net_cash_7_days' => round($dailyNetAvg * 7, 0),
                    'net_cash_30_days' => round($dailyNetAvg * 30, 0),
                    'cash_in_30_days' => round(($revenue / $days) * 30, 0),
                    'cash_out_30_days' => round(($expense / $days) * 30, 0),
                ],
                'health' => [
                    'score' => round($healthScore, 0),
                    'status' => $healthScore >= 82 ? 'Sehat' : ($healthScore >= 60 ? 'Perlu dipantau' : 'Perlu tindakan'),
                ],
                'trend' => $trend,
                'kandang' => $kandangRows,
                'recommendations' => $recommendations,
                'action_plan' => $this->actionPlan($recommendations, $kandangRows->all(), $benchmark),
            ],
        ]);
    }

    private function emptyData(Carbon $start, Carbon $end): array
    {
        return [
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => 0],
            'summary' => [
                'cash_in' => 0,
                'cash_out' => 0,
                'net_cash' => 0,
                'profit_margin_pct' => null,
                'cost_ratio_pct' => null,
                'production_kg' => 0,
                'feed_kg' => 0,
                'fcr' => null,
                'kandang_count' => 0,
                'avg_price_per_kg' => null,
                'cost_per_kg' => null,
                'feed_cost_per_kg_egg' => null,
                'profit_per_kg' => null,
            ],
            'comparison' => [],
            'break_even' => ['price_per_kg' => null, 'production_kg' => null, 'price_gap_per_kg' => null, 'production_gap_kg' => null],
            'sensitivity' => ['feed_price' => [], 'selling_price' => []],
            'safety_buffer' => [
                'status' => 'Belum ada data',
                'level' => 'empty',
                'production_drop_pct' => null,
                'price_drop_pct' => null,
                'feed_cost_increase_pct' => null,
                'cash_buffer' => 0,
                'text' => 'Data belum cukup untuk menghitung batas aman sebelum rugi.',
            ],
            'benchmark' => [],
            'categories' => [],
            'forecast' => ['net_cash_7_days' => 0, 'net_cash_30_days' => 0, 'cash_in_30_days' => 0, 'cash_out_30_days' => 0],
            'health' => ['score' => 0, 'status' => 'Belum ada data'],
            'trend' => [],
            'kandang' => [],
            'recommendations' => [[
                'tone' => 'green',
                'title' => 'Belum ada data',
                'text' => 'Tambahkan produksi, pakan, dan operasional agar analisis finance bisa dihitung.',
            ]],
            'action_plan' => [],
        ];
    }

    private function productionWeightTotal(Produksi $row): float
    {
        return array_reduce(
            Produksi::BERAT_COLUMNS,
            fn (float $carry, string $column) => $carry + (float) ($row->{$column} ?? 0),
            0.0
        );
    }

    private function healthScore(?float $margin, ?float $costRatio, ?float $fcr, float $revenue, float $netCash): float
    {
        return max(0, min(100,
            100
            - ($revenue <= 0 ? 22 : 0)
            - ($netCash < 0 ? 22 : 0)
            - max(0, 18 - ($margin ?? 0)) * 0.9
            - max(0, (($costRatio ?? 0) - 82)) * 0.45
            - max(0, (($fcr ?? 0) - 2.4) * 16)
        ));
    }

    private function kandangStatus(float $profit, float $cashIn, float $productionKg): string
    {
        if ($cashIn <= 0 && $productionKg <= 0) {
            return 'Belum ada produksi';
        }

        if ($profit < 0) {
            return 'Defisit';
        }

        return 'Surplus';
    }

    private function recommendations(?float $margin, ?float $costRatio, ?float $fcr, float $netCash, float $feedCost, float $expense, array $kandangRows): array
    {
        $items = [];

        if ($netCash < 0) {
            $items[] = [
                'tone' => 'rose',
                'title' => 'Cash flow negatif',
                'text' => 'Pengeluaran periode ini lebih besar dari pendapatan. Prioritaskan cek pakan dan operasional terbesar.',
            ];
        }

        if ($margin !== null && $margin < 15) {
            $items[] = [
                'tone' => 'amber',
                'title' => 'Margin menipis',
                'text' => 'Margin di bawah 15%. Bandingkan harga jual, biaya pakan, dan biaya operasional per kandang.',
            ];
        }

        if ($fcr !== null && $fcr > 2.4) {
            $items[] = [
                'tone' => 'amber',
                'title' => 'Efisiensi pakan turun',
                'text' => 'FCR melewati batas pantau 2,400. Cek konsistensi pakan dan akurasi produksi harian.',
            ];
        }

        if ($expense > 0 && ($feedCost / $expense) > 0.72) {
            $items[] = [
                'tone' => 'green',
                'title' => 'Pakan dominan',
                'text' => 'Mayoritas biaya berasal dari pakan. Optimasi kecil pada pakan akan berdampak besar pada kas.',
            ];
        }

        $risk = collect($kandangRows)
            ->filter(fn (array $row) => (float) $row['net_cash'] < 0)
            ->sortBy('net_cash')
            ->first();
        if ($risk) {
            $items[] = [
                'tone' => 'amber',
                'title' => 'Prioritas kandang',
                'text' => 'Mulai evaluasi dari '.$risk['nama_kandang'].' karena net cash periode ini paling rendah.',
            ];
        }

        if ($costRatio !== null && $costRatio <= 78 && $netCash >= 0) {
            $items[] = [
                'tone' => 'green',
                'title' => 'Pola keuangan stabil',
                'text' => 'Rasio biaya masih terkendali. Pertahankan input data rutin agar sinyal risiko cepat terlihat.',
            ];
        }

        return array_slice($items ?: [[
            'tone' => 'green',
            'title' => 'Data mulai terbaca',
            'text' => 'Belum ada alarm utama. Lengkapi data harian supaya rekomendasi semakin tajam.',
        ]], 0, 5);
    }

    private function periodComparison(array $ids, Carbon $start, int $days, float $revenue, float $expense, float $netCash): array
    {
        $previousEnd = $start->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays(max(0, $days - 1));
        $startDate = $previousStart->toDateString();
        $endDate = $previousEnd->toDateString();

        $previousRevenue = (float) Produksi::query()
            ->whereIn('id_kandang', $ids)
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->get()
            ->sum('total_harga');
        $previousFeed = (float) PakanTerpakai::query()
            ->whereIn('id_kandang', $ids)
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->get()
            ->sum('total_harga');
        $previousOps = (float) Operasional::query()
            ->whereIn('id_kandang', $ids)
            ->whereBetween('tanggal', [$startDate, $endDate])
            ->get()
            ->sum(fn (Operasional $row) => (float) $row->rak + (float) $row->gaji + (float) $row->lain);
        $previousExpense = $previousFeed + $previousOps;
        $previousNet = $previousRevenue - $previousExpense;

        return [
            'period' => ['start' => $startDate, 'end' => $endDate, 'days' => $days],
            'cash_in_pct' => $this->percentChange($revenue, $previousRevenue),
            'cash_out_pct' => $this->percentChange($expense, $previousExpense),
            'net_cash_pct' => $this->percentChange($netCash, $previousNet),
            'previous_cash_in' => round($previousRevenue, 0),
            'previous_cash_out' => round($previousExpense, 0),
            'previous_net_cash' => round($previousNet, 0),
        ];
    }

    private function percentChange(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.0001) {
            return abs($current) < 0.0001 ? 0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
    }

    private function feedSensitivity(float $revenue, float $expense, float $feedCost): array
    {
        return collect([5, 10, 15])->map(function (int $pct) use ($revenue, $expense, $feedCost) {
            $newExpense = $expense + ($feedCost * ($pct / 100));
            $profit = $revenue - $newExpense;

            return [
                'change_pct' => $pct,
                'net_cash' => round($profit, 0),
                'margin_pct' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : null,
            ];
        })->all();
    }

    private function sellingPriceSensitivity(float $revenue, float $expense): array
    {
        return collect([-5, -10, -15])->map(function (int $pct) use ($revenue, $expense) {
            $newRevenue = $revenue * (1 + ($pct / 100));
            $profit = $newRevenue - $expense;

            return [
                'change_pct' => $pct,
                'net_cash' => round($profit, 0),
                'margin_pct' => $newRevenue > 0 ? round(($profit / $newRevenue) * 100, 2) : null,
            ];
        })->all();
    }

    private function safetyBuffer(float $revenue, float $expense, float $netCash, float $productionKg, ?float $avgPricePerKg, ?float $breakEvenProductionKg, ?float $breakEvenPricePerKg, float $feedCost): array
    {
        $productionDropPct = $productionKg > 0 && $breakEvenProductionKg !== null
            ? (($productionKg - $breakEvenProductionKg) / $productionKg) * 100
            : null;
        $priceDropPct = $avgPricePerKg && $avgPricePerKg > 0 && $breakEvenPricePerKg !== null
            ? (($avgPricePerKg - $breakEvenPricePerKg) / $avgPricePerKg) * 100
            : null;
        $feedCostIncreasePct = $feedCost > 0
            ? ($netCash / $feedCost) * 100
            : null;

        $buffers = collect([$productionDropPct, $priceDropPct, $feedCostIncreasePct])
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (float) $value);
        $lowest = $buffers->isNotEmpty() ? $buffers->min() : null;
        $level = $lowest === null ? 'empty' : ($lowest >= 15 ? 'safe' : ($lowest >= 5 ? 'thin' : 'risk'));
        $status = match ($level) {
            'safe' => 'Aman',
            'thin' => 'Tipis',
            'risk' => 'Rawan rugi',
            default => 'Belum ada data',
        };

        return [
            'status' => $status,
            'level' => $level,
            'production_drop_pct' => $productionDropPct !== null ? round($productionDropPct, 2) : null,
            'price_drop_pct' => $priceDropPct !== null ? round($priceDropPct, 2) : null,
            'feed_cost_increase_pct' => $feedCostIncreasePct !== null ? round($feedCostIncreasePct, 2) : null,
            'cash_buffer' => round($netCash, 0),
            'text' => $this->safetyBufferText($level, $productionDropPct, $priceDropPct, $feedCostIncreasePct),
        ];
    }

    private function safetyBufferText(string $level, ?float $productionDropPct, ?float $priceDropPct, ?float $feedCostIncreasePct): string
    {
        if ($level === 'empty') {
            return 'Data belum cukup untuk menghitung batas aman sebelum rugi.';
        }

        $parts = [];
        if ($productionDropPct !== null) {
            $parts[] = 'produksi turun '.number_format(max(0, $productionDropPct), 1, ',', '.').'%';
        }
        if ($priceDropPct !== null) {
            $parts[] = 'harga jual turun '.number_format(max(0, $priceDropPct), 1, ',', '.').'%';
        }
        if ($feedCostIncreasePct !== null) {
            $parts[] = 'biaya pakan naik '.number_format(max(0, $feedCostIncreasePct), 1, ',', '.').'%';
        }

        $prefix = match ($level) {
            'safe' => 'Masih ada ruang aman sebelum rugi jika',
            'thin' => 'Ruang aman mulai tipis jika',
            default => 'Rawan rugi jika',
        };

        return $prefix.' '.implode(', atau ', array_slice($parts, 0, 3)).'.';
    }

    private function benchmark(array $kandangRows): array
    {
        $eligible = collect($kandangRows)->filter(fn (array $row) => ($row['production_kg'] ?? 0) > 0);
        $bestCost = $eligible->sortBy('cost_per_kg')->first();
        $bestMargin = $eligible->sortByDesc(fn (array $row) => $row['margin_pct'] ?? -999)->first();

        return [
            'best_cost_kandang' => $bestCost ? [
                'nama_kandang' => $bestCost['nama_kandang'],
                'cost_per_kg' => $bestCost['cost_per_kg'],
                'gap_to_average' => $this->benchmarkGap($bestCost, $eligible->avg('cost_per_kg')),
            ] : null,
            'best_margin_kandang' => $bestMargin ? [
                'nama_kandang' => $bestMargin['nama_kandang'],
                'margin_pct' => $bestMargin['margin_pct'],
            ] : null,
        ];
    }

    private function benchmarkGap(?array $row, mixed $average): ?float
    {
        if (! $row || $average === null || ! isset($row['cost_per_kg'])) {
            return null;
        }

        return round((float) $average - (float) $row['cost_per_kg'], 0);
    }

    private function kandangRiskScore(?float $margin, ?float $fcr, float $profit, float $cashIn, float $productionKg): float
    {
        return max(0, min(100,
            ($cashIn <= 0 ? 24 : 0)
            + ($productionKg <= 0 ? 18 : 0)
            + ($profit < 0 ? 28 : 0)
            + max(0, 16 - ($margin ?? 0)) * 1.1
            + max(0, (($fcr ?? 0) - 2.4) * 16)
        ));
    }

    private function riskLevel(float $riskScore): string
    {
        if ($riskScore >= 70) {
            return 'Risiko tinggi';
        }

        if ($riskScore >= 38) {
            return 'Pantau';
        }

        return 'Aman';
    }

    private function kandangRootCauses(?float $margin, ?float $fcr, float $profit, float $cashIn, float $productionKg, float $feedCost, float $cashOut): array
    {
        $causes = [];

        if ($profit < 0) {
            $causes[] = ['tone' => 'rose', 'title' => 'Defisit', 'text' => 'Cash out kandang lebih besar dari cash in pada periode ini.'];
        }

        if ($cashIn <= 0 && $productionKg <= 0) {
            $causes[] = ['tone' => 'amber', 'title' => 'Produksi belum terbaca', 'text' => 'Tidak ada produksi bernilai uang pada periode ini.'];
        }

        if ($margin !== null && $margin < 15) {
            $causes[] = ['tone' => 'amber', 'title' => 'Margin rendah', 'text' => 'Margin di bawah 15%, biaya terlalu dekat dengan pendapatan.'];
        }

        if ($fcr !== null && $fcr > 2.4) {
            $causes[] = ['tone' => 'amber', 'title' => 'FCR tinggi', 'text' => 'Pakan belum sebanding dengan produksi telur.'];
        }

        if ($cashOut > 0 && ($feedCost / $cashOut) > 0.72) {
            $causes[] = ['tone' => 'green', 'title' => 'Biaya pakan dominan', 'text' => 'Pakan menjadi komponen biaya terbesar kandang ini.'];
        }

        return array_slice($causes, 0, 3);
    }

    private function actionPlan(array $recommendations, array $kandangRows, array $benchmark): array
    {
        $riskRows = collect($kandangRows)
            ->sortByDesc('risk_score')
            ->take(3)
            ->values();

        $actions = $riskRows->map(fn (array $row, int $index) => [
            'priority' => $index + 1,
            'title' => 'Evaluasi '.$row['nama_kandang'],
            'text' => ($row['root_causes'][0]['text'] ?? 'Bandingkan cash in, cash out, FCR, dan biaya per kg telur.')
                .' Net cash: '.number_format((float) $row['net_cash'], 0, ',', '.').'.',
        ])->all();

        if (empty($actions) && ! empty($recommendations)) {
            $actions[] = [
                'priority' => 1,
                'title' => $recommendations[0]['title'],
                'text' => $recommendations[0]['text'],
            ];
        }

        if (($benchmark['best_cost_kandang']['nama_kandang'] ?? null) && count($actions) < 3) {
            $actions[] = [
                'priority' => count($actions) + 1,
                'title' => 'Gunakan benchmark biaya',
                'text' => 'Bandingkan kandang lain dengan '.$benchmark['best_cost_kandang']['nama_kandang'].' yang punya biaya/kg paling efisien.',
            ];
        }

        return array_slice($actions, 0, 4);
    }
}
