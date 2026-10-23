<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthenticationController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticationController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticationController::class, 'store'])->middleware('throttle:administrative-login')->name('login.store');
});

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::post('/logout', [AuthenticationController::class, 'destroy'])->name('logout');
    Route::get('/minha-conta', [AccountController::class, 'edit'])->name('account.edit');
    Route::patch('/minha-conta', [AccountController::class, 'update'])->name('account.update');
    Route::get('/seguranca', [AccountController::class, 'security'])->name('account.security');
    Route::put('/seguranca', [AccountController::class, 'updatePassword'])->middleware('throttle:6,1')->name('account.password');

    Route::get('/', fn () => to_route('commercial-dashboard'))->name('home');
    Route::livewire('/painel-comercial', 'pages::commercial-dashboard')->name('commercial-dashboard');
    Route::livewire('/produtos', 'pages::products.index')->name('products.index');
    Route::livewire('/produtos/novo', 'pages::products.form')->name('products.create');
    Route::livewire('/produtos/{product}/editar', 'pages::products.form')->name('products.edit');
    Route::livewire('/insumos', 'pages::supplies.index')->name('supplies.index');
    Route::livewire('/insumos/novo', 'pages::supplies.form')->name('supplies.create');
    Route::livewire('/insumos/{supply}', 'pages::supplies.form')->name('supplies.show');
    Route::livewire('/simulador', 'pages::simulator')->name('simulator');
    Route::livewire('/tarifas', 'pages::tariffs.index')->name('tariffs.index');
    Route::livewire('/tarifas/novo', 'pages::tariffs.index')->name('tariffs.create');
    Route::livewire('/tarifas/{profile}/editar', 'pages::tariffs.index')->name('tariffs.edit');
    Route::livewire('/cenarios', 'pages::scenarios')->name('scenarios');
    Route::livewire('/viabilidade', 'pages::viability')->name('viability');
    Route::livewire('/fluxo-de-caixa', 'pages::cash-flow')->name('cash-flow');
    Route::livewire('/vendas', 'pages::sales')->name('sales');
});
