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

<div class="grid gap-6">
    <div class="grid gap-2"><p class="eyebrow">Planejamento da operação</p><h1 class="page-title">Viabilidade comercial</h1><p class="text-sm text-stone-500">Compare lotes e estime os recursos antes de comprar. Simulações temporárias, sem alterar os cadastros.</p></div>
    <nav class="flex flex-wrap gap-2 text-sm" aria-label="Seções da viabilidade">
        @foreach(['lotes' => 'Lotes', 'investimento' => 'Investimento inicial', 'giro' => 'Capital de giro', 'sensibilidade' => 'Sensibilidade'] as $anchor => $label)<a href="#{{ $anchor }}" class="rounded-lg border border-stone-200 bg-white px-4 py-2 font-medium text-cajubi-700 hover:bg-cajubi-50">{{ $label }}</a>@endforeach
    </nav>
    <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-900">Preencha valores desconhecidos antes de concluir. Informe 0 somente quando não houver gasto. Contribuição por venda não representa dinheiro recebido nem lucro líquido. Os resultados não registram compras, estoque ou vendas reais.</p>

    <section id="lotes" class="panel grid scroll-mt-6 gap-5 p-5 sm:p-6" aria-labelledby="lotes-title">
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="lotes-title" class="text-lg font-semibold">Lotes</h2><p class="mt-1 text-xs text-stone-500">Informe unidades acabadas. Peso do lote e consumo de insumos são apresentados separadamente.</p></div><button type="button" wire:click="addLot" class="btn-secondary">+ Adicionar lote</button></div>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field label="Produto *" for="product_id"><select id="product_id" wire:model.live="product_id" class="field"><option value="">Selecione um produto</option>@foreach($this->products as $product)<option value="{{ $product->id }}" wire:key="viability-product-{{ $product->id }}">{{ $product->name }} · {{ $product->sku }}</option>@endforeach</select></x-field>
            <x-field label="Lote para investimento e sensibilidade" for="selected_lot"><select id="selected_lot" wire:model.live="selected_lot" class="field">@foreach($lots as $lot)<option value="{{ $lot['key'] }}" wire:key="chosen-lot-{{ $lot['key'] }}">{{ $lot['name'] }}</option>@endforeach</select></x-field>
        </div>
        @if($this->supplyContext['product'])<p class="text-xs text-stone-600">Peso líquido por unidade acabada: <strong>{{ \App\Services\DecimalPresentation::weight($this->supplyContext['product']->net_weight_grams) }}</strong>. O consumo segue a composição cadastrada, inclusive perdas já informadas nela; nenhum rendimento adicional é presumido.</p>@endif
        @if($this->supplyContext['warning'])<p role="alert" class="text-sm text-amber-900">{{ $this->supplyContext['warning'] }}</p>@endif
        <div class="grid items-start gap-4 xl:grid-cols-2">
            @foreach($lots as $index => $lot)
                @php($evaluation = $this->lotResults[$lot['key']])
                @php($result = $evaluation['result'])
                <article wire:key="viability-lot-{{ $lot['key'] }}" @class(['min-w-0 rounded-lg border p-4', 'border-cajubi-200 bg-cajubi-50/30' => $selected_lot === $lot['key'], 'border-stone-200' => $selected_lot !== $lot['key']])>
                    <div class="mb-4 flex items-center justify-between gap-2"><h3 class="text-sm font-semibold">{{ $lot['name'] }} @if($selected_lot === $lot['key'])<span class="ml-1 text-[10px] font-normal text-cajubi-700">Selecionado</span>@endif</h3><button type="button" wire:click="removeLot({{ $index }})" class="text-xs text-stone-500 hover:text-red-700" aria-label="Remover {{ $lot['name'] }}">Remover</button></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field label="Unidades acabadas *" for="lots.{{ $index }}.units"><input id="lots.{{ $index }}.units" wire:model.live.debounce.400ms="lots.{{ $index }}.units" class="field" inputmode="numeric" placeholder="Ex.: 10"></x-field>
                        <x-field label="Adicionais do lote (R$) *" for="lots.{{ $index }}.additional_cost" hint="Total do lote, sem repetir insumos da composição."><input id="lots.{{ $index }}.additional_cost" wire:model.live.debounce.400ms="lots.{{ $index }}.additional_cost" class="field" inputmode="decimal"></x-field>
                    </div>
                    @if($result)
                        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-stone-200 pt-4 text-sm">
                            <div><dt class="text-xs text-stone-500">Produto acabado</dt><dd class="mt-1 font-medium">{{ $result['units'] }} un. · {{ \App\Services\DecimalPresentation::weight($result['finished_weight_grams']) }}</dd></div>
                            <div><dt class="text-xs text-stone-500">Custo por unidade</dt><dd class="mt-1 font-semibold text-cajubi-700"><x-money :value="$result['unit_cost']"/></dd></div>
                            <div><dt class="text-xs text-stone-500">Insumos do lote</dt><dd class="mt-1"><x-money :value="$result['material_cost']"/></dd></div>
                            <div><dt class="text-xs text-stone-500">Custo total do lote</dt><dd class="mt-1 font-semibold"><x-money :value="$result['total_cost']"/></dd></div>
                        </dl>
                        <details class="mt-4 border-t border-stone-200 pt-3"><summary class="cursor-pointer text-xs font-medium text-cajubi-700">Consumo de insumos, incluindo kits</summary><div class="mt-3 overflow-x-auto"><table class="w-full text-left text-xs"><thead class="text-stone-500"><tr><th scope="col" class="pb-2">Insumo</th><th scope="col" class="pb-2 text-right">Quantidade</th><th scope="col" class="pb-2 text-right">Custo</th></tr></thead><tbody class="divide-y divide-stone-100">@foreach($result['supplies'] as $id => $supply)<tr wire:key="lot-{{ $lot['key'] }}-supply-{{ $id }}"><th scope="row" class="py-2 pr-3 font-normal">{{ $supply['name'] }}</th><td class="whitespace-nowrap py-2 text-right">{{ \App\Services\DecimalPresentation::quantity($supply['quantity']) }} {{ $supply['unit'] }}</td><td class="whitespace-nowrap py-2 pl-3 text-right"><x-money :value="$supply['total_cost']"/></td></tr>@endforeach</tbody></table></div></details>
                    @else
                        <div class="mt-4 rounded-lg bg-stone-50 p-3"><p class="text-sm font-medium text-stone-600">Lote pendente</p><ul class="mt-2 grid gap-1 text-xs leading-5 text-amber-900">@foreach($evaluation['errors'] as $messages)<li>{{ $messages[0] }}</li>@endforeach</ul></div>
                    @endif
                </article>
            @endforeach
        </div>
        @if($lots === [])<p class="text-sm text-stone-500">Adicione um lote para começar.</p>@endif
    </section>

    <section id="investimento" class="panel grid scroll-mt-6 gap-5 p-5 sm:p-6" aria-labelledby="investimento-title">
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="investimento-title" class="text-lg font-semibold">Investimento inicial</h2><p class="mt-1 text-xs text-stone-500">O lote selecionado já entra no planejamento. Informe apenas os demais desembolsos ou marque o que já está incluído.</p></div><button type="button" wire:click="addExpense" class="btn-secondary">+ Adicionar desembolso</button></div>
        @foreach($expenses as $index => $expense)
            <div wire:key="viability-expense-{{ $expense['key'] }}" class="grid gap-3 rounded-lg border border-stone-200 p-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-field label="Descrição *" for="expenses.{{ $index }}.name"><input id="expenses.{{ $index }}.name" wire:model.live.debounce.400ms="expenses.{{ $index }}.name" class="field" maxlength="150"></x-field>
                <x-field label="Categoria *" for="expenses.{{ $index }}.category"><select id="expenses.{{ $index }}.category" wire:model.live="expenses.{{ $index }}.category" class="field"><option value="">Selecione</option><option value="equipment">Equipamentos e estrutura</option><option value="inventory">Estoque e insumos</option><option value="startup">Despesa inicial não recuperável</option></select></x-field>
                <x-field label="Como considerar *" for="expenses.{{ $index }}.coverage"><select id="expenses.{{ $index }}.coverage" wire:model.live="expenses.{{ $index }}.coverage" class="field"><option value="">Selecione</option><option value="additional">Somar: desembolso adicional</option><option value="included_in_lot">Já incluído no lote: não somar</option></select></x-field>
                <x-field label="Valor previsto (R$) *" for="expenses.{{ $index }}.amount"><input id="expenses.{{ $index }}.amount" wire:model.live.debounce.400ms="expenses.{{ $index }}.amount" class="field" inputmode="decimal"></x-field>
                <button type="button" wire:click="removeExpense({{ $index }})" class="justify-self-start text-xs text-stone-500 hover:text-red-700" aria-label="Remover desembolso {{ $index + 1 }}">Remover desembolso</button>
            </div>
        @endforeach
        @if($expenses === [])<p class="rounded-lg bg-stone-50 p-3 text-sm text-stone-500">Nenhum desembolso adicional informado. Confirme abaixo apenas se não houver outros gastos iniciais.</p>@endif
        <label class="flex items-start gap-2 text-sm leading-6"><input type="checkbox" wire:model.live="expenses_reviewed" class="mt-1 size-4 shrink-0 accent-cajubi-700"> Revisei os desembolsos e as marcações de custos já incluídos no lote, ou confirmei que não há outros gastos iniciais.</label>
        <p class="text-xs leading-5 text-stone-500">Embalagens já presentes na composição não devem ser somadas novamente. Marcar “já incluído” deixa o valor apenas informativo. Ao trocar o lote, confira novamente o que está coberto. Estas previsões não são um controle de estoque nem um registro contábil.</p>
    </section>

    <section id="giro" class="panel grid scroll-mt-6 gap-5 p-5 sm:p-6" aria-labelledby="giro-title">
        <div><h2 id="giro-title" class="text-lg font-semibold">Capital de giro</h2><p class="mt-1 text-xs leading-5 text-stone-500">Conte os prazos desde o início do lote. O recebimento deve incluir o tempo de produção, venda e liberação do dinheiro.</p></div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-field label="Até receber as vendas (dias) *" for="receipt_days"><input id="receipt_days" wire:model.live.debounce.400ms="receipt_days" class="field" inputmode="numeric"></x-field>
            <x-field label="Pagamento ao fornecedor (dias) *" for="supplier_days"><input id="supplier_days" wire:model.live.debounce.400ms="supplier_days" class="field" inputmode="numeric"></x-field>
            <x-field label="Despesas operacionais / mês (R$) *" for="monthly_operating" hint="Sem repetir o lote ou os gastos iniciais acima."><input id="monthly_operating" wire:model.live.debounce.400ms="monthly_operating" class="field" inputmode="decimal"></x-field>
            <x-field label="Reserva financeira (R$) *" for="reserve"><input id="reserve" wire:model.live.debounce.400ms="reserve" class="field" inputmode="decimal"></x-field>
        </div>
        @if($this->funding['result'])
            @php($funding = $this->funding['result'])
            <div class="grid gap-5 lg:grid-cols-2">
                <dl class="grid gap-3 text-sm">
                    @foreach(['equipment' => 'Equipamentos e estrutura', 'extra_inventory' => 'Compras de estoque além do lote', 'startup_expenses' => 'Despesas iniciais não recuperáveis', 'initial_resources' => 'Lote + demais desembolsos iniciais', 'operating_bridge' => 'Operação até o recebimento', 'reserve' => 'Reserva financeira'] as $field => $label)<div class="flex items-start justify-between gap-4 border-b border-stone-100 pb-2"><dt class="text-stone-600">{{ $label }}</dt><dd class="shrink-0 font-medium"><x-money :value="$funding[$field]"/></dd></div>@endforeach
                </dl>
                <div class="grid content-start gap-4 rounded-lg border border-cajubi-200 bg-cajubi-50 p-5">
                    <div><h3 class="text-sm font-medium text-cajubi-800">Total de recursos planejados</h3><p class="mt-2 text-3xl font-semibold tracking-tight text-cajubi-800"><x-money :value="$funding['total_resources']"/></p><p class="mt-2 text-xs leading-5 text-stone-600">Lote, desembolsos adicionais, operação até receber e reserva. Não abate vendas futuras ou crédito do fornecedor.</p></div>
                    <div class="border-t border-cajubi-200 pt-4 text-sm"><p>Capital de giro estimado: <strong><x-money :value="$funding['working_capital']"/></strong></p><p class="mt-2 text-xs leading-5 text-stone-600">Deste valor, <x-money :value="$funding['already_in_initial']"/> já estão nos desembolsos iniciais. Não some novamente. Intervalo entre pagamento e recebimento: {{ $funding['gap_days'] }} dia(s).</p></div>
                    <p class="text-xs leading-5 text-stone-600">Valores marcados como já incluídos, não somados: <x-money :value="$funding['excluded_duplicates']"/>.</p>
                </div>
            </div>
        @else
            <div class="rounded-lg border border-dashed border-stone-200 bg-stone-50 p-4"><p class="text-sm font-semibold text-stone-600">Recursos ainda pendentes</p><ul class="mt-2 grid gap-1 text-xs leading-5 text-amber-900">@foreach($this->funding['errors'] as $field => $messages)<li wire:key="viability-funding-error-{{ $field }}">{{ $messages[0] }}</li>@endforeach</ul></div>
        @endif
        <details class="rounded-lg border border-stone-200 p-4 text-xs leading-6 text-stone-600"><summary class="cursor-pointer font-medium text-cajubi-700">Como esta estimativa considera o caixa</summary><p class="mt-3">As despesas operacionais são proporcionais aos dias até o recebimento, usando mês de 30 dias e arredondamento para cima ao centavo. O intervalo financeiro é o prazo de recebimento menos o prazo de pagamento, limitado a zero.</p><p class="mt-2">O capital de giro inclui os insumos do lote pagos antes ou no mesmo dia do recebimento, o estoque adicional, os adicionais do lote, a operação até receber e a reserva. No mesmo dia, é prudente não presumir que o recebimento antecederá o pagamento. Estoque e adicionais já incluídos no investimento não são somados novamente ao total.</p><p class="mt-2">O total é um orçamento bruto de recursos para o lote selecionado, sem descontar receitas futuras: não presume que as vendas cobrirão os fornecedores. Não contempla recebimentos parcelados, reinvestimento em lotes recorrentes ou retorno do investimento.</p></details>
    </section>

    <section id="sensibilidade" class="panel grid scroll-mt-6 gap-5 p-5 sm:p-6" aria-labelledby="sensibilidade-title">
        <div><h2 id="sensibilidade-title" class="text-lg font-semibold">Sensibilidade</h2><p class="mt-1 text-xs leading-5 text-stone-500">Teste custos alternativos de um insumo no lote selecionado. Custos cadastrados e históricos permanecem intactos.</p></div>
        <x-field label="Insumo para testar" for="sensitivity_supply_id" hint="O preço alternativo usa a unidade de medida cadastrada; para castanha por kg, escolha o insumo em kg."><select id="sensitivity_supply_id" wire:model.live="sensitivity_supply_id" class="field"><option value="">Selecione um insumo da composição</option>@foreach($this->supplyContext['supplies'] as $supply)<option value="{{ $supply->id }}" wire:key="viability-sensitive-{{ $supply->id }}">{{ $supply->name }} · R$ / {{ $supply->unit }}</option>@endforeach</select></x-field>
        <p class="text-xs text-stone-500">Exemplos editáveis de custo por kg: R$ 43,00, R$ 48,00, R$ 50,00 e R$ 55,00. São hipóteses; nenhum valor é aplicado automaticamente.</p>
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
                    @else<p class="text-xs leading-5 text-stone-500">Selecione o insumo e informe um custo alternativo.</p>@endif
                    <button type="button" wire:click="removePrice({{ $index }})" class="justify-self-start text-xs text-stone-500 hover:text-red-700" aria-label="Remover custo alternativo {{ $index + 1 }}">Remover</button>
                </div>
            @endforeach
        </div>
        <button type="button" wire:click="addPrice" class="btn-secondary justify-self-start">+ Adicionar custo alternativo</button>
    </section>
</div>
