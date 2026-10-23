<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class TariffPricing
{
    public function __construct(private PriceSimulator $simulator, private TariffCatalog $catalog) {}

    public function simulate(array $data, array $supplyUnitCosts = []): array
    {
        if (($data['tariff_profile_id'] ?? '') === '') {
            return $this->simulator->simulate($data, $supplyUnitCosts);
        }
        $selection = $this->catalog->simulationParameters($data['tariff_profile_id'], $data['channel'] ?? '', $data['sale_price'] ?? null);
        if ($selection['profile']->pricing_model === 'tiered' && $selection['tier'] === null) {
            throw ValidationException::withMessages(['sale_price' => 'Informe um preço com faixa aplicável nesta regra.']);
        }

        return $this->simulator->simulate($this->parameters($data, $selection), $supplyUnitCosts);
    }

    public function findPrice(array $data, array $supplyUnitCosts = []): array
    {
        if (($data['tariff_profile_id'] ?? '') === '') {
            return $this->simulator->findPrice($data, $supplyUnitCosts);
        }
        $selection = $this->catalog->simulationParameters($data['tariff_profile_id'], $data['channel'] ?? '');
        if ($selection['profile']->pricing_model !== 'tiered') {
            return $this->simulator->findPrice($this->parameters($data, $selection), $supplyUnitCosts);
        }

        $best = null;
        foreach ($selection['profile']->tiers ?? [] as $tier) {
            $parameters = $this->parameters($data, [
                'commission_percent' => $tier['commission_percent'],
                'fixed_fee' => $selection['profile']->charge_basis === 'order' ? null : $tier['fixed_fee'],
            ]);
            // Every interval must be known to assert that the global minimum is known.
            try {
                $candidate = $this->simulator->findPrice($parameters, $supplyUnitCosts);
            } catch (ValidationException $exception) {
                if (array_keys($exception->errors()) === ['desired_contribution']) {
                    // An unreachable target in one interval may be reachable in another.
                    continue;
                }
                throw $exception;
            }
            $price = BigDecimal::of($candidate['suggested_price']);
            $minimum = BigDecimal::of($tier['min_price'])->toScale(2, RoundingMode::Ceiling);
            if ($price->isLessThan($minimum)) {
                $price = $minimum;
            }
            if ($tier['max_price'] !== null && $price->isGreaterThan($tier['max_price'])) {
                continue;
            }
            $parameters['sale_price'] = (string) $price;
            $result = $this->simulator->simulate($parameters, $supplyUnitCosts);
            if (BigDecimal::of($result['contribution'])->isGreaterThanOrEqualTo($data['desired_contribution']) && ($best === null || $price->isLessThan($best['suggested_price']))) {
                $best = $result + ['suggested_price' => (string) $price, 'desired_contribution' => $data['desired_contribution']];
            }
        }
        if ($best === null) {
            throw ValidationException::withMessages(['desired_contribution' => 'Nenhuma faixa cadastrada atende à meta. Confira os limites e as tarifas.']);
        }

        return $best;
    }

    private function parameters(array $data, array $selection): array
    {
        foreach (['commission_percent' => 'commission_manual', 'fixed_fee' => 'fixed_manual'] as $field => $flag) {
            // Existing flat-rule callers may explicitly supply manual values without flags.
            if (! ($data[$flag] ?? (($selection['profile']->pricing_model ?? null) === 'single' && array_key_exists($field, $data)))) {
                $data[$field] = $selection[$field] ?? '';
            }
        }

        return $data;
    }
}
