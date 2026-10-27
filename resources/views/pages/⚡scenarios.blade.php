<?php

use App\Models\Product;
use App\Models\TariffProfile;
use App\Services\DecimalPresentation;
use App\Services\ProductCostCalculator;
use App\Services\ScenarioComparison;
use App\Services\TariffCatalog;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Comparação de cenários')] class extends Component
{
    public string $product_id = '';
    public string $reference_key = '';
    public array $rows = [];
    protected ScenarioComparison $comparison;
    protected TariffCatalog $tariffs;
    protected ProductCostCalculator $costs;

    public function boot(ScenarioComparison $comparison, TariffCatalog $tariffs, ProductCostCalculator $costs): void
    {
        $this->comparison = $comparison;
        $this->tariffs = $tariffs;
        $this->costs = $costs;
    }

    public function mount(): void
    {
        $this->addScenario();
        $this->addScenario();
    }

    public function addScenario(): void
    {
        $key = (string) Str::uuid();
        $this->rows[] = ['key' => $key, 'name' => 'Cenário '.(count($this->rows) + 1),
            'channel' => '', 'tariff_profile_id' => '', 'tariff_revision_id' => null,
            'sale_price' => '', 'commission_percent' => '', 'fixed_fee' => '', 'commission_manual' => false, 'fixed_manual' => false,
            'seller_shipping' => '', 'other_variable_expenses' => '', 'quantity' => '',
            'cost_mode' => 'current', 'cost_overrides' => []];
        if ($this->reference_key === '') { $this->reference_key = $key; }
    }

    public function duplicateScenario(int $index): void
    {
        if (! isset($this->rows[$index])) { return; }
        $row = $this->rows[$index];
        $row['key'] = (string) Str::uuid();
        $row['name'] = 'Cópia de '.$row['name'];
        $this->rows[] = $row;
    }

    public function removeScenario(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
        if (! in_array($this->reference_key, array_column($this->rows, 'key'), true)) {
            $this->reference_key = $this->rows[0]['key'] ?? '';
        }
    }

    public function updatedProductId(): void
    {
        foreach ($this->rows as &$row) {
            $row['cost_mode'] = 'current';
            $row['cost_overrides'] = [];
        }
    }

    public function updatedRows(mixed $value, string $path): void
    {
        [$index, $field] = explode('.', $path, 2);
        if (! isset($this->rows[$index])) { return; }
        if ($field === 'channel') {
            $this->rows[$index]['tariff_profile_id'] = '';
            $this->rows[$index]['tariff_revision_id'] = null;
            $this->rows[$index]['commission_percent'] = '';
            $this->rows[$index]['fixed_fee'] = '';
            $this->rows[$index]['commission_manual'] = false;
            $this->rows[$index]['fixed_manual'] = false;
        } elseif ($field === 'tariff_profile_id') {
            $this->applyProfile((int) $index);
        } elseif ($field === 'sale_price') {
            $this->refreshTier((int) $index);
        } elseif (in_array($field, ['commission_percent', 'fixed_fee'], true)) {
            $this->rows[$index][$field === 'fixed_fee' ? 'fixed_manual' : 'commission_manual'] = true;
        }
    }

    public function refreshTier(int $index): void
    {
        if (! isset($this->rows[$index]) || $this->rows[$index]['tariff_profile_id'] === '') { return; }
        $row = &$this->rows[$index];
        try {
            $selection = $this->tariffs->simulationParameters($row['tariff_profile_id'], $row['channel'], DecimalPresentation::input($row['sale_price']));
            if ($selection['profile']->pricing_model !== 'tiered') { return; }
            foreach (['commission_percent' => 'commission_manual', 'fixed_fee' => 'fixed_manual'] as $field => $manual) {
                if (! ($row[$manual] ?? false)) { $row[$field] = $selection[$field] !== null ? str_replace('.', ',', $selection[$field]) : ''; }
            }
        } catch (ValidationException) {}
    }

    public function applyProfile(int $index): void
    {
        if (! isset($this->rows[$index])) { return; }
        $row = &$this->rows[$index];
        $row['commission_percent'] = '';
        $row['fixed_fee'] = '';
        $row['tariff_revision_id'] = null;
        $row['commission_manual'] = $row['fixed_manual'] = false;
        if ($row['tariff_profile_id'] === '') { return; }
        try {
            $selection = $this->tariffs->simulationParameters($row['tariff_profile_id'], $row['channel'], DecimalPresentation::input($row['sale_price']));
            $row['commission_percent'] = $selection['commission_percent'] !== null ? str_replace('.', ',', $selection['commission_percent']) : '';
            $row['fixed_fee'] = $selection['fixed_fee'] !== null ? str_replace('.', ',', $selection['fixed_fee']) : '';
            $row['tariff_revision_id'] = $selection['revision_id'];
        } catch (ValidationException) {
            // The comparison displays the profile validation error without saving anything.
        }
    }

    #[Computed]
    public function products(): \Illuminate\Database\Eloquent\Collection
    {
        return Product::query()->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function profiles(): \Illuminate\Database\Eloquent\Collection
    {
        return TariffProfile::query()->where('is_active', true)->orderBy('category')->orderBy('listing_type')->get();
    }

    #[Computed]
    public function costContext(): array
    {
        $product = preg_match('/\A[1-9][0-9]*\z/', $this->product_id) ? Product::query()->find($this->product_id) : null;
        if (! $product) { return ['supplies' => collect(), 'value' => null, 'warning' => null]; }
        try {
            $supplies = $this->costs->supplies($product);
        } catch (ValidationException $exception) {
            return ['supplies' => collect(), 'value' => null, 'warning' => $exception->validator->errors()->first()];
        }
        try {
            return ['supplies' => $supplies, 'value' => $this->costs->calculate($product), 'warning' => null];
        } catch (ValidationException $exception) {
            return ['supplies' => $supplies, 'value' => null, 'warning' => $exception->validator->errors()->first()];
        }
    }

    #[Computed]
    public function selections(): array
    {
        $selections = [];
        foreach ($this->rows as $row) {
            if ($row['tariff_profile_id'] === '') { continue; }
            try {
                $selection = $this->tariffs->simulationParameters($row['tariff_profile_id'], $row['channel'], DecimalPresentation::input($row['sale_price']));
                if ($row['tariff_revision_id'] !== null && $row['tariff_revision_id'] !== $selection['revision_id']) {
                    $selection['warnings'][] = 'Regra alterada desde a seleção. Reaplique para carregar os novos valores.';
                }
                $selections[$row['key']] = $selection + ['error' => null];
            } catch (ValidationException $exception) {
                $selections[$row['key']] = ['error' => $exception->validator->errors()->first()];
            }
        }

        return $selections;
    }

    #[Computed]
    public function evaluations(): array
    {
        $evaluations = [];
        foreach ($this->rows as $row) {
            $parameters = array_replace($row, ['product_id' => $this->product_id]);
            foreach (['sale_price', 'commission_percent', 'fixed_fee', 'seller_shipping', 'other_variable_expenses'] as $field) {
                if (is_string($parameters[$field])) { $parameters[$field] = DecimalPresentation::input($parameters[$field]); }
            }
            $parameters['cost_overrides'] = [];
            foreach ($row['cost_overrides'] as $id => $cost) {
                if ($cost !== '') {
                    $parameters['cost_overrides'][$id] = is_string($cost) ? DecimalPresentation::input($cost) : $cost;
                }
            }
            try {
                $evaluations[$row['key']] = ['result' => $this->comparison->evaluate($parameters), 'errors' => []];
            } catch (ValidationException $exception) {
                $evaluations[$row['key']] = ['result' => null, 'errors' => $exception->errors()];
            }
        }

        return $evaluations;
    }

    #[Computed]
    public function volumeComparisons(): array
    {
        $reference = $this->evaluations[$this->reference_key]['result'] ?? null;
        $comparisons = [];
        if (! $reference) { return []; }
        foreach ($this->evaluations as $key => $evaluation) {
            if ($key !== $this->reference_key && $evaluation['result']) {
                $comparisons[$key] = $this->comparison->matchVolume($evaluation['result'], $reference);
            }
        }

        return $comparisons;
    }
};
?>
<div class="scenarios-page">
    @php($evaluations = $this->evaluations)
    @php($selections = $this->selections)
    @php($volumes = $this->volumeComparisons)
    @php($selectedProduct = $this->products->firstWhere('id', (int) $product_id))
    @php($referenceResult = $evaluations[$reference_key]['result'] ?? null)
    <div class="scenario-page-heading">
        <div><h1 class="page-title">Cenários</h1><p>Compare estratégias e veja o impacto na contribuição e no volume.</p></div>
        <button type="button" wire:click="addScenario" class="btn-primary">+ Adicionar cenário</button>
    </div>

    <section class="scenario-configuration" aria-labelledby="scenario-config-title">
        <div class="scenario-section-heading"><span class="scenario-step" aria-hidden="true">1</span><h2 id="scenario-config-title">Configuração da comparação</h2></div>
        <div class="scenario-configuration-grid">
            <div class="scenario-product-choice">
                @if($selectedProduct)<x-catalog-thumbnail :record="$selectedProduct"/>@endif
                <div class="min-w-0 flex-1">
                    <x-field label="Produto para comparar" for="product_id">
                        <select id="product_id" wire:model.live="product_id" class="field"><option value="">Selecione um produto</option>@foreach($this->products as $product)<option wire:key="comparison-product-{{ $product->id }}" value="{{ $product->id }}">{{ $product->name }} · {{ $product->sku }}</option>@endforeach</select>
                    </x-field>
                    <p class="scenario-config-note">@if($this->costContext['value'] !== null)Custo cadastrado por unidade: <x-money :value="$this->costContext['value']"/>@elseif($this->costContext['warning']){{ $this->costContext['warning'] }}@else O mesmo produto será utilizado em todos os cenários. @endif</p>
                </div>
            </div>
            <x-field label="Cenário de referência" for="reference_key" hint="Escolha o cenário cuja contribuição total deseja igualar.">
                <select id="reference_key" wire:model.live="reference_key" class="field">@foreach($rows as $row)<option wire:key="reference-{{ $row['key'] }}" value="{{ $row['key'] }}">{{ $row['name'] }}{{ $row['channel'] === 'shopee' ? ' (Shopee)' : ($row['channel'] === 'mercado_livre' ? ' (Mercado Livre)' : '') }}</option>@endforeach</select>
            </x-field>
            <div class="scenario-how-to"><strong>Como usar</strong><p>Informe canal, preço e quantidade simulada. Ajuste as condições comerciais e compare os resultados abaixo.</p></div>
        </div>
    </section>

    <section aria-labelledby="scenario-cards-title">
        <div class="scenario-section-heading scenario-cards-heading"><span class="scenario-step" aria-hidden="true">2</span><h2 id="scenario-cards-title">Cenários configurados</h2><p>Ajuste as hipóteses principais e veja os resultados. Detalhes em “Condições comerciais”.</p></div>
        <p wire:loading class="text-xs text-stone-500" role="status">Atualizando comparação…</p>
        <div @class(['scenario-cards', 'scenario-cards-multiple' => count($rows) > 1, 'scenario-cards-three' => count($rows) === 3 || count($rows) > 4]) wire:loading.class="opacity-50">
            @forelse($rows as $index => $row)
                @php($evaluation = $evaluations[$row['key']])
                @php($result = $evaluation['result'])
                @php($selection = $selections[$row['key']] ?? null)
                <section wire:key="scenario-{{ $row['key'] }}" @class(['scenario-card', 'scenario-card-reference' => $row['key'] === $reference_key]) aria-labelledby="scenario-title-{{ $row['key'] }}" x-data>
                    <div class="scenario-card-body">
                        <div class="scenario-card-heading">
                            <div class="flex min-w-0 flex-wrap items-center gap-2"><h3 id="scenario-title-{{ $row['key'] }}">{{ $row['name'] }}</h3>@if($row['key'] === $reference_key)<span class="scenario-reference-badge">Referência</span>@endif</div>
                            <div class="scenario-card-actions"><button type="button" wire:click="duplicateScenario({{ $index }})" aria-label="Duplicar {{ $row['name'] }}">Duplicar</button><button type="button" wire:click="removeScenario({{ $index }})" class="scenario-remove" aria-label="Remover {{ $row['name'] }}">Remover</button></div>
                        </div>
                        <div class="scenario-main-inputs" aria-label="Hipóteses informadas">
                            <x-field label="Nome do cenário" for="rows.{{ $index }}.name"><input id="rows.{{ $index }}.name" wire:model.live.debounce.400ms="rows.{{ $index }}.name" class="field" maxlength="80"></x-field>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-field label="Canal *" for="rows.{{ $index }}.channel"><select id="rows.{{ $index }}.channel" wire:model.live="rows.{{ $index }}.channel" class="field"><option value="">Selecione</option><option value="mercado_livre">Mercado Livre</option><option value="shopee">Shopee</option></select>
                                    @if(isset($evaluation['errors']['channel']))<p class="text-xs text-red-700" role="alert">{{ $evaluation['errors']['channel'][0] }}</p>@endif
                                </x-field>
                                <x-field label="Quantidade simulada (un.) *" for="rows.{{ $index }}.quantity"><input id="rows.{{ $index }}.quantity" wire:model.live.debounce.400ms="rows.{{ $index }}.quantity" class="field" inputmode="numeric">
                                    @if(isset($evaluation['errors']['quantity']))<p class="text-xs text-red-700" role="alert">{{ $evaluation['errors']['quantity'][0] }}</p>@endif
                                </x-field>
                            </div>
                            <x-field label="Preço de venda (R$) *" for="rows.{{ $index }}.sale_price"><input id="rows.{{ $index }}.sale_price" wire:model.live.debounce.400ms="rows.{{ $index }}.sale_price" class="field" inputmode="decimal">
                                @if(isset($evaluation['errors']['sale_price']))<p class="text-xs text-red-700" role="alert">{{ $evaluation['errors']['sale_price'][0] }}</p>@endif
                            </x-field>
                        </div>
                    <details wire:ignore.self wire:key="conditions-{{ $row['key'] }}" x-ref="conditions" class="scenario-conditions">
                        <summary>Condições comerciais @if(($row['commission_manual'] ?? false) || ($row['fixed_manual'] ?? false))<span class="scenario-manual-badge">Ajuste manual ativo</span>@elseif($product_id !== '' && $evaluation['errors'])<span class="scenario-condition-hint">Revisar campos</span>@endif</summary>
                        <div class="grid gap-4 border-t border-stone-100 p-4">
                        @if(($row['commission_manual'] ?? false) || ($row['fixed_manual'] ?? false))<p class="scenario-manual-badge">Ajuste manual ativo{{ $row['tariff_profile_id'] !== '' ? ' nas tarifas da regra' : ' nas tarifas deste cenário' }}.</p>
                        @elseif($selection && ! $selection['error'])<p class="scenario-policy-note">{{ $selection['profile']->listing_type }} · {{ $selection['profile']->pricing_model === 'tiered' ? 'Tarifas por faixa de preço' : 'Tarifas da regra' }}</p>@endif

                            @if($product_id !== '' && $evaluation['errors'])
                                <ul class="grid gap-1 text-xs leading-5 text-amber-900" role="alert">@foreach($evaluation['errors'] as $field => $messages)<li wire:key="scenario-{{ $row['key'] }}-error-{{ $field }}">{{ $messages[0] }}</li>@endforeach</ul>
                            @endif
                            <x-field label="Regra de tarifa" for="rows.{{ $index }}.tariff_profile_id">
                                <select id="rows.{{ $index }}.tariff_profile_id" wire:model.live="rows.{{ $index }}.tariff_profile_id" class="field" @disabled($row['channel'] === '')><option value="">Informar manualmente</option>@foreach($this->profiles->where('channel', $row['channel']) as $profile)<option wire:key="scenario-{{ $row['key'] }}-profile-{{ $profile->id }}" value="{{ $profile->id }}">{{ $profile->listing_type }} · {{ $profile->category }} · {{ $profile->confidence === 'confirmed' ? 'Confirmado' : 'Estimado' }}</option>@endforeach</select>
                            </x-field>
                            @if($selection)
                                @if($selection['error'])<p class="text-sm text-red-800" role="alert">{{ $selection['error'] }}</p>
                                @else
                                    <div class="grid gap-2 rounded-lg bg-stone-50 p-3 text-xs leading-5">
                                        <p class="font-semibold">{{ $selection['profile']->confidence === 'confirmed' ? 'Confirmado' : 'Estimado' }} · {{ $selection['profile']->listing_type }}</p>
                                        @if($selection['profile']->pricing_model === 'tiered')<p>Tarifas por faixa de preço: acompanham o preço informado, preservando ajustes manuais.</p>@endif
                                        @if($selection['tier'])<p>Faixa: <x-money :value="$selection['tier']['min_price']"/> até @if($selection['tier']['max_price'] !== null)<x-money :value="$selection['tier']['max_price']"/>@else sem limite @endif</p>@endif
                                        @if(($row['commission_manual'] ?? false) || ($row['fixed_manual'] ?? false))<p class="text-amber-900">Ajuste manual ativo. Reaplique a regra para usar as tarifas automáticas.</p>@endif
                                        <p class="whitespace-pre-line text-stone-600">Fonte: {{ $selection['profile']->source }}</p>
                                        @if($selection['profile']->conditions)<p class="whitespace-pre-line text-stone-600">{{ $selection['profile']->conditions }}</p>@endif
                                        @if($selection['warnings'])<ul class="grid gap-1 text-amber-900">@foreach($selection['warnings'] as $warning)<li wire:key="scenario-{{ $row['key'] }}-warning-{{ $loop->index }}">{{ $warning }}</li>@endforeach</ul>@endif
                                        <button type="button" wire:click="applyProfile({{ $index }})" class="justify-self-start font-medium text-cajubi-700 underline">Reaplicar valores da regra</button>
                                    </div>
                                @endif
                            @endif
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach(['commission_percent' => 'Comissão (%) *', 'fixed_fee' => 'Tarifa fixa (R$) *', 'seller_shipping' => 'Frete do vendedor (R$) *', 'other_variable_expenses' => 'Outras despesas variáveis (R$) *'] as $field => $label)
                                    <x-field wire:key="scenario-{{ $row['key'] }}-{{ $field }}" :label="$label" for="rows.{{ $index }}.{{ $field }}"><input id="rows.{{ $index }}.{{ $field }}" wire:model.live.debounce.400ms="rows.{{ $index }}.{{ $field }}" class="field" inputmode="decimal"></x-field>
                                @endforeach
                            </div>
                            <p class="text-xs leading-5 text-stone-500">Campos obrigatórios: informe zero explicitamente quando não houver despesa. Ajustes de tarifas são exclusivos deste cenário.</p>
                            <fieldset class="grid gap-3 border-t border-stone-100 pt-4">
                                <legend class="text-xs font-semibold text-stone-700">Sensibilidade de custos · {{ $row['cost_mode'] === 'alternative' ? 'Alternativos' : 'Cadastrados' }}</legend>
                                <x-field label="Custos utilizados" for="rows.{{ $index }}.cost_mode"><select id="rows.{{ $index }}.cost_mode" wire:model.live="rows.{{ $index }}.cost_mode" class="field"><option value="current">Custos cadastrados</option><option value="alternative">Custos alternativos de insumos</option></select></x-field>
                                @if($row['cost_mode'] === 'alternative')
                                    <p class="text-xs leading-5 text-stone-500">Informe o custo por unidade de medida de cada insumo que deseja alterar. Em branco mantém o custo cadastrado; 0 representa custo zero explícito. Nada será gravado.</p>
                                    @forelse($this->costContext['supplies'] as $supply)
                                        <x-field wire:key="scenario-{{ $row['key'] }}-cost-{{ $supply->id }}" :label="$supply->name.' (R$ / '.$supply->unit.')'" for="rows.{{ $index }}.cost_overrides.{{ $supply->id }}">
                                            <input id="rows.{{ $index }}.cost_overrides.{{ $supply->id }}" wire:model.live.debounce.400ms="rows.{{ $index }}.cost_overrides.{{ $supply->id }}" class="field" inputmode="decimal">
                                            <p class="text-xs text-stone-500">Cadastrado: @if($supply->currentCost)<x-money :value="$supply->currentCost->unit_cost"/> / {{ $supply->unit }}@else Desconhecido @endif</p>
                                        </x-field>
                                    @empty<p class="text-sm text-amber-900">Selecione um produto com insumos na composição para informar custos alternativos.</p>@endforelse
                                @endif
                            </fieldset>
                        </div>
                    </details>
                        <div @class(['scenario-result', 'scenario-result-negative' => $result && $result['tone'] === 'negative']) aria-label="Resultados calculados de {{ $row['name'] }}" wire:loading.class="opacity-50">
                            @if($result)
                                <dl class="scenario-result-grid">
                                    <div class="scenario-unit-result"><dt>Contribuição por venda</dt><dd><x-money :value="$result['contribution']"/></dd></div>
                                    <div class="scenario-total-result"><dt>Contribuição total simulada<span>({{ $result['quantity'] }} un.)</span></dt><dd><x-money :value="$result['contribution_total']"/></dd></div>
                                    <div class="scenario-margin-result"><dt>Margem de contribuição</dt><dd>{{ \App\Services\DecimalPresentation::format($result['contribution_percent'], 2) }}%</dd></div>
                                </dl>
                                @if($result['tone'] === 'negative')<p class="mt-2 text-xs">Contribuição negativa: mais vendas aumentam a perda de contribuição.</p>@endif
                                @if($row['cost_mode'] === 'alternative')<p class="mt-2 text-xs font-medium">Custos alternativos hipotéticos</p>@endif
                            @else
                                <p class="text-sm font-semibold text-stone-600">Cenário pendente</p>
                                @if($product_id === '')<p class="mt-1 text-xs text-stone-500">Selecione um produto para começar.</p>
                                @else<p class="mt-1 text-xs leading-5 text-stone-500">Preencha as hipóteses e as condições comerciais.</p><button type="button" x-on:click="$refs.conditions.open = true; $refs.conditions.querySelector('summary').focus()" class="mt-2 text-xs font-medium underline">Revisar condições comerciais</button>@endif
                            @endif
                        </div>
                    </div>
                </section>
            @empty<div class="panel p-5 text-sm text-stone-500">Nenhum cenário na comparação. Adicione um cenário para informar suas hipóteses.</div>@endforelse
        </div>
    </section>

    <div class="scenario-comparison-area">
        <section class="scenario-comparison-table" aria-labelledby="comparison-title" x-data="{ comparisonView: 'unit' }" wire:loading.class="opacity-50">
            <div class="scenario-section-heading"><span class="scenario-step" aria-hidden="true">3</span><div><h2 id="comparison-title">Comparativo financeiro</h2><p>A referência é uma escolha sua, não uma recomendação automática.</p></div></div>
            <div class="scenario-view-switch" role="group" aria-label="Indicadores apresentados"><button type="button" x-on:click="comparisonView = 'unit'" x-bind:aria-pressed="comparisonView === 'unit'" x-bind:class="comparisonView === 'unit' ? 'is-active' : ''">Por venda</button><button type="button" x-on:click="comparisonView = 'totals'" x-bind:aria-pressed="comparisonView === 'totals'" x-bind:class="comparisonView === 'totals' ? 'is-active' : ''">Totais (quantidade)</button></div>
            <div class="overflow-x-auto" role="region" aria-label="Tabela consolidada dos cenários" tabindex="0">
                <table class="scenario-financial-table">
                    <caption class="sr-only">Comparação financeira do mesmo produto. Preço e quantidade são hipóteses informadas; os demais valores são calculados.</caption>
                    <thead><tr><th scope="col">Indicador</th>@foreach($rows as $row)<th wire:key="comparison-heading-{{ $row['key'] }}" scope="col">{{ $row['name'] }}<span>{{ $row['channel'] === 'mercado_livre' ? 'Mercado Livre' : ($row['channel'] === 'shopee' ? 'Shopee' : 'Canal não informado') }}{{ $row['key'] === $reference_key ? ' · Referência' : '' }}</span></th>@endforeach</tr></thead>
                    <tbody>
                        @foreach(['sale_price' => ['Preço de venda', 'money', 'unit'], 'product_cost' => ['Custo por unidade', 'money', 'unit'], 'quantity' => ['Quantidade simulada', 'quantity', 'totals'], 'gross_revenue' => ['Receita bruta', 'money', 'totals'], 'fees_and_expenses_total' => ['Tarifas e despesas variáveis (total)', 'money', 'both'], 'payout_total' => ['Repasse estimado (antes do frete)', 'money', 'totals'], 'product_cost_total' => ['Custo dos produtos', 'money', 'totals'], 'contribution' => ['Contribuição por venda', 'money', 'unit'], 'contribution_percent' => ['Margem de contribuição', 'percent', 'unit'], 'contribution_total' => ['Contribuição total simulada', 'money', 'both']] as $metric => [$label, $format, $view])
                            <tr wire:key="comparison-metric-{{ $metric }}" @if($view !== 'both') x-show="comparisonView === '{{ $view }}'" @endif @if($view === 'totals') x-cloak @endif @class(['scenario-contribution-row' => $metric === 'contribution', 'scenario-total-row' => $metric === 'contribution_total'])>
                                <th scope="row">{{ $label }}</th>
                                @foreach($rows as $row)
                                    @php($result = $evaluations[$row['key']]['result'])
                                    <td wire:key="comparison-{{ $row['key'] }}-{{ $metric }}" @class(['text-red-800' => $result && in_array($metric, ['contribution', 'contribution_percent', 'contribution_total'], true) && $result['tone'] === 'negative'])>
                                        @if(! $result)<span aria-label="Cenário pendente" class="text-stone-400">—</span>
                                        @elseif($format === 'quantity'){{ $result[$metric] }} unidade(s)
                                        @elseif($format === 'percent'){{ \App\Services\DecimalPresentation::format($result[$metric], 2) }}%
                                        @else<x-money :value="$result[$metric]"/>@endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr><th scope="row">Volume para igualar a referência</th>@foreach($rows as $row)<td wire:key="comparison-volume-{{ $row['key'] }}">
                            @if($row['key'] === $reference_key)<span aria-label="Cenário de referência">—</span>
                            @elseif(isset($volumes[$row['key']]) && ! $volumes[$row['key']]['message']){{ $volumes[$row['key']]['required_units'] }} unidades
                            @else<span class="text-xs text-stone-500">Consulte o volume equivalente</span>@endif
                        </td>@endforeach</tr>
                    </tbody>
                </table>
            </div>
            <p class="scenario-table-note">Tarifas e despesas variáveis incluem comissão, tarifa fixa, frete e outras despesas e são totais para a quantidade simulada. O custo do produto é separado.</p>
        </section>
        <aside class="scenario-volume-area">
            <section aria-labelledby="volume-title">
                <div class="scenario-section-heading"><span class="scenario-step" aria-hidden="true">4</span><div><h2 id="volume-title">Volume necessário para igualar a referência</h2><p>Quantidade necessária para alcançar a mesma contribuição total do cenário de referência.</p></div></div>
                <div class="scenario-volume-cards">
                    @forelse(array_filter($rows, fn ($row) => $row['key'] !== $reference_key) as $row)
                        @php($volume = $volumes[$row['key']] ?? null)
                        <article wire:key="volume-card-{{ $row['key'] }}" class="scenario-volume-card">
                            <h3>{{ $row['name'] }}</h3><p class="scenario-volume-channel">{{ $row['channel'] === 'mercado_livre' ? 'Mercado Livre' : ($row['channel'] === 'shopee' ? 'Shopee' : 'Canal não informado') }}</p>
                            @if($volume && ! $volume['message'])
                                <div class="scenario-volume-value"><strong>{{ $volume['required_units'] }} unidades</strong><span>para igualar <x-money :value="$referenceResult['contribution_total']"/></span></div>
                                <p class="scenario-volume-detail">{{ $volume['additional_units'] }} adicionais à hipótese deste cenário.</p>
                                <p class="scenario-volume-detail">{{ $volume['extra_vs_reference_units'] }} unidades a mais que o volume da referência ({{ \App\Services\DecimalPresentation::format($volume['extra_percent'], 2) }}%).</p>
                            @elseif($volume)<p class="scenario-volume-message">{{ $volume['message'] }}</p>
                            @elseif(! $referenceResult)<p class="scenario-volume-message">Complete o cenário de referência para comparar volumes.</p>
                            @else<p class="scenario-volume-message">Complete este cenário para comparar volumes.</p>@endif
                        </article>
                    @empty<p class="scenario-volume-message">Adicione outro cenário para comparar seu volume com a referência.</p>@endforelse
                </div>
            </section>
            <section class="scenario-observations" aria-labelledby="observations-title">
                <h2 id="observations-title">Observações importantes</h2>
                <ul><li>As quantidades são hipóteses informadas por você, não previsões de vendas.</li><li>Contribuição não é lucro líquido: despesas fixas não são descontadas.</li><li>Os valores exibidos são arredondados para centavos. Os totais usam a contribuição unitária sem arredondamento prévio.</li><li>Comparações temporárias: nenhuma venda, regra ou custo é alterado.</li><li>Maior receita ou contribuição, isoladamente, não determina o melhor cenário. Use custos alternativos e duplique cenários para explorar sensibilidades.</li></ul>
            </section>
        </aside>
    </div>
</div>