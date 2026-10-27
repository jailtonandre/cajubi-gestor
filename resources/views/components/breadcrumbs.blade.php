@php
    $routeName = request()->route()?->getName();
    $pages = [
        'commercial-dashboard' => ['Início', 'home', 'Painel Comercial'],
        'products.index' => ['Início', 'home', 'Produtos'],
        'products.create' => ['Produtos', 'products.index', 'Novo produto'],
        'products.edit' => ['Produtos', 'products.index', 'Editar produto'],
        'supplies.index' => ['Início', 'home', 'Insumos'],
        'supplies.create' => ['Insumos', 'supplies.index', 'Novo insumo'],
        'supplies.show' => ['Insumos', 'supplies.index', 'Editar insumo'],
        'simulator' => ['Início', 'home', 'Simulador de Preços'],
        'tariffs.index' => ['Início', 'home', 'Tarifas dos Marketplaces'],
        'tariffs.create' => ['Tarifas dos Marketplaces', 'tariffs.index', 'Nova tarifa'],
        'tariffs.edit' => ['Tarifas dos Marketplaces', 'tariffs.index', 'Editar tarifa'],
        'scenarios' => ['Início', 'home', 'Cenários'],
        'viability' => ['Início', 'home', 'Viabilidade'],
        'cash-flow' => ['Início', 'home', 'Fluxo de Caixa'],
        'sales' => ['Início', 'home', 'Vendas'],
        'account.edit' => [null, null, 'Minha conta'],
        'account.security' => [null, null, 'Segurança e senha'],
    ];
    [$parent, $parentRoute, $current] = $pages[$routeName] ?? [null, null, $title ?? 'Gestor'];
@endphp
<nav aria-label="Breadcrumb" class="min-w-0 text-xs text-stone-500">
    <ol class="flex flex-wrap items-center gap-2">
        @if($parentRoute)
            <li><a href="{{ route($parentRoute) }}" wire:navigate class="hover:text-cajubi-700 hover:underline">{{ $parent }}</a></li>
            <li aria-hidden="true" class="text-stone-300">/</li>
        @endif
        <li aria-current="page" class="font-medium text-stone-700">{{ $current }}</li>
    </ol>
</nav>
