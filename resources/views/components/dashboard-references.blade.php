@props(['overview'])
        <div class="grid items-start gap-4 xl:grid-cols-2">
            <div class="panel grid gap-4 p-5"><div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold">Custos atuais dos produtos</h3><a href="{{ route('products.index') }}" wire:navigate class="text-xs text-cajubi-700 hover:underline">Ver catálogo →</a></div><p class="text-xs text-stone-500">Prévia de até 6 produtos ativos, em ordem alfabética; {{ $overview['product_count'] }} no cadastro.</p>
                <ul class="grid divide-y divide-stone-100">
                    @forelse($overview['products'] as $reference)
                        <li wire:key="premise-product-{{ $reference['product']->id }}" class="grid gap-2 py-3"><div class="flex flex-wrap justify-between gap-2"><a href="{{ route('products.edit', $reference['product']) }}" wire:navigate class="text-sm font-medium hover:text-cajubi-700">{{ $reference['product']->name }}</a>@if($reference['cost'] !== null)<span class="text-sm tabular-nums">R$ {{ \App\Services\DecimalPresentation::format($reference['cost'], 4) }} / un.</span>@endif</div>
                            @if($reference['warning'])
                                <p class="text-xs text-amber-800">{{ $reference['warning'] }} <a href="{{ route('products.edit', $reference['product']) }}" wire:navigate class="underline">Revisar composição</a></p>
                            @elseif($reference['earliest_reference'])
                                <p class="text-xs text-stone-500">Custos dos insumos registrados de {{ \Carbon\CarbonImmutable::parse($reference['earliest_reference'])->format('d/m/Y') }} a {{ \Carbon\CarbonImmutable::parse($reference['latest_reference'])->format('d/m/Y') }}.</p>
                            @else
                                <p class="text-xs text-stone-500">Data dos custos não disponível. <a href="{{ route('supplies.index') }}" wire:navigate class="underline">Consultar insumos</a></p>
                            @endif
                        </li>
                    @empty
                        <li class="py-3 text-sm text-stone-500">Nenhum produto ativo cadastrado. <a href="{{ route('products.create') }}" wire:navigate class="text-cajubi-700 underline">Cadastrar produto</a></li>
                    @endforelse
                </ul>
            </div>
            <div class="panel grid gap-4 p-5"><div class="flex flex-wrap justify-between gap-2"><h3 class="font-semibold">Tarifas para simulações</h3><a href="{{ route('tariffs.index') }}" wire:navigate class="text-xs text-cajubi-700 hover:underline">Ver tarifas →</a></div><p class="text-xs text-stone-500">{{ $overview['tariff_count'] }} regra(s) ativa(s), por marketplace e categoria.</p>
                <ul class="grid divide-y divide-stone-100">
                    @forelse($overview['tariffs'] as $reference)
                        @php($profile = $reference['profile'])
                        <li wire:key="premise-tariff-{{ $profile->id }}" class="grid gap-2 py-3">
                            <div class="flex flex-wrap items-start justify-between gap-2"><a href="{{ route('tariffs.edit', $profile) }}" wire:navigate class="text-sm font-medium hover:text-cajubi-700">{{ $profile->channel === 'mercado_livre' ? 'Mercado Livre' : 'Shopee' }} · {{ $profile->listing_type }}</a><span class="rounded bg-stone-100 px-2 py-1 text-xs text-stone-600">{{ $profile->confidence === 'confirmed' ? 'Confirmada no cadastro' : 'Estimada' }}</span></div>
                            <p class="text-xs text-stone-500">{{ $profile->category }}</p>
                            @if($profile->pricing_model === 'tiered')
                                <p class="text-xs">Tarifas por faixa de preço. <a href="{{ route('tariffs.edit', $profile) }}" wire:navigate class="text-cajubi-700 underline">Consultar limites e valores</a></p>
                            @else
                                <p class="text-xs">Comissão: {{ $profile->commission_percent === null ? 'não informada' : \App\Services\DecimalPresentation::quantity($profile->commission_percent).'%' }} · Fixa: {{ $profile->fixed_fee === null ? 'não informada' : 'R$ '.\App\Services\DecimalPresentation::format($profile->fixed_fee, 2) }} {{ $profile->charge_basis === 'order' ? 'por pedido' : 'por item' }}.</p>
                            @endif
                            <p class="text-xs text-stone-500">Referência: {{ $profile->observed_on?->format('d/m/Y') ?? 'não informada' }} · revisão: {{ $profile->review_on?->format('d/m/Y') ?? 'não informada' }}.</p>
                        </li>
                    @empty
                        <li class="py-3 text-sm text-stone-500">Nenhuma regra de tarifa ativa. <a href="{{ route('tariffs.index') }}" wire:navigate class="text-cajubi-700 underline">Consultar cadastro</a></li>
                    @endforelse
                </ul>
            </div>
        </div>

