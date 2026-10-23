<?php

use App\Models\Product;
use App\Models\Sale;
use App\Services\DecimalPresentation;
use App\Services\SalesLedger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Vendas')] class extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $selected_id = null;

    #[Locked]
    public ?int $editing_id = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $receipt_key = '';

    public bool $form_open = false;

    public array $form = [];

    public array $receipt = ['reference' => '', 'kind' => 'receipt', 'amount' => '', 'received_on' => '', 'notes' => ''];

    public string $cancellation_reason = '';

    protected SalesLedger $ledger;

    public function boot(SalesLedger $ledger): void
    {
        $this->ledger = $ledger;
    }

    public function mount(): void
    {
        $this->receipt_key = (string) Str::uuid();
        if (request()->boolean('nova')) {
            $this->newSale();
        }
    }

    public function newSale(): void
    {
        $this->resetValidation();
        $this->selected_id = null;
        $this->editing_id = null;
        $this->form_open = true;
        $this->form = array_fill_keys(SalesLedger::MONEY_FIELDS, '');
        $this->form += ['marketplace' => 'mercado_livre', 'order_reference' => '', 'sold_on' => now('America/Sao_Paulo')->toDateString(),
            'product_id' => '', 'quantity' => '1', 'freight_source' => '', 'expected_on' => '', 'notes' => '',
            'reconciliation_closed' => false, 'reconciliation_note' => ''];
        unset($this->sale);
        $this->dispatch('sale-form-opened');
    }

    public function viewSale(int $id): void
    {
        $sale = Sale::query()->findOrFail($id);
        $this->selected_id = $id;
        $this->editing_id = null;
        $this->version = $sale->version;
        $this->form_open = false;
        $this->receipt = ['reference' => '', 'kind' => 'receipt', 'amount' => '', 'received_on' => '', 'notes' => ''];
        $this->receipt_key = (string) Str::uuid();
        $this->cancellation_reason = '';
        $this->resetValidation();
        unset($this->sale);
        $this->dispatch('sale-opened');
    }

    public function editSale(int $id): void
    {
        $this->viewSale($id);
        $sale = $this->sale;
        $this->editing_id = $id;
        $this->form_open = true;
        $this->form = $sale->only(['marketplace', 'order_reference', 'product_id', 'quantity', 'freight_source', 'notes', 'reconciliation_closed', 'reconciliation_note']);
        $this->form['sold_on'] = $sale->sold_on->toDateString();
        $this->form['expected_on'] = $sale->expected_on?->toDateString() ?? '';
        foreach (SalesLedger::MONEY_FIELDS as $field) {
            $this->form[$field] = $sale->$field === null ? '' : DecimalPresentation::format($sale->$field, 2);
        }
        $this->dispatch('sale-form-opened');
    }

    public function saveSale(): void
    {
        try {
            $sale = $this->ledger->save($this->editing_id ? Sale::query()->findOrFail($this->editing_id) : null, $this->form, $this->version);
        } catch (ValidationException $exception) {
            $this->formErrors($exception, 'form');
        }
        $this->viewSale($sale->id);
        unset($this->sales, $this->summary);
        session()->flash('success', 'Venda salva. Nenhum recebimento foi presumido.');
    }

    public function addReceipt(): void
    {
        abort_unless($this->selected_id !== null, 404);
        try {
            $this->ledger->receive(Sale::query()->findOrFail($this->selected_id), $this->receipt, $this->receipt_key);
        } catch (ValidationException $exception) {
            $this->formErrors($exception, 'receipt');
        }
        $this->viewSale($this->selected_id);
        unset($this->sales, $this->summary);
        session()->flash('success', 'Recebimento ou estorno registrado no histórico.');
    }

    public function cancelSale(): void
    {
        abort_unless($this->selected_id !== null, 404);
        $this->ledger->cancel(Sale::query()->findOrFail($this->selected_id), $this->cancellation_reason, $this->version);
        $this->viewSale($this->selected_id);
        unset($this->sales, $this->summary);
        session()->flash('success', 'Venda cancelada. Recebimentos e histórico preservados.');
    }

    public function captureCost(): void
    {
        abort_unless($this->selected_id !== null, 404);
        $this->ledger->captureCost(Sale::query()->findOrFail($this->selected_id), $this->version);
        $this->viewSale($this->selected_id);
        unset($this->sales, $this->summary);
    }

    public function closeForm(): void
    {
        $this->form_open = false;
        $this->editing_id = null;
        $this->resetValidation();
    }

    #[Computed]
    public function sales(): LengthAwarePaginator
    {
        return Sale::query()->with('receipts')->orderByDesc('sold_on')->orderByDesc('id')->paginate(15);
    }

    #[Computed]
    public function sale(): ?Sale
    {
        return $this->selected_id ? Sale::query()->with(['receipts' => fn ($query) => $query->orderBy('received_on')->orderBy('id'), 'revisions' => fn ($query) => $query->orderByDesc('id')])->findOrFail($this->selected_id) : null;
    }

    /** @return Collection<int, Product> */
    #[Computed]
    public function products(): Collection
    {
        return Product::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'sku']);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function summary(): array
    {
        return $this->ledger->summary();
    }

    /** @return array<string, mixed> */
    public function figures(Sale $sale): array
    {
        return $this->ledger->figures($sale);
    }

    private function formErrors(ValidationException $exception, string $prefix): never
    {
        $errors = [];
        foreach ($exception->errors() as $key => $messages) {
            $errors[$prefix.'.'.$key] = $messages;
        }
        throw ValidationException::withMessages($errors);
    }
};
?>

