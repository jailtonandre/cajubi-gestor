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

<div class="grid gap-7">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="grid gap-2"><p class="eyebrow">Hipóteses comerciais</p><h1 class="page-title">Cenários</h1><p class="text-sm text-stone-500">Compare preços, modalidades e custos sem alterar os cadastros.</p></div>
        <button type="button" wire:click="addScenario" class="btn-primary">+ Adicionar cenário</button>
    </div>
    <section class="panel grid gap-5 p-6 sm:grid-cols-2" aria-label="Produto e referência">
        <x-field label="Produto para comparar" for="product_id">
            <select id="product_id" wire:model.live="product_id" class="field"><option value="">Selecione um produto</option>@foreach($this->products as $product)<option wire:key="comparison-product-{{ $product->id }}" value="{{ $product->id }}">{{ $product->name }} · {{ $product->sku }}</option>@endforeach</select>
        </x-field>
        <x-field label="Referência para comparar volume" for="reference_key" hint="Escolha o cenário cuja contribuição total deseja igualar.">
            <select id="reference_key" wire:model.live="reference_key" class="field">@foreach($rows as $row)<option wire:key="reference-{{ $row['key'] }}" value="{{ $row['key'] }}">{{ $row['name'] }}</option>@endforeach</select>
        </x-field>
        <div class="sm:col-span-2 text-sm text-stone-600">
            @if($this->costContext['value'] !== null)Custo cadastrado por unidade: <x-money :value="$this->costContext['value']" class="font-semibold"/>
            @elseif($this->costContext['warning'])<span class="text-amber-900">{{ $this->costContext['warning'] }}</span>
            @else Selecione um produto e preencha suas hipóteses em cada cenário. @endif
        </div>
    </section>
    <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-900">As quantidades são hipóteses informadas por você, não previsões. Maior exposição não garante mais vendas. Contribuição não é lucro líquido: despesas fixas não são descontadas. Maior receita bruta não determina o melhor cenário.</p>
    <p wire:loading class="text-sm text-stone-500" role="status">Atualizando comparação…</p>
    <div class="grid items-start gap-6 lg:grid-cols-2 2xl:grid-cols-3" wire:loading.class="opacity-50">
    @foreach($rows as $index => $row)
        @php($evaluation = $this->evaluations[$row['key']])
        @php($result = $evaluation['result'])
        @php($selection = $this->selections[$row['key']] ?? null)
        <section wire:key="scenario-{{ $row['key'] }}" class="panel min-w-0 overflow-hidden" aria-label="{{ $row['name'] }}">
            <div @class(['h-1', 'bg-red-500' => $result && $result['tone'] === 'negative', 'bg-cajubi-yellow' => ! $result || $result['tone'] !== 'negative'])></div>
            <div class="grid gap-5 p-5 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">{{ $row['name'] }}</h2>
                    <div class="flex gap-3 text-xs"><button type="button" wire:click="duplicateScenario({{ $index }})" class="text-cajubi-700 hover:underline">Duplicar</button><button type="button" wire:click="removeScenario({{ $index }})" class="text-stone-500 hover:text-red-700" aria-label="Remover {{ $row['name'] }}">Remover</button></div>
                </div>
                <x-field label="Nome do cenário" for="rows.{{ $index }}.name"><input id="rows.{{ $index }}.name" wire:model.live.debounce.400ms="rows.{{ $index }}.name" class="field" maxlength="80"></x-field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Canal *" for="rows.{{ $index }}.channel"><select id="rows.{{ $index }}.channel" wire:model.live="rows.{{ $index }}.channel" class="field"><option value="">Selecione</option><option value="mercado_livre">Mercado Livre</option><option value="shopee">Shopee</option></select></x-field>
                    <x-field label="Vendas estimadas (un.) *" for="rows.{{ $index }}.quantity"><input id="rows.{{ $index }}.quantity" wire:model.live.debounce.400ms="rows.{{ $index }}.quantity" class="field" inputmode="numeric"></x-field>
                </div>
                <x-field label="Regra de tarifa" for="rows.{{ $index }}.tariff_profile_id">
                    <select id="rows.{{ $index }}.tariff_profile_id" wire:model.live="rows.{{ $index }}.tariff_profile_id" class="field" @disabled($row['channel'] === '')><option value="">Informar manualmente</option>@foreach($this->profiles->where('channel', $row['channel']) as $profile)<option wire:key="scenario-{{ $row['key'] }}-profile-{{ $profile->id }}" value="{{ $profile->id }}">{{ $profile->listing_type }} · {{ $profile->category }} · {{ $profile->confidence === 'confirmed' ? 'Confirmado' : 'Estimado' }}</option>@endforeach</select>
                </x-field>
                @if($selection)
                    @if($selection['error'])
                        <p class="text-sm text-red-800" role="alert">{{ $selection['error'] }}</p>
                    @else
                        <details class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5">
                            <summary class="cursor-pointer font-semibold text-amber-900">{{ $selection['profile']->confidence === 'confirmed' ? 'Confirmado' : 'Estimado' }} · {{ $selection['profile']->listing_type }} · {{ count($selection['warnings']) }} aviso(s)</summary>
                            <p class="mt-3 whitespace-pre-line text-stone-600">Fonte: {{ $selection['profile']->source }}</p>
                            @if($selection['tier'])<p class="mt-2">Faixa: <x-money :value="$selection['tier']['min_price']"/> até @if($selection['tier']['max_price'] !== null)<x-money :value="$selection['tier']['max_price']"/>@else sem limite @endif</p>@endif
                            @if(($row['commission_manual'] ?? false) || ($row['fixed_manual'] ?? false))<p class="mt-2 text-amber-900">Ajuste manual ativo. Reaplique a regra para usar as tarifas automáticas.</p>@endif
                            <p class="mt-2 whitespace-pre-line text-stone-600">{{ $selection['profile']->conditions }}</p>
                            <ul class="mt-3 grid gap-1 text-amber-900">@foreach($selection['warnings'] as $warning)<li wire:key="scenario-{{ $row['key'] }}-warning-{{ $loop->index }}">{{ $warning }}</li>@endforeach</ul>
                            <button type="button" wire:click="applyProfile({{ $index }})" class="mt-3 font-medium text-cajubi-700 underline">Reaplicar valores da regra</button>
                        </details>
                    @endif
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach(['sale_price' => 'Preço de venda (R$) *', 'commission_percent' => 'Comissão (%) *', 'fixed_fee' => 'Tarifa fixa (R$) *', 'seller_shipping' => 'Frete do vendedor (R$) *', 'other_variable_expenses' => 'Outras despesas variáveis (R$) *'] as $field => $label)
                        <x-field wire:key="scenario-{{ $row['key'] }}-{{ $field }}" :label="$label" for="rows.{{ $index }}.{{ $field }}"><input id="rows.{{ $index }}.{{ $field }}" wire:model.live.debounce.400ms="rows.{{ $index }}.{{ $field }}" class="field" inputmode="decimal"></x-field>
                    @endforeach
                </div>
                <p class="text-xs leading-5 text-stone-500">Campos obrigatórios: informe zero explicitamente quando não houver despesa. Ajustes de tarifas são exclusivos deste cenário.</p>
                <details wire:ignore.self wire:key="sensitivity-{{ $row['key'] }}" class="rounded-lg border border-stone-200 p-4">
                    <summary class="cursor-pointer text-sm font-medium text-cajubi-700">Sensibilidade de custos · {{ $row['cost_mode'] === 'alternative' ? 'Alternativos' : 'Cadastrados' }}</summary>
                    <div class="mt-4 grid gap-4">
                        <x-field label="Custos utilizados" for="rows.{{ $index }}.cost_mode"><select id="rows.{{ $index }}.cost_mode" wire:model.live="rows.{{ $index }}.cost_mode" class="field"><option value="current">Custos cadastrados</option><option value="alternative">Custos alternativos de insumos</option></select></x-field>
                        @if($row['cost_mode'] === 'alternative')
                            <p class="text-xs leading-5 text-stone-500">Informe o custo por unidade de medida de cada insumo que deseja alterar. Em branco mantém o custo cadastrado; 0 representa custo zero explícito. Nada será gravado.</p>
                            @forelse($this->costContext['supplies'] as $supply)
                                <x-field wire:key="scenario-{{ $row['key'] }}-cost-{{ $supply->id }}" :label="$supply->name.' (R$ / '.$supply->unit.')'" for="rows.{{ $index }}.cost_overrides.{{ $supply->id }}">
                                    <input id="rows.{{ $index }}.cost_overrides.{{ $supply->id }}" wire:model.live.debounce.400ms="rows.{{ $index }}.cost_overrides.{{ $supply->id }}" class="field" inputmode="decimal">
                                    <p class="text-xs text-stone-500">Cadastrado: @if($supply->currentCost)<x-money :value="$supply->currentCost->unit_cost"/> / {{ $supply->unit }}@else Desconhecido @endif</p>
                                </x-field>
                            @empty
                                <p class="text-sm text-amber-900">Selecione um produto com insumos na composição para informar custos alternativos.</p>
                            @endforelse
                        @endif
                    </div>
                </details>
                <div wire:loading.class="hidden">
                @if($result)
                    <div class="grid gap-4 border-t border-stone-200 pt-5">
                        @if($row['cost_mode'] === 'alternative')<p class="text-xs font-medium text-amber-900">Custos alternativos hipotéticos</p>@endif
                        <div @class(['grid gap-2 rounded-lg p-4', 'bg-red-50 text-red-800' => $result['tone'] === 'negative', 'bg-cajubi-50 text-cajubi-800' => $result['tone'] !== 'negative'])>
                            <p class="text-xs font-medium">Contribuição por unidade</p><x-money :value="$result['contribution']" class="text-2xl font-semibold"/><p class="text-sm font-semibold">{{ \App\Services\DecimalPresentation::format($result['contribution_percent'], 2) }}%</p>
                            @if($result['tone'] === 'negative')<p class="text-xs">Contribuição negativa: mais vendas aumentam a perda de contribuição.</p>@endif
                        </div>
                        <p class="text-xs font-medium text-stone-500">Totais para {{ $result['quantity'] }} unidade(s)</p>
                        <dl class="grid gap-3 text-sm">
                            @foreach(['gross_revenue' => 'Receita bruta', 'fees_and_expenses_total' => 'Tarifas e despesas variáveis', 'payout_total' => 'Repasse estimado (antes do frete)', 'product_cost_total' => 'Custo dos produtos'] as $metric => $label)
                                <div wire:key="scenario-{{ $row['key'] }}-metric-{{ $metric }}" class="flex flex-wrap justify-between gap-2"><dt class="text-stone-500">{{ $label }}</dt><dd><x-money :value="$result[$metric]"/></dd></div>
                            @endforeach
                            <div class="flex flex-wrap justify-between gap-2 border-t border-stone-100 pt-3 font-semibold"><dt>Contribuição total projetada</dt><dd><x-money :value="$result['contribution_total']"/></dd></div>
                        </dl>
                        <p class="text-xs leading-5 text-stone-400">Tarifas e despesas variáveis incluem comissão, tarifa fixa, frete e outras despesas. O custo do produto é separado. Totais calculados sem arredondar a contribuição unitária para centavos.</p>
                        @if($row['key'] === $reference_key)
                            <p class="rounded-lg bg-stone-50 p-3 text-xs text-stone-600">Referência para a comparação de volume.</p>
                        @elseif(isset($this->volumeComparisons[$row['key']]))
                            @php($volume = $this->volumeComparisons[$row['key']])
                            <div class="grid gap-2 rounded-lg border border-stone-200 p-4 text-sm">
                                <p class="font-semibold">Para igualar a referência</p>
                                @if($volume['message'])<p class="text-xs leading-5 text-amber-900">{{ $volume['message'] }}</p>
                                @else
                                    <p><strong>{{ $volume['required_units'] }} unidades</strong> necessárias.</p>
                                    <p class="text-xs text-stone-500">{{ $volume['additional_units'] }} adicionais à hipótese deste cenário.</p>
                                    <p class="text-xs text-stone-500">{{ $volume['extra_vs_reference_units'] }} unidades a mais que o volume da referência ({{ \App\Services\DecimalPresentation::format($volume['extra_percent'], 2) }}%).</p>
                                @endif
                            </div>
                        @else
                            <p class="text-xs text-stone-500">Complete o cenário de referência para comparar volumes.</p>
                        @endif
                    </div>
                @else
                    <div class="grid gap-3 border-t border-stone-200 pt-5"><p class="text-sm font-medium text-stone-500">Cenário pendente</p>
                    @if($product_id !== '')
                        <ul class="grid gap-1 text-xs leading-5 text-amber-900">@foreach($evaluation['errors'] as $field => $messages)<li wire:key="scenario-{{ $row['key'] }}-error-{{ $field }}">{{ $messages[0] }}</li>@endforeach</ul>
                    @else<p class="text-xs text-stone-500">Selecione um produto para começar.</p>@endif
                    </div>
                @endif
                </div>
            </div>
        </section>
    @endforeach
    </div>
    <p class="text-xs leading-5 text-stone-500">Comparações temporárias: nenhuma venda, regra ou custo é alterado. Os valores exibidos são arredondados para centavos. Use custos alternativos e duplique cenários para explorar sensibilidades.</p>
</div>

