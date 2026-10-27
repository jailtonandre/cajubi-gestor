<?php

use App\Models\Product;
use App\Services\CommercialViability;
use App\Services\DecimalPresentation;
use App\Services\ProductCostCalculator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Viabilidade comercial')] class extends Component
{
    public string $product_id = '';
    public array $lots = [];
    public string $selected_lot = '';
    public array $expenses = [];
    public bool $expenses_reviewed = false;
    public string $receipt_days = '';
    public string $supplier_days = '';
    public string $monthly_operating = '';
    public string $reserve = '';
    public string $sensitivity_supply_id = '';
    public array $prices = ['', ''];
    protected CommercialViability $viability;
    protected ProductCostCalculator $costs;

    public function boot(CommercialViability $viability, ProductCostCalculator $costs): void
    {
        $this->viability = $viability;
        $this->costs = $costs;
    }

    public function mount(): void
    {
        $this->addLot();
        $this->addLot();
    }

    public function addLot(): void
    {
        $key = (string) Str::uuid();
        $this->lots[] = ['key' => $key, 'name' => 'Lote '.(count($this->lots) + 1), 'units' => '', 'additional_cost' => ''];
        if ($this->selected_lot === '') {
            $this->selected_lot = $key;
        }
    }

    public function removeLot(int $index): void
    {
        unset($this->lots[$index]);
        $this->lots = array_values($this->lots);
        if (! in_array($this->selected_lot, array_column($this->lots, 'key'), true)) {
            $this->selected_lot = $this->lots[0]['key'] ?? '';
            $this->expenses_reviewed = false;
        }
    }

    public function addExpense(): void
    {
        $this->expenses[] = ['key' => (string) Str::uuid(), 'name' => '', 'category' => '', 'coverage' => '', 'amount' => ''];
        $this->expenses_reviewed = false;
    }

    public function removeExpense(int $index): void
    {
        unset($this->expenses[$index]);
        $this->expenses = array_values($this->expenses);
        $this->expenses_reviewed = false;
    }

    public function updatedExpenses(): void
    {
        $this->expenses_reviewed = false;
    }

    public function updatedProductId(): void
    {
        $this->sensitivity_supply_id = '';
        $this->expenses_reviewed = false;
    }

    public function updatedSelectedLot(): void
    {
        $this->expenses_reviewed = false;
    }

    public function addPrice(): void { $this->prices[] = ''; }

    public function removePrice(int $index): void
    {
        unset($this->prices[$index]);
        $this->prices = array_values($this->prices);
    }

    #[Computed]
    public function products(): \Illuminate\Database\Eloquent\Collection
    {
        return Product::query()->orderBy('name')->orderBy('id')->get();
    }

    #[Computed]
    public function supplyContext(): array
    {
        $product = $this->products->firstWhere('id', $this->product_id);
        if (! $product) { return ['supplies' => collect(), 'warning' => null, 'product' => null]; }
        try {
            return ['supplies' => $this->costs->supplies($product), 'warning' => null, 'product' => $product];
        } catch (ValidationException $exception) {
            return ['supplies' => collect(), 'warning' => $exception->validator->errors()->first(), 'product' => $product];
        }
    }

    #[Computed]
    public function lotResults(): array
    {
        $results = [];
        foreach ($this->lots as $lot) {
            try {
                $results[$lot['key']] = ['result' => $this->viability->lot($this->lotData($lot)), 'errors' => []];
            } catch (ValidationException $exception) {
                $results[$lot['key']] = ['result' => null, 'errors' => $exception->errors()];
            }
        }

        return $results;
    }

    #[Computed]
    public function funding(): array
    {
        $lot = $this->lotResults[$this->selected_lot]['result'] ?? null;
        if (! $lot) { return ['result' => null, 'errors' => ['lot' => ['Complete o lote selecionado para estimar os recursos.']]]; }
        try {
            return ['result' => $this->viability->funding($this->fundingData(), $lot), 'errors' => []];
        } catch (ValidationException $exception) {
            return ['result' => null, 'errors' => $exception->errors()];
        }
    }

    #[Computed]
    public function sensitivities(): array
    {
        $lot = collect($this->lots)->firstWhere('key', $this->selected_lot);
        if (! $lot || $this->sensitivity_supply_id === '') { return []; }
        $results = [];
        foreach ($this->prices as $index => $price) {
            if ($price === '') { continue; }
            try {
                $batch = $this->viability->lot($this->lotData($lot), [$this->sensitivity_supply_id => DecimalPresentation::input($price)]);
                try {
                    $funding = $this->viability->funding($this->fundingData(), $batch);
                } catch (ValidationException) {
                    $funding = null;
                }
                $results[$index] = ['lot' => $batch, 'funding' => $funding, 'error' => null];
            } catch (ValidationException $exception) {
                $results[$index] = ['lot' => null, 'funding' => null, 'error' => $exception->validator->errors()->first()];
            }
        }

        return $results;
    }

    protected function lotData(array $lot): array
    {
        return ['product_id' => $this->product_id, 'units' => $lot['units'], 'additional_cost' => DecimalPresentation::input($lot['additional_cost'])];
    }

    protected function fundingData(): array
    {
        $expenses = $this->expenses;
        foreach ($expenses as &$expense) {
            $expense['amount'] = DecimalPresentation::input($expense['amount']);
        }

        return ['expenses' => $expenses, 'expenses_reviewed' => $this->expenses_reviewed,
            'receipt_days' => $this->receipt_days, 'supplier_days' => $this->supplier_days,
            'monthly_operating' => DecimalPresentation::input($this->monthly_operating), 'reserve' => DecimalPresentation::input($this->reserve)];
    }
};
?>
<div class="viability-page grid gap-5">
    @php
        $lotResults = $this->lotResults;
        $fundingState = $this->funding;
        $funding = $fundingState['result'];
        $baseline = $lotResults[$selected_lot]['result'] ?? null;
        $context = $this->supplyContext;
    @endphp
    <header><h1 class="page-title">Viabilidade comercial</h1><p class="mt-2 text-sm text-stone-500">Planeje lotes e estime os recursos antes de comprar.</p></header>
    <section id="lotes" class="panel grid gap-5 p-5 sm:p-6" aria-labelledby="lotes-title">
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="lotes-title" class="viability-heading"><span>1</span> Planejar o lote</h2><button type="button" wire:click="addLot" class="btn-secondary">+ Adicionar outro lote</button></div>
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="grid gap-2"><div class="flex items-center gap-3 min-w-0">
                @if($context['product'])<x-catalog-thumbnail :record="$context['product']"/>@endif
                <x-field class="min-w-0 flex-1" label="Produto *" for="product_id"><select id="product_id" wire:model.live="product_id" class="field"><option value="">Selecione um produto</option>@foreach($this->products as $product)<option value="{{ $product->id }}" wire:key="viability-product-{{ $product->id }}">{{ $product->name }} · {{ $product->sku }}</option>@endforeach</select></x-field>
            </div>
                @if($context['product'])
                    @php
                        try { $registeredCost = app(\App\Services\ProductCostCalculator::class)->calculate($context['product']); }
                        catch (\Illuminate\Validation\ValidationException) { $registeredCost = null; }
                    @endphp
                    <p class="text-sm text-stone-600">Custo cadastrado por unidade: @if($registeredCost !== null)<strong><x-money :value="$registeredCost"/></strong>@else Indisponível @endif</p>
                @endif
            </div>
            <x-field label="Lote para investimento e sensibilidade" for="selected_lot"><select id="selected_lot" wire:model.live="selected_lot" class="field">@foreach($lots as $lot)<option value="{{ $lot['key'] }}" wire:key="chosen-lot-{{ $lot['key'] }}">{{ $lot['name'] }}</option>@endforeach</select></x-field>
        </div>
        @if($context['warning'])<p role="alert" class="text-sm text-amber-900">{{ $context['warning'] }}</p>@endif
        <div class="grid items-start gap-4 xl:grid-cols-2">
            @foreach($lots as $index => $lot)
                @php($evaluation = $lotResults[$lot['key']])
                @php($result = $evaluation['result'])
                <article wire:key="viability-lot-{{ $lot['key'] }}" @class(['grid min-w-0 gap-4 rounded-xl border p-4', 'border-cajubi-200 bg-cajubi-50/30' => $selected_lot === $lot['key'], 'border-stone-200' => $selected_lot !== $lot['key']])>
                    <div class="flex items-center justify-between gap-2"><h3 class="font-semibold">{{ $lot['name'] }} @if($selected_lot === $lot['key'])<span class="text-xs font-normal text-cajubi-700">Selecionado</span>@endif</h3><button type="button" wire:click="removeLot({{ $index }})" class="text-sm text-stone-500 hover:text-red-700" aria-label="Remover {{ $lot['name'] }}">Remover</button></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field label="Unidades acabadas *" for="lots.{{ $index }}.units" x-data="{ touched: false }" @focusout="touched = true"><input id="lots.{{ $index }}.units" wire:model.live.debounce.400ms="lots.{{ $index }}.units" class="field" inputmode="numeric" placeholder="Ex.: 50">@if(isset($evaluation['errors']['units']))<p x-cloak x-show="touched || @js($lot['units'] !== '')" role="alert" class="text-sm text-red-700">{{ $evaluation['errors']['units'][0] }}</p>@endif</x-field>
                        <x-field label="Custos adicionais do lote (R$) *" for="lots.{{ $index }}.additional_cost" x-data="{ touched: false }" @focusout="touched = true"><input id="lots.{{ $index }}.additional_cost" wire:model.live.debounce.400ms="lots.{{ $index }}.additional_cost" class="field" inputmode="decimal">@if(isset($evaluation['errors']['additional_cost']))<p x-cloak x-show="touched || @js($lot['additional_cost'] !== '')" role="alert" class="text-sm text-red-700">{{ $evaluation['errors']['additional_cost'][0] }}</p>@endif</x-field>
                    </div>
                    @if($result)
                        <dl class="viability-highlight grid grid-cols-2 gap-4"><div><dt>Custo por unidade</dt><dd><x-money :value="$result['unit_cost']"/></dd></div><div><dt>Custo total do lote</dt><dd><x-money :value="$result['total_cost']"/></dd></div></dl>
                        <p class="text-sm text-stone-600">{{ $result['units'] }} un. · {{ \App\Services\DecimalPresentation::weight($result['finished_weight_grams']) }} · Insumos: <x-money :value="$result['material_cost']"/></p>
                        <details class="mt-4 border-t border-stone-200 pt-3"><summary class="cursor-pointer text-xs font-medium text-cajubi-700">Consumo de insumos, incluindo kits</summary><div class="mt-3 overflow-x-auto"><table class="w-full text-left text-xs"><thead class="text-stone-500"><tr><th scope="col" class="pb-2">Insumo</th><th scope="col" class="pb-2 text-right">Quantidade</th><th scope="col" class="pb-2 text-right">Custo</th></tr></thead><tbody class="divide-y divide-stone-100">@foreach($result['supplies'] as $id => $supply)<tr wire:key="lot-{{ $lot['key'] }}-supply-{{ $id }}"><th scope="row" class="py-2 pr-3 font-normal">{{ $supply['name'] }}</th><td class="whitespace-nowrap py-2 text-right">{{ \App\Services\DecimalPresentation::quantity($supply['quantity']) }} {{ $supply['unit'] }}</td><td class="whitespace-nowrap py-2 pl-3 text-right"><x-money :value="$supply['total_cost']"/></td></tr>@endforeach</tbody></table></div></details>
                    @else
                        <p class="text-sm text-stone-500">Lote pendente</p>
                        @if(isset($evaluation['errors']['composition']))<p role="alert" class="text-sm text-red-700">{{ $evaluation['errors']['composition'][0] }}</p>@endif
                        @if($product_id !== '' && isset($evaluation['errors']['product_id']))<p role="alert" class="text-sm text-red-700">{{ $evaluation['errors']['product_id'][0] }}</p>@endif
                    @endif
                </article>
            @endforeach
        </div>
        @if($lots === [])<p class="text-sm text-stone-500">Adicione um lote para começar.</p>@endif
        <p class="text-sm text-stone-500">Adicionais são o total do lote. Não repita custos da composição; informe 0 quando não houver gasto.</p>
    </section>

    <section id="investimento" class="panel grid gap-5 p-5 sm:p-6" aria-labelledby="investimento-title">
        <h2 id="investimento-title" class="viability-heading"><span>2</span> Planejar o investimento</h2>
        <div class="grid items-start gap-5 2xl:grid-cols-2">
            <div class="grid min-w-0 gap-4 rounded-xl border border-stone-200 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-semibold">Investimento inicial</h3><button type="button" wire:click="addExpense" class="btn-secondary">+ Adicionar desembolso</button></div>
                <p class="text-sm text-stone-600">O lote já está incluído. Embalagens e rótulos da composição não devem ser somados novamente.</p>
        @foreach($expenses as $index => $expense)
            <div wire:key="viability-expense-{{ $expense['key'] }}" class="grid gap-3 rounded-lg border border-stone-200 p-4 sm:grid-cols-2">
                <x-field x-data="{ touched: false }" @focusout="touched = true" label="Descrição *" for="expenses.{{ $index }}.name"><input id="expenses.{{ $index }}.name" wire:model.live.debounce.400ms="expenses.{{ $index }}.name" class="field" maxlength="150">@if(isset($fundingState['errors']["expenses.$index.name"]))<p x-cloak x-show="touched || @js($expense['name'] !== '')" role="alert" class="text-sm text-red-700">{{ $fundingState['errors']["expenses.$index.name"][0] }}</p>@endif</x-field>
                <x-field x-data="{ touched: false }" @focusout="touched = true" label="Categoria *" for="expenses.{{ $index }}.category"><select id="expenses.{{ $index }}.category" wire:model.live="expenses.{{ $index }}.category" class="field"><option value="">Selecione</option><option value="equipment">Equipamentos e estrutura</option><option value="inventory">Estoque e insumos</option><option value="startup">Despesa inicial não recuperável</option></select>@if(isset($fundingState['errors']["expenses.$index.category"]))<p x-cloak x-show="touched || @js($expense['category'] !== '')" role="alert" class="text-sm text-red-700">{{ $fundingState['errors']["expenses.$index.category"][0] }}</p>@endif</x-field>
                <x-field x-data="{ touched: false }" @focusout="touched = true" label="Como considerar *" for="expenses.{{ $index }}.coverage"><select id="expenses.{{ $index }}.coverage" wire:model.live="expenses.{{ $index }}.coverage" class="field"><option value="">Selecione</option><option value="additional">Somar: desembolso adicional</option><option value="included_in_lot">Já incluído no lote: não somar</option></select>@if(isset($fundingState['errors']["expenses.$index.coverage"]))<p x-cloak x-show="touched || @js($expense['coverage'] !== '')" role="alert" class="text-sm text-red-700">{{ $fundingState['errors']["expenses.$index.coverage"][0] }}</p>@endif</x-field>
                <x-field x-data="{ touched: false }" @focusout="touched = true" label="Valor previsto (R$) *" for="expenses.{{ $index }}.amount"><input id="expenses.{{ $index }}.amount" wire:model.live.debounce.400ms="expenses.{{ $index }}.amount" class="field" inputmode="decimal">@if(isset($fundingState['errors']["expenses.$index.amount"]))<p x-cloak x-show="touched || @js($expense['amount'] !== '')" role="alert" class="text-sm text-red-700">{{ $fundingState['errors']["expenses.$index.amount"][0] }}</p>@endif</x-field>
                <button type="button" wire:click="removeExpense({{ $index }})" class="justify-self-start text-xs text-stone-500 hover:text-red-700" aria-label="Remover desembolso {{ $index + 1 }}">Remover desembolso</button>
            </div>
        @endforeach

                <label class="flex items-start gap-2 text-sm leading-6"><input type="checkbox" wire:model.live="expenses_reviewed" class="mt-1 size-4 shrink-0 accent-cajubi-700"> Revisei os desembolsos e os custos já incluídos no lote, inclusive quando não há outros gastos.</label>
                @if($funding)<div class="viability-highlight"><p>Lote + demais desembolsos iniciais</p><strong><x-money :value="$funding['initial_resources']"/></strong></div>@endif
            </div>
            <div id="giro" class="grid min-w-0 gap-4 rounded-xl border border-stone-200 p-4">
                <h3 class="font-semibold">Capital de giro</h3>
                <p class="text-sm text-stone-600">Prazos desde o início do lote, incluindo produção, venda e liberação do recebimento.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field x-data="{ touched: false }" @focusout="touched = true" label="Até receber as vendas (dias) *" for="receipt_days"><input id="receipt_days" wire:model.live.debounce.400ms="receipt_days" class="field" inputmode="numeric">@if(isset($fundingState['errors']['receipt_days']))<p x-cloak x-show="touched || @js($receipt_days !== '')" role="alert" class="text-sm text-red-700">{{ $fundingState['errors']['receipt_days'][0] }}</p>@endif</x-field>
            <x-field x-data="{ touched: false }" @focusout="touched = true" label="Pagamento ao fornecedor (dias) *" for="supplier_days"><input id="supplier_days" wire:model.live.debounce.400ms="supplier_days" class="field" inputmode="numeric">@if(isset($fundingState['errors']['supplier_days']))<p x-cloak x-show="touched || @js($supplier_days !== '')" role="alert" class="text-sm text-red-700">{{ $fundingState['errors']['supplier_days'][0] }}</p>@endif</x-field>
            <x-field x-data="{ touched: false }" @focusout="touched = true" label="Despesas operacionais / mês (R$) *" for="monthly_operating" hint="Sem repetir o lote ou os gastos iniciais acima."><input id="monthly_operating" wire:model.live.debounce.400ms="monthly_operating" class="field" inputmode="decimal">@if(isset($fundingState['errors']['monthly_operating']))<p x-cloak x-show="touched || @js($monthly_operating !== '')" role="alert" class="text-sm text-red-700">{{ $fundingState['errors']['monthly_operating'][0] }}</p>@endif</x-field>
            <x-field x-data="{ touched: false }" @focusout="touched = true" label="Reserva financeira (R$) *" for="reserve"><input id="reserve" wire:model.live.debounce.400ms="reserve" class="field" inputmode="decimal">@if(isset($fundingState['errors']['reserve']))<p x-cloak x-show="touched || @js($reserve !== '')" role="alert" class="text-sm text-red-700">{{ $fundingState['errors']['reserve'][0] }}</p>@endif</x-field>
        </div>

                @if($funding)<div class="viability-highlight"><p>Capital de giro estimado</p><strong><x-money :value="$funding['working_capital']"/></strong><p class="mt-2 text-sm text-stone-600"><x-money :value="$funding['already_in_initial']"/> já incluídos no investimento. Não some novamente.</p></div>@endif
            </div>
        </div>
    </section>

    <section class="panel grid gap-5 p-5 sm:p-6" aria-labelledby="resultados-title">
        <h2 id="resultados-title" class="viability-heading"><span>3</span> Resultados estimados</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach(['total_cost' => 'Custo do lote', 'unit_cost' => 'Custo por unidade'] as $field => $label)<div class="viability-stat"><h3>{{ $label }}</h3><p>@if($baseline)<x-money :value="$baseline[$field]"/>@else — @endif</p></div>@endforeach
            @foreach(['working_capital' => 'Capital de giro estimado', 'total_resources' => 'Total de recursos planejados'] as $field => $label)<div @class(['viability-stat', 'viability-highlight' => $field === 'total_resources'])><h3>{{ $label }}</h3><p>@if($funding)<x-money :value="$funding[$field]"/>@else — @endif</p></div>@endforeach
        </div>
        @if(!$funding)<p class="text-sm text-stone-500">Complete o lote, os prazos e a revisão dos desembolsos para estimar os recursos.</p>@else
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
                @foreach(['equipment' => 'Equipamentos e estrutura', 'extra_inventory' => 'Estoque além do lote', 'startup_expenses' => 'Despesas iniciais não recuperáveis', 'operating_bridge' => 'Operação até o recebimento', 'reserve' => 'Reserva financeira', 'excluded_duplicates' => 'Já incluídos: não somados'] as $field => $label)<div class="flex justify-between gap-3 border-b border-stone-100 pb-2"><dt>{{ $label }}</dt><dd class="shrink-0 font-medium"><x-money :value="$funding[$field]"/></dd></div>@endforeach
            </dl>
            <p class="text-sm text-stone-600">Intervalo entre pagamento e recebimento: {{ $funding['gap_days'] }} dia(s). O total não desconta vendas futuras.</p>
        @endif
        <details class="rounded-lg border border-stone-200 p-4 text-sm text-stone-600"><summary class="cursor-pointer font-medium text-cajubi-700">Como o caixa é estimado</summary><p class="mt-3 leading-6">A operação usa os dias até receber, mês de 30 dias e arredondamento para cima ao centavo. O giro inclui insumos pagos antes ou no dia do recebimento, estoque adicional, adicionais, operação e reserva. Valores já presentes no investimento não são somados outra vez ao total. Não considera parcelas, lotes recorrentes ou retorno do investimento.</p></details>
        <details id="sensibilidade" wire:ignore.self class="rounded-lg border border-stone-200 p-4">
            <summary class="cursor-pointer font-medium text-cajubi-700">Análise de sensibilidade de custos</summary>
            <div class="mt-5 grid gap-4">
        <x-field label="Insumo para testar" for="sensitivity_supply_id" hint="O preço alternativo usa a unidade de medida cadastrada; para castanha por kg, escolha o insumo em kg."><select id="sensitivity_supply_id" wire:model.live="sensitivity_supply_id" class="field"><option value="">Selecione um insumo da composição</option>@foreach($this->supplyContext['supplies'] as $supply)<option value="{{ $supply->id }}" wire:key="viability-sensitive-{{ $supply->id }}">{{ $supply->name }} · R$ / {{ $supply->unit }}</option>@endforeach</select></x-field>

        @if($baseline = $this->lotResults[$selected_lot]['result'] ?? null)
            <div class="flex flex-wrap gap-x-6 gap-y-2 rounded-lg bg-stone-50 px-4 py-3 text-xs leading-5 text-stone-600"><p class="font-semibold">Referência com custos cadastrados</p><p>Unidade: <x-money :value="$baseline['unit_cost']"/></p><p>Lote: <x-money :value="$baseline['total_cost']"/></p>@if($this->funding['result'])<p>Recursos: <x-money :value="$this->funding['result']['total_resources']"/></p><p>Giro: <x-money :value="$this->funding['result']['working_capital']"/></p>@else<p>Recursos e giro pendentes de informações.</p>@endif</div>
        @endif
        <div class="grid items-start gap-4 sm:grid-cols-2 2xl:grid-cols-4">
            @foreach($prices as $index => $price)
                @php($sensitivity = $this->sensitivities[$index] ?? null)
                <div wire:key="viability-price-{{ $index }}" class="grid gap-3 rounded-lg border border-stone-200 p-4">
                    <x-field label="Custo alternativo {{ $index + 1 }} (R$)" for="prices.{{ $index }}"><input id="prices.{{ $index }}" wire:model.live.debounce.400ms="prices.{{ $index }}" class="field" inputmode="decimal"></x-field>
                    @if($sensitivity && $sensitivity['error'])<p class="text-xs text-amber-900" role="alert">{{ $sensitivity['error'] }}</p>
                    @elseif($sensitivity)
                        <dl class="grid gap-2 text-xs"><div><dt class="text-stone-500">Custo por unidade acabada</dt><dd class="mt-1 font-semibold"><x-money :value="$sensitivity['lot']['unit_cost']"/></dd></div><div><dt class="text-stone-500">Custo do lote</dt><dd class="mt-1"><x-money :value="$sensitivity['lot']['total_cost']"/></dd></div><div><dt class="text-stone-500">Total de recursos</dt><dd class="mt-1">@if($sensitivity['funding'])<x-money :value="$sensitivity['funding']['total_resources']"/>@else Complete o investimento e os prazos. @endif</dd></div><div><dt class="text-stone-500">Capital de giro</dt><dd class="mt-1">@if($sensitivity['funding'])<x-money :value="$sensitivity['funding']['working_capital']"/>@else Pendente @endif</dd></div></dl>
                    @endif
                    <button type="button" wire:click="removePrice({{ $index }})" class="justify-self-start text-xs text-stone-500 hover:text-red-700" aria-label="Remover custo alternativo {{ $index + 1 }}">Remover</button>
                </div>
            @endforeach
        </div>
        <button type="button" wire:click="addPrice" class="btn-secondary justify-self-start">+ Adicionar custo alternativo</button>

            </div>
        </details>
        <p class="text-sm text-stone-500">Simulação temporária: não altera custos cadastrados nem registra compras, estoque ou vendas.</p>
    </section>
</div>