<div class="grid gap-6" x-data x-on:sale-opened.window="$nextTick(() => document.getElementById('details-title')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))" x-on:sale-form-opened.window="$nextTick(() => document.getElementById('sale-form-title')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
    <x-success/>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid gap-2"><p class="eyebrow">Operação comercial / Vendas reais</p><h1 class="page-title">Vendas</h1><p class="text-sm text-stone-500">Registre os pedidos e confira os repasses do Mercado Livre e da Shopee.</p></div>
        <button type="button" wire:click="newSale" class="btn-primary">Nova venda</button>
    </div>
    @php($summary = $this->summary)
    <section aria-label="Resumo das vendas ativas" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="panel grid gap-2 p-4"><p class="text-xs text-stone-500">Vendas registradas</p><p class="text-2xl font-semibold">{{ $summary['count'] }}</p></div>
        @foreach(['gross' => 'Valor vendido', 'expected' => 'Líquido esperado', 'received' => 'Total recebido', 'pending' => 'Valor pendente'] as $key => $label)
            <div wire:key="summary-{{ $key }}" class="panel grid gap-2 p-4"><p class="text-xs text-stone-500">{{ $label }}</p><x-money :value="$summary[$key]" class="text-xl font-semibold"/></div>
        @endforeach
    </section>
    <p class="text-xs leading-5 text-stone-500">Resumo de vendas não canceladas. {{ $summary['unknown_gross'] }} sem valor vendido e {{ $summary['unknown_expected'] }} com repasse incompleto: valores desconhecidos não entram nos respectivos totais. {{ $summary['cancelled'] }} cancelada(s), com saldo recebido de <x-money :value="$summary['cancelled_received']"/> preservado à parte. Recebido = recebimentos menos estornos.</p>
    <x-errors/>

    @if($form_open)
        <section class="panel p-5 sm:p-6" aria-labelledby="sale-form-title">
            <div class="mb-5 grid gap-2"><h2 id="sale-form-title" class="text-lg font-semibold">{{ $editing_id ? 'Editar venda' : 'Nova venda' }}</h2><p class="text-xs text-stone-500">Um produto por pedido. Deixe valores desconhecidos em branco; informe zero somente quando confirmado.</p></div>
            <form wire:submit="saveSale" class="grid gap-5">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-field label="Marketplace *" for="form.marketplace"><select id="form.marketplace" wire:model="form.marketplace" class="field"><option value="mercado_livre">Mercado Livre</option><option value="shopee">Shopee</option></select></x-field>
                    <x-field label="Identificador do pedido *" for="form.order_reference"><input id="form.order_reference" wire:model="form.order_reference" class="field" maxlength="100" required></x-field>
                    <x-field label="Data da venda *" for="form.sold_on"><input id="form.sold_on" wire:model="form.sold_on" type="date" class="field" required></x-field>
                    <x-field label="Produto *" for="form.product_id"><select id="form.product_id" wire:model="form.product_id" class="field" required><option value="">Selecione</option>@foreach($this->products as $product)<option wire:key="product-{{ $product->id }}" value="{{ $product->id }}">{{ $product->name }} · {{ $product->sku }}</option>@endforeach</select></x-field>
                    <x-field label="Quantidade vendida *" for="form.quantity"><input id="form.quantity" wire:model="form.quantity" type="number" min="1" step="1" class="field" required></x-field>
                    <x-field label="Data prevista do repasse" for="form.expected_on"><input id="form.expected_on" wire:model="form.expected_on" type="date" class="field"></x-field>
                </div>
                <div class="border-t border-stone-100 pt-5"><h3 class="mb-3 font-semibold">Valores do pedido em reais</h3><div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach(['gross_amount' => 'Total dos produtos vendidos', 'seller_discount' => 'Desconto concedido pelo vendedor', 'marketplace_fees' => 'Tarifas e cobranças reais', 'seller_freight' => 'Frete pago pelo vendedor', 'other_adjustment' => 'Outros descontos / ajustes', 'expedition_cost' => 'Materiais de expedição do pedido', 'paid_labor' => 'Mão de obra efetivamente paga'] as $key => $label)
                        <x-field wire:key="field-{{ $key }}" :label="$label" :for="'form.'.$key"><input id="form.{{ $key }}" wire:model="form.{{ $key }}" class="field" inputmode="decimal" placeholder="Não informado"></x-field>
                    @endforeach
                    <x-field label="Como foi pago o frete?" for="form.freight_source"><select id="form.freight_source" wire:model="form.freight_source" class="field"><option value="">Ainda não informado / frete zero</option><option value="marketplace">Descontado do repasse</option><option value="external">Pago por fora ao transportador</option></select></x-field>
                </div><p class="mt-3 text-xs leading-5 text-stone-500">Tarifas reais são independentes do módulo Tarifas. Em outros ajustes, positivo desconta do repasse e negativo acrescenta um crédito. Materiais e mão de obra são totais do pedido, pagos por fora: não multiplique por unidade nem repita custos da composição.</p></div>
                <x-field label="Observações financeiras e da venda" for="form.notes"><textarea id="form.notes" wire:model="form.notes" class="field" rows="2" maxlength="3000"></textarea></x-field>
                <div class="grid gap-3 rounded-lg bg-stone-50 p-4">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.reconciliation_closed">Conferência concluída: não espero novos recebimentos para este pedido</label>
                    <x-field label="Conclusão da conferência" for="form.reconciliation_note"><textarea id="form.reconciliation_note" wire:model="form.reconciliation_note" class="field" rows="2" maxlength="3000" placeholder="Explique diferenças, ajustes ou a conclusão do repasse."></textarea></x-field>
                    <p class="text-xs text-stone-500">Uma diferença a menor só será divergente após esta confirmação. Reabra a conferência desmarcando a opção se houver novos repasses previstos.</p>
                </div>
                <p class="text-xs text-stone-500">O custo é preservado no cadastro. Editar valores ou quantidade mantém essa referência; trocar o produto captura uma nova referência e preserva a anterior no histórico. Com recebimentos, a identificação e o produto ficam protegidos.</p>
                <div class="flex justify-end gap-3"><button type="button" wire:click="closeForm" class="btn-secondary">Cancelar edição</button><button class="btn-primary" wire:loading.attr="disabled" wire:target="saveSale">Salvar venda</button></div>
            </form>
        </section>
    @endif

    <section class="panel overflow-hidden" aria-labelledby="sales-title">
        <h2 id="sales-title" class="p-5 font-semibold">Lista de vendas</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="table-head"><tr><th class="px-4 py-3">Data / marketplace</th><th class="px-4 py-3">Pedido / produto</th><th class="px-4 py-3">Valor vendido</th><th class="px-4 py-3">Repasse</th><th class="px-4 py-3">Situação</th><th class="px-4 py-3"><span class="sr-only">Ações</span></th></tr></thead><tbody class="divide-y divide-stone-100">
            @forelse($this->sales as $sale)
                @php($figures = $this->figures($sale))
                <tr wire:key="sale-{{ $sale->id }}"><td class="px-4 py-4">{{ $sale->sold_on->format('d/m/Y') }}<span class="block text-xs text-stone-500">{{ $sale->marketplace === 'mercado_livre' ? 'Mercado Livre' : 'Shopee' }}</span></td><td class="px-4 py-4"><span class="font-medium">{{ $sale->order_reference }}</span><span class="block text-xs text-stone-500">{{ $sale->product_snapshot['name'] }} · {{ $sale->quantity }} un.</span></td><td class="px-4 py-4">@if($sale->gross_amount !== null)<x-money :value="$sale->gross_amount"/>@else<span class="text-xs text-stone-500">Não informado</span>@endif</td><td class="px-4 py-4"><x-money :value="$figures['received']"/><span class="block text-xs text-stone-500">@if($figures['expected'] !== null)de <x-money :value="$figures['expected']"/>@else Esperado incompleto @endif</span></td><td class="px-4 py-4"><span @class(['rounded px-2 py-1 text-xs font-semibold', 'bg-red-50 text-red-800' => $figures['status'] === 'Divergente', 'bg-cajubi-50 text-cajubi-800' => $figures['status'] === 'Recebido', 'bg-stone-100 text-stone-700' => !in_array($figures['status'], ['Divergente', 'Recebido'])])>{{ $sale->cancelled_at ? 'Cancelada · ' : '' }}{{ $figures['status'] }}</span></td><td class="px-4 py-4"><button type="button" wire:click="viewSale({{ $sale->id }})" class="btn-secondary" aria-label="Detalhes do pedido {{ $sale->order_reference }}">Detalhes</button></td></tr>
            @empty
                <tr><td colspan="6" class="p-10 text-center text-stone-500">Nenhuma venda registrada. Comece pelo primeiro pedido real.</td></tr>
            @endforelse
        </tbody></table></div><div class="px-5 py-3">{{ $this->sales->links() }}</div>
    </section>

    @if($this->sale && !$form_open)
        @php($sale = $this->sale)
        @php($figures = $this->figures($sale))
        <section class="grid gap-5" aria-labelledby="details-title">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 id="details-title" class="text-xl font-semibold">Pedido {{ $sale->order_reference }}</h2><p class="mt-1 text-sm text-stone-500">{{ $sale->product_snapshot['name'] }} · {{ $sale->quantity }} un. · {{ $sale->marketplace === 'mercado_livre' ? 'Mercado Livre' : 'Shopee' }}</p></div>@if(!$sale->cancelled_at)<button type="button" wire:click="editSale({{ $sale->id }})" class="btn-secondary">Editar venda</button>@endif</div>
            @if($sale->cancelled_at)<p class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Cancelada em {{ $sale->cancelled_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}: {{ $sale->cancellation_reason }}. O cancelamento não estorna valores automaticamente; o histórico permanece disponível.</p>@endif
            <div class="grid items-start gap-5 lg:grid-cols-2">
                <div class="panel grid gap-4 p-5"><h3 class="font-semibold">Custos e resultado</h3><dl class="grid gap-3 text-sm">
                    @foreach(['gross_amount' => 'Total vendido', 'seller_discount' => 'Desconto do vendedor', 'marketplace_fees' => 'Tarifas reais', 'seller_freight' => 'Frete do vendedor', 'other_adjustment' => 'Outros descontos / ajustes', 'expedition_cost' => 'Expedição por pedido', 'paid_labor' => 'Mão de obra paga por pedido'] as $key => $label)
                        <div wire:key="detail-{{ $key }}" class="flex justify-between gap-3"><dt>{{ $label }}</dt><dd>@if($sale->$key !== null)<x-money :value="$sale->$key"/>@else<span class="text-stone-400">Não informado</span>@endif</dd></div>
                    @endforeach
                    @foreach(['revenue' => 'Receita após desconto do vendedor', 'expected' => 'Líquido esperado do marketplace', 'cost' => 'Custo dos produtos vendidos', 'contribution' => 'Contribuição estimada'] as $key => $label)
                        <div wire:key="result-{{ $key }}" class="flex justify-between gap-3 border-t border-stone-100 pt-3 font-semibold"><dt>{{ $label }}</dt><dd>@if($figures[$key] !== null)<x-money :value="$figures[$key]"/>@else<span class="text-xs font-normal text-amber-800">Dados incompletos</span>@endif</dd></div>
                    @endforeach
                </dl><p class="text-xs leading-5 text-stone-500">Contribuição estimada não é lucro líquido. Frete {{ $sale->freight_source === 'marketplace' ? 'descontado do repasse' : ($sale->freight_source === 'external' ? 'pago por fora' : 'sem origem informada') }}. Materiais e mão de obra pagos por fora reduzem a contribuição, sem reduzir novamente o repasse.</p>
                    @if($sale->cost_snapshot)
                        <details class="text-xs"><summary class="cursor-pointer text-cajubi-700">Referência de custo preservada em {{ $sale->cost_captured_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</summary><p class="mt-2 text-stone-500">Custo unitário: <x-money :value="$sale->cost_snapshot['unit_cost']"/>. Referência do cadastro nessa data; não é uma reconstrução de custos anteriores.</p><ul class="mt-2 grid gap-1">@foreach($sale->cost_snapshot['supplies'] as $id => $supply)<li wire:key="cost-supply-{{ $id }}">{{ $supply['name'] }} · {{ \App\Services\DecimalPresentation::quantity($supply['quantity']) }} {{ $supply['unit'] }} × R$ {{ \App\Services\DecimalPresentation::format($supply['unit_cost'], 4) }}</li>@endforeach</ul></details>
                    @else
                        <div class="grid gap-2 text-xs text-amber-800"><p>Custo não disponível no cadastro: {{ $sale->cost_note }}</p>@if(!$sale->cancelled_at)<button type="button" wire:click="captureCost" wire:loading.attr="disabled" class="btn-secondary">Registrar referência de custo atual</button>@endif</div>
                    @endif
                    @if($sale->notes)<p class="whitespace-pre-wrap text-sm text-stone-600">{{ $sale->notes }}</p>@endif
                </div>
                <div class="panel grid gap-4 p-5"><h3 class="font-semibold">Conciliação · {{ $figures['status'] }}</h3><dl class="grid gap-3 text-sm">
                    @foreach(['expected' => 'Líquido esperado', 'credits' => 'Recebimentos registrados', 'refunds' => 'Estornos registrados', 'received' => 'Total efetivamente recebido', 'difference' => 'Diferença: recebido − esperado', 'pending' => 'Valor ainda pendente'] as $key => $label)
                        <div wire:key="reconciliation-{{ $key }}" class="flex justify-between gap-3"><dt>{{ $label }}</dt><dd>@if($figures[$key] !== null)<x-money :value="$figures[$key]"/>@else<span class="text-xs text-amber-800">Aguardando dados</span>@endif</dd></div>
                    @endforeach
                </dl><p class="text-xs text-stone-500">Previsto para {{ $sale->expected_on?->format('d/m/Y') ?? 'data ainda não informada' }}.</p><p class="text-xs leading-5 text-stone-500">Parcial: recebeu menos e ainda espera novos repasses. Divergente: recebeu a mais, ou concluiu a conferência com diferença. Uma diferença não identifica automaticamente erro do marketplace.</p><p class="text-xs font-medium">{{ $sale->reconciliation_closed ? 'Conferência concluída' : 'Conferência aberta' }}</p>@if($sale->reconciliation_note)<p class="whitespace-pre-wrap text-sm">{{ $sale->reconciliation_note }}</p>@endif
                    <p class="rounded-lg bg-stone-50 p-3 text-xs leading-5 text-stone-500">Recebimentos reais são independentes do Fluxo de Caixa previsto. Nenhuma previsão ou saldo inicial é alterado automaticamente.</p>
                </div>
            </div>
            <section class="panel grid gap-5 p-5" aria-labelledby="receipts-title"><h3 id="receipts-title" class="font-semibold">Histórico de recebimentos e estornos</h3>
                <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="table-head"><tr><th class="p-3">Data efetiva</th><th class="p-3">Referência / tipo</th><th class="p-3">Valor</th><th class="p-3">Observações</th></tr></thead><tbody class="divide-y divide-stone-100">@forelse($sale->receipts as $entry)<tr wire:key="receipt-{{ $entry->id }}"><td class="p-3">{{ $entry->received_on->format('d/m/Y') }}</td><td class="p-3">{{ $entry->reference }}<span class="block text-xs text-stone-500">{{ $entry->kind === 'refund' ? 'Estorno / correção a menor' : 'Recebimento' }}</span></td><td class="p-3"><x-money :value="$entry->amount"/></td><td class="max-w-sm whitespace-pre-wrap break-words p-3">{{ $entry->notes }}</td></tr>@empty<tr><td colspan="4" class="py-6 text-sm text-stone-500">Nenhum recebimento registrado.</td></tr>@endforelse</tbody></table></div>
                <form wire:submit="addReceipt" class="grid gap-4 border-t border-stone-100 pt-5"><p class="text-sm font-medium">Registrar recebimento ou estorno efetivo</p><div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-field label="Tipo" for="receipt.kind"><select id="receipt.kind" wire:model="receipt.kind" class="field"><option value="receipt">Recebimento</option><option value="refund">Estorno / correção a menor</option></select></x-field>
                    <x-field label="Referência do repasse / comprovante *" for="receipt.reference"><input id="receipt.reference" wire:model="receipt.reference" class="field" required maxlength="100"></x-field>
                    <x-field label="Valor (R$) *" for="receipt.amount"><input id="receipt.amount" wire:model="receipt.amount" class="field" inputmode="decimal" required></x-field>
                    <x-field label="Data efetiva *" for="receipt.received_on"><input id="receipt.received_on" wire:model="receipt.received_on" class="field" type="date" required></x-field>
                </div><x-field label="Observações (obrigatórias em estornos)" for="receipt.notes"><textarea id="receipt.notes" wire:model="receipt.notes" rows="2" class="field" maxlength="3000"></textarea></x-field><p class="text-xs text-stone-500">O histórico não é apagado. Corrija um valor a maior registrando um estorno com motivo; para complementar, registre outro recebimento com referência própria.</p><button class="btn-primary justify-self-end" wire:loading.attr="disabled" wire:target="addReceipt">Registrar no histórico</button></form>
            </section>
            <details class="panel p-5"><summary class="cursor-pointer text-sm font-semibold">Histórico de alterações financeiras</summary><div class="mt-4 grid gap-3">@foreach($sale->revisions as $revision)<details wire:key="revision-{{ $revision->id }}" class="rounded border border-stone-100 p-3"><summary class="cursor-pointer text-xs">{{ $revision->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') }} · {{ ['created' => 'Venda registrada', 'updated' => 'Venda editada', 'receipt' => 'Recebimento registrado', 'refund' => 'Estorno registrado', 'cancelled' => 'Venda cancelada', 'cost_captured' => 'Referência de custo registrada'][$revision->event] ?? $revision->event }} · versão {{ $revision->snapshot['version'] }}</summary><dl class="mt-3 grid gap-2 text-xs"><div>Pedido: {{ $revision->snapshot['marketplace'] }} / {{ $revision->snapshot['order_reference'] }} · {{ $revision->snapshot['quantity'] }} un. · {{ $revision->snapshot['product_snapshot']['name'] }}</div>@foreach(['gross_amount' => 'Valor vendido', 'seller_discount' => 'Desconto vendedor', 'marketplace_fees' => 'Tarifas', 'seller_freight' => 'Frete', 'other_adjustment' => 'Ajustes', 'expedition_cost' => 'Expedição', 'paid_labor' => 'Mão de obra'] as $key => $label)<div wire:key="revision-{{ $revision->id }}-{{ $key }}">{{ $label }}: {{ $revision->snapshot[$key] === null ? 'Não informado' : 'R$ '.\App\Services\DecimalPresentation::format($revision->snapshot[$key], 2) }}</div>@endforeach<div>Custo unitário preservado: {{ isset($revision->snapshot['cost_snapshot']['unit_cost']) ? 'R$ '.\App\Services\DecimalPresentation::format($revision->snapshot['cost_snapshot']['unit_cost'], 4) : 'Não disponível' }}</div><div>Observações: {{ $revision->snapshot['notes'] ?? '—' }}</div><div>Conferência: {{ $revision->snapshot['reconciliation_note'] ?? '—' }}</div></dl></details>@endforeach</div></details>
            @if(!$sale->cancelled_at)<form wire:submit="cancelSale" wire:confirm="Cancelar a venda preservando os recebimentos e o histórico?" class="panel grid gap-3 p-5"><h3 class="font-semibold">Cancelar venda</h3><x-field label="Motivo do cancelamento *" for="cancellation_reason"><textarea id="cancellation_reason" wire:model="cancellation_reason" class="field" rows="2" required maxlength="3000"></textarea></x-field><button class="btn-secondary justify-self-end text-red-700" wire:loading.attr="disabled" wire:target="cancelSale">Cancelar venda e preservar histórico</button></form>@endif
        </section>
    @endif
</div>


