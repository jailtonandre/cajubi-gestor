<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ScenarioComparison
{
    public function __construct(private TariffPricing $simulator, private TariffCatalog $tariffs) {}

    /**
     * All amounts are decimal strings. Quantities are user hypotheses, not forecasts.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function evaluate(array $data): array
    {
        Validator::make($data, [
            'quantity' => ['required', 'string', 'regex:/\A[0-9]{1,9}\z/'],
            'cost_mode' => ['required', Rule::in(['current', 'alternative'])],
            'cost_overrides' => ['sometimes', 'array'],
        ], [
            'quantity.required' => 'Informe a quantidade de vendas, ou zero explícito.',
            'quantity.regex' => 'A quantidade deve ser um inteiro não negativo, com até 9 dígitos.',
        ])->validate();

        $warnings = [];
        $profile = null;
        if (($data['tariff_profile_id'] ?? '') !== '') {
            $selection = $this->tariffs->simulationParameters($data['tariff_profile_id'], $data['channel'] ?? '', $data['sale_price'] ?? null);
            $profile = $selection['profile'];
            $warnings = $selection['warnings'];
            if (isset($data['tariff_revision_id']) && $data['tariff_revision_id'] !== $selection['revision_id']) {
                $warnings[] = 'A regra foi alterada desde a seleção. Reaplique os valores se desejar atualizá-los.';
            }
        }
        $overrides = [];
        if ($data['cost_mode'] === 'alternative') {
            $overrides = $data['cost_overrides'] ?? [];
            Validator::make(['cost_overrides' => $overrides], ['cost_overrides' => ['required', 'array', 'min:1']], [
                'cost_overrides.required' => 'Informe pelo menos um custo alternativo de insumo.',
                'cost_overrides.min' => 'Informe pelo menos um custo alternativo de insumo.',
            ])->validate();
            $warnings[] = 'Sensibilidade: custos alternativos hipotéticos, sem alteração dos cadastros.';
        }

        $result = $this->simulator->simulate($data, $overrides);
        $quantity = BigDecimal::of($data['quantity']);
        $fees = BigDecimal::of($result['commission_amount'])->plus($result['fixed_fee']);
        $variableExpenses = BigDecimal::of($result['seller_shipping'])->plus($result['other_variable_expenses']);

        return $result + [
            'quantity' => (string) $quantity,
            'gross_revenue' => (string) BigDecimal::of($result['sale_price'])->multipliedBy($quantity),
            'marketplace_fees_total' => (string) $fees->multipliedBy($quantity),
            'variable_expenses_total' => (string) $variableExpenses->multipliedBy($quantity),
            'fees_and_expenses_total' => (string) $fees->plus($variableExpenses)->multipliedBy($quantity),
            'payout_total' => (string) BigDecimal::of($result['marketplace_payout'])->multipliedBy($quantity),
            'product_cost_total' => (string) BigDecimal::of($result['product_cost'])->multipliedBy($quantity),
            'contribution_total' => (string) BigDecimal::of($result['contribution'])->multipliedBy($quantity),
            'warnings' => $warnings, 'profile' => $profile,
        ];
    }

    /**
     * Find the minimum integer volume needed to match a positive reference total.
     *
     * @return array<string, string|null>
     */
    public function matchVolume(array $candidate, array $reference): array
    {
        $target = BigDecimal::of($reference['contribution_total']);
        $unit = BigDecimal::of($candidate['contribution']);
        if ($target->isLessThanOrEqualTo('0')) {
            return ['required_units' => null, 'additional_units' => null, 'extra_vs_reference_units' => null, 'extra_percent' => null,
                'message' => 'Referência sem contribuição total positiva. A compensação por aumento de volume não se aplica.'];
        }
        if ($unit->isLessThanOrEqualTo('0')) {
            return ['required_units' => null, 'additional_units' => null, 'extra_vs_reference_units' => null, 'extra_percent' => null,
                'message' => 'Não há volume que compense a referência positiva com contribuição unitária nula ou negativa.'];
        }

        $required = $target->dividedBy($unit, 0, RoundingMode::Ceiling);
        $additional = $required->minus($candidate['quantity']);
        $extra = $required->minus($reference['quantity']);
        if ($additional->isNegative()) {
            $additional = BigDecimal::of('0');
        }
        if ($extra->isNegative()) {
            $extra = BigDecimal::of('0');
        }
        $percent = $extra->multipliedBy('100')->dividedBy($reference['quantity'], 4, RoundingMode::HalfUp);

        return ['required_units' => (string) $required, 'additional_units' => (string) $additional,
            'extra_vs_reference_units' => (string) $extra, 'extra_percent' => (string) $percent, 'message' => null];
    }
}
