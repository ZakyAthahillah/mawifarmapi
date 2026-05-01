<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FcrController;
use App\Http\Controllers\Api\KandangController;
use App\Http\Controllers\Api\OperasionalController;
use App\Http\Controllers\Api\PakanController;
use App\Http\Controllers\Api\ProduksiController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware(['jwt', 'throttle:120,1'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/get-kandang-user', [KandangController::class, 'search']);
    Route::get('/kandang', [KandangController::class, 'index']);
    Route::get('/kandang/periode', [KandangController::class, 'periods']);
    Route::post('/kandang', [KandangController::class, 'store']);
    Route::post('/kandang/periode', [KandangController::class, 'storePeriod']);
    Route::get('/kandang/show', [KandangController::class, 'showByOwner']);
    Route::get('/kandang/list', [KandangController::class, 'index']);
    Route::post('/kandang/tambah', [KandangController::class, 'store']);
    Route::post('/kandang/edit', [KandangController::class, 'updateFromRequest']);
    Route::post('/kandang/set-periode', [KandangController::class, 'setPeriodeFromRequest']);
    Route::post('/kandang/mati', [KandangController::class, 'addMortalityFromRequest']);
    Route::post('/kandang/koreksi-mati', [KandangController::class, 'correctMortality']);
    Route::post('/kandang/hapus', [KandangController::class, 'destroy']);
    Route::put('/kandang/{kandang}', [KandangController::class, 'update']);
    Route::patch('/kandang/{kandang}/periode', [KandangController::class, 'setPeriode']);
    Route::patch('/kandang/{kandang}/mati', [KandangController::class, 'addMortality']);
    Route::delete('/kandang/{kandang}', [KandangController::class, 'destroy']);

    Route::get('/pakan', [PakanController::class, 'index']);
    Route::get('/pakan/show', [PakanController::class, 'index']);
    Route::get('/pakan/get-kandang-by-user', [KandangController::class, 'search']);
    Route::post('/pakan', [PakanController::class, 'store']);
    Route::post('/pakan/tambah', [PakanController::class, 'store']);
    Route::post('/pakan/edit', [PakanController::class, 'updateFromRequest']);
    Route::post('/pakan/hapus', [PakanController::class, 'destroyFromRequest']);
    Route::put('/pakan/{pakan}', [PakanController::class, 'update']);
    Route::delete('/pakan/{pakan}', [PakanController::class, 'destroy']);

    Route::get('/produksi', [ProduksiController::class, 'index']);
    Route::get('/produksi/show', [ProduksiController::class, 'index']);
    Route::get('/produksi/get-kandang-by-user', [KandangController::class, 'search']);
    Route::post('/produksi', [ProduksiController::class, 'store']);
    Route::post('/produksi/tambah', [ProduksiController::class, 'store']);
    Route::post('/produksi/edit', [ProduksiController::class, 'updateFromRequest']);
    Route::post('/produksi/hapus', [ProduksiController::class, 'destroyFromRequest']);
    Route::put('/produksi/{produksi}', [ProduksiController::class, 'update']);
    Route::delete('/produksi/{produksi}', [ProduksiController::class, 'destroy']);

    Route::get('/operasional', [OperasionalController::class, 'index']);
    Route::get('/operasional/show', [OperasionalController::class, 'index']);
    Route::get('/operasional/get-kandang-by-user', [KandangController::class, 'search']);
    Route::get('/operasional/rekap-tahun', [OperasionalController::class, 'yearlyRecap']);
    Route::post('/operasional', [OperasionalController::class, 'store']);
    Route::post('/operasional/tambah', [OperasionalController::class, 'store']);
    Route::post('/operasional/edit', [OperasionalController::class, 'updateFromRequest']);
    Route::post('/operasional/hapus', [OperasionalController::class, 'destroyFromRequest']);
    Route::put('/operasional/{operasional}', [OperasionalController::class, 'update']);
    Route::delete('/operasional/{operasional}', [OperasionalController::class, 'destroy']);

    Route::get('/dashboard/kandang-summary', [DashboardController::class, 'kandangSummary']);
    Route::get('/dashboard/produksi-summary', [DashboardController::class, 'produksiSummary']);
    Route::get('/dashboard/produksi-bulanan', [DashboardController::class, 'monthlyProduction']);
    Route::get('/dashboard/produksi-tahunan', [DashboardController::class, 'yearlyProduction']);
    Route::get('/dashboard/card-kandang-summary', [DashboardController::class, 'kandangSummary']);
    Route::get('/dashboard/card-produksi-summary', [DashboardController::class, 'produksiSummary']);
    Route::get('/dashboard/chart-produksi-bulanan', [DashboardController::class, 'monthlyProduction']);
    Route::get('/dashboard/chart-produksi-tahunan', [DashboardController::class, 'yearlyProduction']);

    Route::get('/fcr/periode', [FcrController::class, 'periode']);
});

Route::middleware(['jwt', 'throttle:120,1', 'role:developer'])->prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::put('/{user}', [UserController::class, 'update']);
    Route::delete('/{user}', [UserController::class, 'destroy']);
});
