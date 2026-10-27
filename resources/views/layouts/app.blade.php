<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Cadastro e custos' }} · cajubi</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body @class(['min-h-screen bg-stone-50 font-sans text-stone-800 antialiased', 'dashboard-shell' => request()->routeIs('commercial-dashboard', 'products.*', 'supplies.*', 'tariffs.*', 'simulator', 'scenarios', 'viability', 'cash-flow', 'sales'), 'catalog-shell' => request()->routeIs('products.*', 'supplies.*')])>
    <a href="#conteudo" class="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-white focus:p-4">Ir para o conteúdo</a>
    <div class="lg:grid lg:min-h-screen lg:grid-cols-[240px_1fr]">
        <aside class="admin-sidebar border-b border-stone-200 bg-white lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col lg:overflow-y-auto lg:border-r lg:border-b-0">
            <div class="flex items-center justify-between px-6 py-6 lg:px-8 lg:py-9">
                <a href="{{ route('commercial-dashboard') }}" wire:navigate class="flex items-center gap-2" aria-label="cajubi · Painel Comercial">
                        <span class="dashboard-brand-logo" style="--brand-logo: url('{{ asset('images/cajubi-gestor-logo.png') }}')"><span class="sr-only">cajubi gestor</span></span>
                </a>
                <span class="rounded bg-stone-100 px-2 py-1 text-[10px] font-semibold tracking-widest text-stone-500 lg:hidden">GESTOR</span>
            </div>
            <p class="hidden px-8 pb-5 text-[10px] font-semibold tracking-[0.2em] text-stone-400 lg:block">GESTÃO COMERCIAL</p>
            <nav aria-label="Navegação principal" class="flex flex-wrap gap-2 px-4 pb-4 lg:flex-col">
                <a href="{{ route('commercial-dashboard') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('commercial-dashboard', 'home')]) @if(request()->routeIs('commercial-dashboard', 'home')) aria-current="page" @endif>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m3 11 9-8 9 8M5 10v11h14V10M9 21v-7h6v7"/></svg>Painel Comercial
                </a>
                <a href="{{ route('products.index') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('products.*')]) @if(request()->routeIs('products.*')) aria-current="page" @endif>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m12 3 9 5v8l-9 5-9-5V8l9-5Zm0 10v8M3 8l9 5 9-5M7 5.8l9 5"/></svg>Produtos
                </a>
                <a href="{{ route('supplies.index') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('supplies.*')]) @if(request()->routeIs('supplies.*')) aria-current="page" @endif>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 16l9 5 9-5"/></svg>Insumos
                </a>
                <a href="{{ route('simulator') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('simulator')]) @if(request()->routeIs('simulator')) aria-current="page" @endif>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h2m4 0h2M8 15h2m4 0h2M8 18h2m4 0h2"/></svg>Simulador de Preços
                </a>
                <a href="{{ route('tariffs.index') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('tariffs.*')]) @if(request()->routeIs('tariffs.*')) aria-current="page" @endif>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 4h8l10 10-7 7L3 10V4Z"/><circle cx="7" cy="8" r="1"/></svg>Tarifas dos Marketplaces
                </a>
                <a href="{{ route('scenarios') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('scenarios')]) @if(request()->routeIs('scenarios')) aria-current="page" @endif>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4" width="7" height="16" rx="1"/><rect x="14" y="4" width="7" height="16" rx="1"/><path d="M5 9h3M5 13h3M16 9h3M16 13h3"/></svg>Cenários
                </a>
                <a href="{{ route('viability') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('viability')]) @if(request()->routeIs('viability')) aria-current="page" @endif>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5 3h14v18H5zM8 7h8M8 11h8M8 15l2 2 5-4"/></svg>Viabilidade
                </a>
                <a href="{{ route('cash-flow') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('cash-flow')]) @if(request()->routeIs('cash-flow')) aria-current="page" @endif>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 6h18v14H3zM3 10h18M7 3v5M17 3v5M7 15h3m4 0h3"/></svg>Fluxo de Caixa
                </a>
                <a href="{{ route('sales') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('sales')]) @if(request()->routeIs('sales')) aria-current="page" @endif>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 5h2l2 12h12l2-8H6M9 21h.01M18 21h.01M10 3h8m-4-2v4"/></svg>Vendas
                </a>
            </nav>
            <div class="sidebar-footer mt-auto px-4 pb-5">
                <details class="account-settings" @if(request()->routeIs('account.*')) open @endif>
                    <summary @class(['nav-link', 'nav-active' => request()->routeIs('account.*')])><x-ui-icon name="settings"/>Configurações <x-ui-icon name="chevron" class="settings-chevron ml-auto"/></summary>
                    <nav aria-label="Configurações" class="grid gap-1 pt-2 pl-4">
                        <a href="{{ route('account.edit') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('account.edit')]) @if(request()->routeIs('account.edit')) aria-current="page" @endif>Minha conta</a>
                        <a href="{{ route('account.security') }}" wire:navigate @class(['nav-link', 'nav-active' => request()->routeIs('account.security')]) @if(request()->routeIs('account.security')) aria-current="page" @endif>Segurança e senha</a>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="nav-link w-full text-left">Sair do sistema</button></form>
                    </nav>
                </details>
            </div>
        </aside>
        <div class="min-w-0">
            <header class="flex h-16 items-center justify-between border-b border-stone-200 bg-white px-6 lg:px-10">
                <x-breadcrumbs/>
                <div class="flex items-center gap-5 text-xs text-stone-500">
                    @if(request()->routeIs('commercial-dashboard'))<span class="dashboard-header-date"><x-ui-icon name="calendar"/>{{ now('America/Sao_Paulo')->translatedFormat('d \d\e F \d\e Y') }}</span>@endif
                </div>
            </header>
            <main id="conteudo" class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10 lg:py-10">
                {{ $slot }}
            </main>
        </div>
    </div>
    @livewireScripts
</body>
</html>

