<?php

namespace App\Services;

use App\Models\TariffProfile;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TariffCatalog
{
    /** @param array<string, mixed> $data */
    public function save(array $data, ?TariffProfile $profile = null): TariffProfile
    {
        $data += ['pricing_model' => $profile?->pricing_model ?? 'single', 'charge_basis' => $profile?->charge_basis ?? 'item', 'tiers' => $profile?->tiers];
        if ($profile && ($data['channel'] ?? null) !== $profile->channel) {
            throw ValidationException::withMessages(['channel' => 'O marketplace desta regra não pode ser alterado. Crie outra regra para o novo canal.']);
        }
        foreach (['category', 'listing_type', 'source'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }
        $validator = Validator::make($data, [
            'reference_key' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('tariff_profiles')->ignore($profile)],
            'channel' => ['required', Rule::in(['mercado_livre', 'shopee'])],
            'pricing_model' => ['required', Rule::in(['single', 'tiered'])],
            'charge_basis' => ['required', Rule::in(['item', 'order'])],
            'tiers' => ['nullable', 'array', 'list', 'max:100', 'required_if:pricing_model,tiered', 'min:1'],
            'tiers.*' => ['array:min_price,max_price,commission_percent,fixed_fee'],
            'tiers.*.min_price' => ['required', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,2})?\z/'],
            'tiers.*.max_price' => ['nullable', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,2})?\z/'],
            'tiers.*.commission_percent' => ['nullable', 'string', 'regex:/\A[0-9]{1,3}(\.[0-9]{1,4})?\z/'],
            'tiers.*.fixed_fee' => ['nullable', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,4})?\z/'],
            'category' => ['required', 'string', 'max:255'],
            'listing_type' => ['required', 'string', 'max:100'],
            'commission_percent' => ['nullable', 'string', 'regex:/\A[0-9]{1,3}(\.[0-9]{1,4})?\z/'],
            'fixed_fee' => ['nullable', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,4})?\z/'],
            'conditions' => ['nullable', 'string'],
            'conditions_verified' => ['required', 'boolean'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'review_on' => ['nullable', 'date_format:Y-m-d'],
            'observed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'source' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string'],
            'confidence' => ['required', Rule::in(['confirmed', 'estimated'])],
            'is_active' => ['required', 'boolean'],
        ], ['observed_on.before_or_equal' => 'A data da consulta não pode estar no futuro.']);
        $validator->after(function ($validator) use ($data): void {
            if (! $validator->errors()->has('commission_percent') && ($data['commission_percent'] ?? null) !== null && $data['commission_percent'] !== '' && BigDecimal::of($data['commission_percent'])->isGreaterThan('100')) {
                $validator->errors()->add('commission_percent', 'A comissão deve estar entre 0% e 100%.');
            }
            if (! $validator->errors()->hasAny(['valid_from', 'valid_until']) && ! empty($data['valid_from']) && ! empty($data['valid_until']) && $data['valid_until'] < $data['valid_from']) {
                $validator->errors()->add('valid_until', 'O fim da vigência deve ser igual ou posterior ao início.');
            }
        });
        $validated = $validator->validate();
        $validated['tiers'] = $validated['pricing_model'] === 'tiered' ? $this->validateTiers($validated['tiers']) : null;
        if ($validated['pricing_model'] === 'tiered') {
            $validated['commission_percent'] = null;
            $validated['fixed_fee'] = null;
        }
        foreach (['commission_percent', 'fixed_fee', 'conditions', 'valid_from', 'valid_until', 'review_on', 'observed_on', 'notes'] as $field) {
            $validated[$field] = ($validated[$field] ?? '') === '' ? null : $validated[$field];
        }

        return DB::transaction(function () use ($validated, $profile): TariffProfile {
            $record = $profile ? TariffProfile::query()->lockForUpdate()->findOrFail($profile->id) : new TariffProfile;
            $record->fill($validated);
            if (! $record->exists || $record->isDirty()) {
                $record->save();
                $record->refresh();
                $record->revisions()->create(['snapshot' => $record->only($record->getFillable())]);
            }

            return $record;
        });
    }

    public function setActive(TariffProfile $profile, bool $active): TariffProfile
    {
        return DB::transaction(function () use ($profile, $active): TariffProfile {
            $record = TariffProfile::query()->lockForUpdate()->findOrFail($profile->id);
            if ($record->is_active !== $active) {
                $record->update(['is_active' => $active]);
                $record->revisions()->create(['snapshot' => $record->only($record->getFillable())]);
            }

            return $record;
        });
    }

    /** @return list<string> */
    public function warnings(TariffProfile $profile): array
    {
        $today = now('America/Sao_Paulo')->format('Y-m-d');
        $warnings = [];
        if (! $profile->is_active) {
            $warnings[] = 'Regra desativada.';
        }
        if ($profile->confidence === 'estimated') {
            $warnings[] = 'Regra estimada: confirme as tarifas antes de usar.';
        }
        if ($profile->pricing_model !== 'tiered' && $profile->commission_percent === null) {
            $warnings[] = 'Comissão desconhecida. Informe o valor na simulação.';
        }
        if ($profile->pricing_model !== 'tiered' && $profile->fixed_fee === null) {
            $warnings[] = 'Tarifa fixa desconhecida. Informe o valor ou zero explícito se não se aplicar.';
        }
        if ($profile->charge_basis === 'order') {
            $warnings[] = 'Cobrança por pedido: informe manualmente o rateio da tarifa fixa por unidade na simulação.';
        }
        if (! $profile->conditions_verified) {
            $warnings[] = 'Condições não verificadas. Confira a aplicabilidade à sua venda.';
        }
        if (! $profile->valid_from) {
            $warnings[] = 'Início da vigência não informado.';
        } elseif ($profile->valid_from->format('Y-m-d') > $today) {
            $warnings[] = 'A vigência desta regra ainda não começou.';
        }
        if ($profile->valid_until && $profile->valid_until->format('Y-m-d') < $today) {
            $warnings[] = 'Regra desatualizada: vigência encerrada.';
        }
        if (! $profile->review_on) {
            $warnings[] = 'Sem data de revisão definida; confirme se a referência continua atual.';
        } elseif ($profile->review_on->format('Y-m-d') < $today) {
            $warnings[] = 'Regra desatualizada: revisão pendente.';
        }

        return $warnings;
    }

    /** @return array{profile: TariffProfile, commission_percent: ?string, fixed_fee: ?string, tier: ?array, revision_id: ?int, warnings: list<string>} */
    public function simulationParameters(mixed $id, string $channel, ?string $price = null): array
    {
        Validator::make(['tariff_profile_id' => $id], ['tariff_profile_id' => ['required', 'integer', 'exists:tariff_profiles,id']])->validate();
        $profile = TariffProfile::query()->findOrFail($id);
        if (! $profile->is_active || $profile->channel !== $channel) {
            throw ValidationException::withMessages(['tariff_profile_id' => 'Selecione uma regra ativa do canal informado.']);
        }

        $commission = $profile->commission_percent;
        $fee = $profile->fixed_fee;
        $tier = null;
        $warnings = $this->warnings($profile);
        if ($profile->pricing_model === 'tiered') {
            $commission = $fee = null;
            if ($price !== null && preg_match('/\A[0-9]{1,10}(\.[0-9]{1,4})?\z/', $price)) {
                foreach ($profile->tiers ?? [] as $candidate) {
                    $amount = BigDecimal::of($price);
                    if ($amount->isGreaterThanOrEqualTo($candidate['min_price']) && ($candidate['max_price'] === null || $amount->isLessThanOrEqualTo($candidate['max_price']))) {
                        $tier = $candidate;
                        $commission = $tier['commission_percent'];
                        $fee = $tier['fixed_fee'];
                        break;
                    }
                }
                if ($tier === null) {
                    $warnings[] = 'Não existe faixa aplicável a este preço.';
                } elseif ($commission === null || $fee === null) {
                    $warnings[] = 'A faixa aplicável contém tarifas desconhecidas.';
                }
            } else {
                $warnings[] = 'As tarifas serão selecionadas conforme o preço do item.';
            }
        }

        return ['profile' => $profile, 'commission_percent' => $commission,
            'fixed_fee' => $profile->charge_basis === 'order' ? null : $fee,
            'tier' => $tier, 'revision_id' => $profile->revisions()->max('id'), 'warnings' => $warnings];
    }

    /** @param list<array<string, mixed>> $tiers */
    private function validateTiers(array $tiers): array
    {
        $previous = null;
        foreach ($tiers as $index => &$tier) {
            foreach (['max_price', 'commission_percent', 'fixed_fee'] as $field) {
                $tier[$field] = ($tier[$field] ?? '') === '' ? null : $tier[$field];
            }
            $min = BigDecimal::of($tier['min_price']);
            if ($tier['max_price'] !== null && BigDecimal::of($tier['max_price'])->isLessThan($min)) {
                throw ValidationException::withMessages(["tiers.$index.max_price" => 'O limite final deve ser igual ou maior que o inicial.']);
            }
            if ($index > 0 && ($previous === null || ! $min->isEqualTo(BigDecimal::of($previous)->plus('0.01')))) {
                throw ValidationException::withMessages(["tiers.$index.min_price" => 'Ordene as faixas sem sobreposição ou lacunas: a próxima começa um centavo após a anterior.']);
            }
            if ($tier['commission_percent'] !== null && BigDecimal::of($tier['commission_percent'])->isGreaterThan(100)) {
                throw ValidationException::withMessages(["tiers.$index.commission_percent" => 'A comissão deve estar entre 0% e 100%.']);
            }
            $previous = $tier['max_price'];
        }
        unset($tier);

        return array_values($tiers);
    }
}
