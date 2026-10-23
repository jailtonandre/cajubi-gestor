<?php

use App\Models\Product;
use App\Models\TariffProfile;
use App\Services\TariffCatalog;
use App\Services\DecimalPresentation;
use App\Services\TariffPricing;
use App\Services\ProductCostCalculator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Simulador de preços')] class extends Component
{
    public string $product_id = '';
    public string $mode = 'simulate';
    public string $desired_contribution = '';
    public string $channel = '';
    public string $tariff_profile_id = '';
    #[Locked]
    public ?int $tariff_revision_id = null;
    public string $sale_price = '';
    public string $commission_percent = '';
    public string $fixed_fee = '';
    public bool $commission_manual = false;
    public bool $fixed_manual = false;
    public string $seller_shipping = '';
    public string $other_variable_expenses = '';

    protected TariffPricing $simulator;
    protected ProductCostCalculator $costCalculator;
    protected TariffCatalog $tariffCatalog;

    public function boot(TariffPricing $simulator, ProductCostCalculator $costCalculator, TariffCatalog $tariffCatalog): void
    {
        $this->simulator = $simulator;
        $this->costCalculator = $costCalculator;
        $this->tariffCatalog = $tariffCatalog;
    }

    public function updatedChannel(): void
    {
        $this->reset('commission_percent', 'fixed_fee', 'seller_shipping', 'other_variable_expenses');
        $this->reset('tariff_profile_id', 'tariff_revision_id');
        $this->reset('commission_manual', 'fixed_manual');
    }

    public function updatedCommissionPercent(): void { $this->commission_manual = true; }
    public function updatedFixedFee(): void { $this->fixed_manual = true; }
    public function updatedSalePrice(): void { $this->refreshTariffs(); }
    public function updatedMode(): void { $this->refreshTariffs(); }

    public function refreshTariffs(): void
    {
        if ($this->tariff_profile_id === '') { return; }
        try {
            $selection = $this->tariffCatalog->simulationParameters($this->tariff_profile_id, $this->channel, $this->mode === 'simulate' ? DecimalPresentation::input($this->sale_price) : null);
            if ($selection['profile']->pricing_model !== 'tiered') { return; }
            foreach (['commission_percent' => 'commission_manual', 'fixed_fee' => 'fixed_manual'] as $field => $manual) {
                if (! $this->$manual) { $this->$field = $selection[$field] !== null ? str_replace('.', ',', $selection[$field]) : ''; }
            }
        } catch (ValidationException) {}
        unset($this->selectedTariff);
    }

    public function updatedTariffProfileId(): void
    {
        $this->applyTariffProfile();
    }

    public function applyTariffProfile(): void
    {
        $this->reset('commission_percent', 'fixed_fee', 'tariff_revision_id');
        $this->reset('commission_manual', 'fixed_manual');
        if ($this->tariff_profile_id === '') {
            return;
        }
        try {
            $selection = $this->tariffCatalog->simulationParameters($this->tariff_profile_id, $this->channel, $this->mode === 'simulate' ? DecimalPresentation::input($this->sale_price) : null);
            $this->commission_percent = $selection['commission_percent'] !== null ? str_replace('.', ',', $selection['commission_percent']) : '';
            $this->fixed_fee = $selection['fixed_fee'] !== null ? str_replace('.', ',', $selection['fixed_fee']) : '';
            $this->tariff_revision_id = $selection['revision_id'];
        } catch (ValidationException) {
            // The selection error is displayed by the computed simulation state.
        }
        unset($this->selectedTariff);
    }

    #[Computed]
    public function tariffProfiles(): \Illuminate\Database\Eloquent\Collection
    {
        return TariffProfile::query()->where('channel', $this->channel)->where('is_active', true)->orderBy('category')->orderBy('listing_type')->get();
    }

    #[Computed]
    public function selectedTariff(): ?array
    {
        if ($this->tariff_profile_id === '') {
            return null;
        }
        try {
            $selection = $this->tariffCatalog->simulationParameters($this->tariff_profile_id, $this->channel, $this->mode === 'simulate' ? DecimalPresentation::input($this->sale_price) : null);
            if ($this->tariff_revision_id !== null && $selection['revision_id'] !== $this->tariff_revision_id) {
                $selection['warnings'][] = 'A regra foi alterada desde a seleção. Reaplique a regra para carregar os novos valores.';
            }

            return $selection + ['error' => null];
        } catch (ValidationException $exception) {
            return ['error' => $exception->validator->errors()->first()];
        }
    }

    #[Computed]
    public function products(): \Illuminate\Database\Eloquent\Collection
    {
        return Product::query()->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function productCost(): array
    {
        if (! preg_match('/\A[1-9][0-9]*\z/', $this->product_id)) {
            return ['product' => null, 'value' => null, 'warning' => null];
        }
        $product = Product::query()->find($this->product_id);
        if (! $product) {
            return ['product' => null, 'value' => null, 'warning' => 'Produto não encontrado. Selecione outro produto.'];
        }
        try {
            return ['product' => $product, 'value' => $this->costCalculator->calculate($product), 'warning' => null];
        } catch (ValidationException $exception) {
            return ['product' => $product, 'value' => null, 'warning' => $exception->validator->errors()->first()];
        }
    }

    #[Computed]
    public function simulation(): array
    {
        try {
            if ($this->selectedTariff && $this->selectedTariff['error']) {
                throw ValidationException::withMessages(['tariff_profile_id' => $this->selectedTariff['error']]);
            }
            if (! in_array($this->mode, ['simulate', 'find_price'], true)) {
                throw ValidationException::withMessages(['mode' => 'Selecione um modo de simulação válido.']);
            }
            $parameters = [
                'product_id' => $this->product_id,
                'tariff_profile_id' => $this->tariff_profile_id,
                'commission_manual' => $this->commission_manual,
                'fixed_manual' => $this->fixed_manual,
                'channel' => $this->channel,
                'sale_price' => DecimalPresentation::input($this->sale_price),
                'desired_contribution' => DecimalPresentation::input($this->desired_contribution),
                'commission_percent' => DecimalPresentation::input($this->commission_percent),
                'fixed_fee' => DecimalPresentation::input($this->fixed_fee),
                'seller_shipping' => DecimalPresentation::input($this->seller_shipping),
                'other_variable_expenses' => DecimalPresentation::input($this->other_variable_expenses),
            ];

            return ['result' => $this->mode === 'find_price' ? $this->simulator->findPrice($parameters) : $this->simulator->simulate($parameters), 'errors' => []];
        } catch (ValidationException $exception) {
            return ['result' => null, 'errors' => $exception->errors()];
        }
    }
};
?>

<div class="grid gap-7">
    <div class="grid gap-2">
        <p class="eyebrow">Precificação comercial</p>
        <h1 class="page-title">Simulador de preços</h1>
        <p class="text-sm text-stone-500">Entenda quanto sobra em cada venda antes de definir seu preço.</p>
    </div>
    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <section class="panel p-6 sm:p-7" aria-labelledby="simulacao">
            <h2 id="simulacao" class="text-lg font-semibold">Simulação</h2>
            <p class="mt-1 text-xs leading-5 text-stone-500">Preencha todos os campos. Se uma despesa não se aplicar, informe 0.</p>
            <div class="mt-6 grid gap-5">
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium text-stone-700">O que você quer calcular?</legend>
                    <div class="grid gap-2 rounded-lg bg-stone-50 p-2 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-center gap-2 rounded-md border border-stone-200 bg-white p-3 text-sm"><input type="radio" wire:model.live="mode" value="simulate" class="accent-cajubi-700"> Simular preço</label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-md border border-stone-200 bg-white p-3 text-sm"><input type="radio" wire:model.live="mode" value="find_price" class="accent-cajubi-700"> Encontrar preço de venda</label>
                    </div>
                </fieldset>
                <x-field label="Produto *" for="product_id">
                    <select id="product_id" wire:model.live="product_id" class="field">
                        <option value="">Selecione um produto</option>
                        @foreach($this->products as $product)
                            <option wire:key="simulator-product-{{ $product->id }}" value="{{ $product->id }}">{{ $product->name }} · {{ $product->sku }}{{ $product->status === 'inactive' ? ' (inativo)' : '' }}</option>
                        @endforeach
                    </select>
                </x-field>
                @if($this->products->isEmpty())
                    <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Nenhum produto cadastrado. <a href="{{ route('products.create') }}" wire:navigate class="font-semibold underline">Cadastre um produto</a> para simular.</p>
                @endif
                @if($this->productCost['value'] !== null)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-cajubi-50 px-4 py-3 text-sm text-cajubi-800">
                        <span>Custo atual do produto</span><x-money :value="$this->productCost['value']" class="font-semibold"/>
                    </div>
                @elseif($this->productCost['warning'])
                    <div role="alert" class="rounded-lg bg-amber-50 p-3 text-sm leading-6 text-amber-900">
                        {{ $this->productCost['warning'] }}
                        @if($this->productCost['product'])<a href="{{ route('products.edit', $this->productCost['product']) }}" wire:navigate class="font-semibold underline">Revisar composição</a>@endif
                    </div>
                @endif
                <x-field label="Canal *" for="channel" hint="Selecione uma regra ou informe as tarifas manualmente. Ao trocar de canal, informe novamente as taxas e despesas.">
                    <select id="channel" wire:model.live="channel" class="field"><option value="">Selecione o canal</option><option value="mercado_livre">Mercado Livre</option><option value="shopee">Shopee</option></select>
                </x-field>
                <x-field label="Regra de tarifa" for="tariff_profile_id" hint="Opcional. Carrega apenas comissão e tarifa fixa conhecidas; o frete continua individual.">
                    <select id="tariff_profile_id" wire:model.live="tariff_profile_id" class="field" @disabled($channel === '')>
                        <option value="">Informar tarifas manualmente</option>
                        @foreach($this->tariffProfiles as $tariff)
                            <option wire:key="tariff-option-{{ $tariff->id }}" value="{{ $tariff->id }}">{{ $tariff->listing_type }} · {{ $tariff->category }} · {{ $tariff->confidence === 'confirmed' ? 'Confirmado' : 'Estimado' }}</option>
                        @endforeach
                    </select>
                </x-field>
                @if($this->selectedTariff && ! $this->selectedTariff['error'])
                    @php($selected = $this->selectedTariff)
                    <div class="grid gap-3 rounded-lg border border-stone-200 p-4 text-xs leading-5">
                        <p class="font-semibold">{{ $selected['profile']->confidence === 'confirmed' ? 'Confirmado' : 'Estimado' }} · {{ $selected['profile']->listing_type }}</p>
                        @if($selected['profile']->pricing_model === 'tiered')
                            <p class="text-cajubi-700">Tarifas por faixa de preço. {{ $mode === 'find_price' ? 'O cálculo compara as faixas para encontrar o menor preço que atende à meta. Campos em branco usam os valores cadastrados por faixa.' : 'As tarifas acompanham o preço informado.' }}</p>
                            @if($selected['tier'])<p>Faixa: <x-money :value="$selected['tier']['min_price']"/> até @if($selected['tier']['max_price'] !== null)<x-money :value="$selected['tier']['max_price']"/>@else sem limite @endif</p>@endif
                        @endif
                        @if($commission_manual || $fixed_manual)<p class="text-amber-900">Ajuste manual ativo. Reaplique a regra para voltar às tarifas automáticas.</p>@endif
                        <p class="whitespace-pre-line text-stone-600">Fonte: {{ $selected['profile']->source }}</p>
                        @if($selected['profile']->conditions)<p class="whitespace-pre-line text-stone-600">Condições: {{ $selected['profile']->conditions }}</p>@endif
                        @if($selected['warnings'])<ul class="grid gap-1 text-amber-900">@foreach($selected['warnings'] as $warning)<li wire:key="tariff-warning-{{ $loop->index }}">{{ $warning }}</li>@endforeach</ul>@endif
                        <p class="text-stone-500">Ajustes manuais afetam apenas esta simulação. A regra original não será alterada.</p>
                        <button type="button" wire:click="applyTariffProfile" class="w-fit font-medium text-cajubi-700 underline">Reaplicar valores da regra</button>
                    </div>
                @endif
                @if($mode === 'find_price')
                <x-field wire:key="target-field" label="Contribuição desejada por unidade (R$) *" for="desired_contribution" hint="Quanto você deseja que sobre após o custo e as despesas variáveis.">
                    <input id="desired_contribution" wire:model.live.debounce.400ms="desired_contribution" class="field" inputmode="decimal" autocomplete="off">
                </x-field>
                @else
                <x-field wire:key="price-field" label="Preço de venda (R$) *" for="sale_price">
                    <input id="sale_price" wire:model.live.debounce.400ms="sale_price" class="field" inputmode="decimal" autocomplete="off">
                </x-field>
                @endif
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field label="Comissão (%) *" for="commission_percent"><input id="commission_percent" wire:model.live.debounce.400ms="commission_percent" class="field" inputmode="decimal" autocomplete="off"></x-field>
                    <x-field label="Tarifa fixa por venda (R$) *" for="fixed_fee"><input id="fixed_fee" wire:model.live.debounce.400ms="fixed_fee" class="field" inputmode="decimal" autocomplete="off"></x-field>
                    <x-field label="Frete do vendedor (R$) *" for="seller_shipping" hint="Valor do frete suportado por você."><input id="seller_shipping" wire:model.live.debounce.400ms="seller_shipping" class="field" inputmode="decimal" autocomplete="off"></x-field>
                    <x-field label="Outras despesas variáveis (R$) *" for="other_variable_expenses" hint="Total por venda, sem incluir despesas fixas."><input id="other_variable_expenses" wire:model.live.debounce.400ms="other_variable_expenses" class="field" inputmode="decimal" autocomplete="off"></x-field>
                </div>
                <p class="border-t border-stone-100 pt-4 text-xs leading-5 text-stone-500">Use vírgula e até 4 casas decimais. Informe as condições aplicáveis à sua venda, sem repetir o frete em outras despesas.</p>
            </div>
        </section>

        <section class="panel overflow-hidden xl:sticky xl:top-6" aria-labelledby="resultado" aria-live="polite" aria-atomic="true">
            <div class="h-1 bg-cajubi-yellow"></div>
            <div class="grid gap-6 p-6 sm:p-7">
                <h2 id="resultado" class="text-lg font-semibold">Resultado</h2>
                <p wire:loading role="status" class="text-sm text-stone-500">Atualizando simulação…</p>
                <div wire:loading.remove class="grid gap-6">
                    @if($this->simulation['result'])
                        @php($result = $this->simulation['result'])
                        <div @class(['grid gap-3 rounded-xl border p-6', 'border-cajubi-200 bg-cajubi-50 text-cajubi-800' => $result['tone'] === 'positive', 'border-red-200 bg-red-50 text-red-800' => $result['tone'] === 'negative', 'border-stone-200 bg-stone-50 text-stone-700' => $result['tone'] === 'zero'])>
                            <p class="text-sm font-medium">{{ $mode === 'find_price' ? 'Preço de venda sugerido' : 'Quanto sobra por venda' }}</p>
                            <x-money :value="$mode === 'find_price' ? $result['suggested_price'] : $result['contribution']" class="text-4xl font-semibold tracking-tight"/>
                            @if($mode === 'find_price')
                                <p class="text-sm">Quanto sobra por venda: <x-money :value="$result['contribution']" class="font-semibold"/></p>
                                <p class="text-xs">Meta por unidade: <x-money :value="$result['desired_contribution']"/> · atendida</p>
                            @endif
                            <p class="text-lg font-semibold">{{ \App\Services\DecimalPresentation::format($result['contribution_percent'], 2) }}% <span class="text-xs font-normal">do preço de venda</span></p>
                            <p class="text-xs leading-5">{{ $result['tone'] === 'negative' ? 'Esta venda não cobre o custo do produto e as despesas informadas.' : ($result['tone'] === 'zero' ? 'Esta venda cobre os valores informados, sem contribuição restante.' : 'Contribuição disponível para cobrir despesas fixas e gerar resultado.') }}</p>
                        </div>
                        @if($mode === 'find_price')
                            <p class="text-xs leading-5 text-stone-500">Menor preço positivo em centavos matematicamente necessário para atingir a meta, arredondado para cima e verificado. Não é garantia de competitividade ou de lucro líquido.</p>
                        @endif
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 pb-5 text-sm">
                            <div><p class="font-medium">Repasse estimado</p><p class="mt-1 text-xs text-stone-500">Após comissão e tarifa, antes do frete.</p></div>
                            <x-money :value="$result['marketplace_payout']" class="text-lg font-semibold"/>
                        </div>
                        <details wire:key="financial-breakdown" wire:ignore.self class="rounded-lg border border-stone-200">
                            <summary class="cursor-pointer px-4 py-4 text-sm font-medium text-cajubi-700">Ver composição financeira detalhada</summary>
                            <dl class="grid gap-3 border-t border-stone-100 px-4 py-5 text-sm">
                                <div class="flex flex-wrap justify-between gap-2"><dt>Preço de venda</dt><dd><x-money :value="$result['sale_price']"/></dd></div>
                                <div class="flex flex-wrap justify-between gap-2 text-stone-500"><dt>Comissão ({{ \App\Services\DecimalPresentation::format($result['commission_percent'], 2) }}%)</dt><dd>− <x-money :value="$result['commission_amount']"/></dd></div>
                                <div class="flex flex-wrap justify-between gap-2 text-stone-500"><dt>Tarifa fixa</dt><dd>− <x-money :value="$result['fixed_fee']"/></dd></div>
                                <div class="flex flex-wrap justify-between gap-2 border-t border-stone-100 pt-3 font-semibold"><dt>Repasse antes do frete</dt><dd><x-money :value="$result['marketplace_payout']"/></dd></div>
                                <div class="flex flex-wrap justify-between gap-2 text-stone-500"><dt>Custo do produto</dt><dd>− <x-money :value="$result['product_cost']"/></dd></div>
                                <div class="flex flex-wrap justify-between gap-2 text-stone-500"><dt>Frete do vendedor</dt><dd>− <x-money :value="$result['seller_shipping']"/></dd></div>
                                <div class="flex flex-wrap justify-between gap-2 text-stone-500"><dt>Outras despesas variáveis</dt><dd>− <x-money :value="$result['other_variable_expenses']"/></dd></div>
                                <div class="flex flex-wrap justify-between gap-2 border-t border-stone-100 pt-3 font-semibold"><dt>Margem de contribuição</dt><dd><x-money :value="$result['contribution']"/></dd></div>
                            </dl>
                        </details>
                        <p class="text-xs leading-5 text-stone-500">Estimativa com os parâmetros informados. A contribuição não é lucro líquido: despesas fixas não são descontadas. Os cálculos preservam a precisão; os valores exibidos são arredondados para centavos.</p>
                    @else
                        <div class="grid gap-3 rounded-xl border border-dashed border-stone-200 bg-stone-50 px-6 py-8">
                            <p class="text-sm font-medium text-stone-600">{{ $mode === 'find_price' ? 'Preço de venda sugerido' : 'Quanto sobra por venda' }}</p>
                            <p class="text-2xl font-semibold text-stone-500">Aguardando informações</p>
                            <p class="text-sm leading-6 text-stone-500">O resultado aparece quando o produto tem custo e todos os parâmetros estão preenchidos corretamente.</p>
                        </div>
                        @if($product_id !== '' || $channel !== '' || $sale_price !== '' || $desired_contribution !== '' || $commission_percent !== '' || $fixed_fee !== '' || $seller_shipping !== '' || $other_variable_expenses !== '' || ! in_array($mode, ['simulate', 'find_price'], true))
                        <div class="grid gap-3">
                            <p class="text-xs font-semibold tracking-wide text-stone-500 uppercase">Para concluir a simulação</p>
                            <ul class="grid gap-2 text-sm leading-6 text-amber-900">
                                @foreach($this->simulation['errors'] as $field => $messages)
                                    <li wire:key="simulation-error-{{ $field }}">{{ $messages[0] }}</li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                    @endif
                </div>
                <p class="border-t border-stone-100 pt-4 text-xs text-stone-400">Esta simulação não é salva e não altera os custos cadastrados.</p>
            </div>
        </section>
    </div>
</div>

