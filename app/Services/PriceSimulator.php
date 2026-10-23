<?php

namespace App\Services;

use App\Models\Product;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PriceSimulator
{
    public function __construct(private ProductCostCalculator $costCalculator) {}

    /**
     * Marketplace payout excludes seller-paid shipping and other variable expenses.
     *
     * @param  array<string, mixed>  $data  Decimal values use a dot and are strings.
     * @return array<string, string>
     */
    public function simulate(array $data, array $supplyUnitCosts = []): array
    {
        $validated = $this->validateInput($data);

        return $this->calculate($validated, $this->productCost($validated, $supplyUnitCosts));
    }

    /**
     * Find the smallest positive cent price that meets the contribution target.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function findPrice(array $data, array $supplyUnitCosts = []): array
    {
        $validated = $this->validateInput($data, inverse: true);
        $cost = $this->productCost($validated, $supplyUnitCosts);
        $target = BigDecimal::of($validated['desired_contribution']);
        $expenses = $cost->plus($validated['fixed_fee'])
            ->plus($validated['seller_shipping'])
            ->plus($validated['other_variable_expenses']);
        $retainedShare = BigDecimal::of('1')->minus(BigDecimal::of($validated['commission_percent'])->withPointMovedLeft(2));
        $price = $expenses->plus($target)->dividedBy($retainedShare, 2, RoundingMode::Ceiling);
        if ($price->isZero()) {
            $price = BigDecimal::of('0.01');
        }
        if ($price->isGreaterThan('9999999999.99')) {
            throw ValidationException::withMessages(['desired_contribution' => 'Esta meta exige um preço acima do limite suportado de R$ 9.999.999.999,99. Revise a meta ou os parâmetros.']);
        }

        $validated['sale_price'] = (string) $price;
        $result = $this->calculate($validated, $cost);
        if (BigDecimal::of($result['contribution'])->isLessThan($target)) {
            throw ValidationException::withMessages(['desired_contribution' => 'Não foi possível atingir a meta com o preço calculado. Revise os parâmetros.']);
        }

        return $result + ['suggested_price' => (string) $price, 'desired_contribution' => (string) $target];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateInput(array $data, bool $inverse = false): array
    {
        $amountRules = ['required', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,4})?\z/'];
        $valueField = $inverse ? 'desired_contribution' : 'sale_price';
        $validator = Validator::make($data, [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'channel' => ['required', Rule::in(['mercado_livre', 'shopee'])],
            $valueField => $amountRules,
            'commission_percent' => ['required', 'string', 'regex:/\A[0-9]{1,3}(\.[0-9]{1,4})?\z/'],
            'fixed_fee' => $amountRules,
            'seller_shipping' => $amountRules,
            'other_variable_expenses' => $amountRules,
        ], [
            'required' => 'Informe :attribute. Use zero quando não houver despesa.',
            'sale_price.required' => 'Informe o preço de venda.',
            'desired_contribution.required' => 'Informe a contribuição desejada por unidade.',
            'product_id.required' => 'Selecione um produto.',
            'channel.required' => 'Selecione o canal.',
            'regex' => 'Informe :attribute com valor não negativo e até 4 casas decimais.',
        ], [
            'product_id' => 'produto',
            'channel' => 'canal',
            'sale_price' => 'preço de venda',
            'desired_contribution' => 'contribuição desejada',
            'commission_percent' => 'comissão percentual',
            'fixed_fee' => 'tarifa fixa',
            'seller_shipping' => 'frete do vendedor',
            'other_variable_expenses' => 'outras despesas variáveis',
        ]);
        $validator->after(function ($validator) use ($data, $inverse): void {
            if (! $inverse && ! $validator->errors()->has('sale_price') && BigDecimal::of($data['sale_price'])->isZero()) {
                $validator->errors()->add('sale_price', 'O preço de venda deve ser maior que zero.');
            }
            if (! $validator->errors()->has('commission_percent')) {
                $commission = BigDecimal::of($data['commission_percent']);
                if ($inverse && $commission->isGreaterThanOrEqualTo('100')) {
                    $validator->errors()->add('commission_percent', 'Para encontrar o preço, a comissão deve ser menor que 100%.');
                } elseif ($commission->isGreaterThan('100')) {
                    $validator->errors()->add('commission_percent', 'A comissão deve estar entre 0% e 100%.');
                }
            }
        });

        return $validator->validate();
    }

    /** @param array<string, mixed> $validated */
    private function productCost(array $validated, array $supplyUnitCosts = []): BigDecimal
    {
        $product = Product::query()->findOrFail($validated['product_id']);
        try {
            return BigDecimal::of($this->costCalculator->calculate($product, $supplyUnitCosts));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['product_id' => $exception->validator->errors()->first().' Revise o cadastro do produto e seus insumos.']);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private function calculate(array $validated, BigDecimal $cost): array
    {
        $price = BigDecimal::of($validated['sale_price']);
        $commission = $price->multipliedBy($validated['commission_percent'])->withPointMovedLeft(2);
        $payout = $price->minus($commission)->minus($validated['fixed_fee']);
        $contribution = $payout->minus($cost)->minus($validated['seller_shipping'])->minus($validated['other_variable_expenses']);
        $percentage = $contribution->multipliedBy('100')->dividedBy($price, 4, RoundingMode::HalfUp);

        return [
            'channel' => $validated['channel'],
            'sale_price' => (string) $price,
            'commission_percent' => $validated['commission_percent'],
            'commission_amount' => (string) $commission,
            'fixed_fee' => $validated['fixed_fee'],
            'marketplace_payout' => (string) $payout,
            'product_cost' => (string) $cost,
            'seller_shipping' => $validated['seller_shipping'],
            'other_variable_expenses' => $validated['other_variable_expenses'],
            'contribution' => (string) $contribution,
            'contribution_percent' => (string) $percentage,
            'tone' => $contribution->isZero() ? 'zero' : ($contribution->isNegative() ? 'negative' : 'positive'),
        ];
    }
}
