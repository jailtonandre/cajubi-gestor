<?php

namespace Database\Seeders;

use App\Models\TariffProfile;
use App\Services\TariffCatalog;
use Illuminate\Database\Seeder;

class UserProvidedTariffSeeder extends Seeder
{
    public function run(TariffCatalog $catalog): void
    {
        foreach (['classico' => ['Clássico', '14'], 'premium' => ['Premium', '19'], 'gratis' => ['Grátis', '0']] as $key => [$type, $commission]) {
            $referenceKey = 'user_ml_'.$key.'_20261019';
            if (TariffProfile::query()->where('reference_key', $referenceKey)->exists()) {
                continue;
            }
            $catalog->save([
                'reference_key' => $referenceKey, 'channel' => 'mercado_livre',
                'category' => 'Alimentos e Bebidas → Mercearia → Snacks → Frutas Secas',
                'listing_type' => $type, 'commission_percent' => $commission, 'fixed_fee' => null,
                'confidence' => 'estimated', 'conditions_verified' => false, 'is_active' => true,
                'observed_on' => '2026-10-19', 'valid_from' => null, 'valid_until' => null, 'review_on' => null,
                'source' => 'Referência fornecida pelo usuário: consulta realizada em 19/10/2026. Sem verificação automática ou integração oficial.',
                'conditions' => $key === 'gratis' ? 'Comissão de 0% anunciada, sujeita às condições da modalidade Grátis. Elegibilidade e demais regras não confirmadas.' : 'Aplicabilidade à categoria e modalidade informadas pelo usuário. Tarifa fixa e demais condições não confirmadas.',
                'notes' => 'Não presumir tarifa fixa zero, descontos especiais, subsídios de frete ou outras regras não confirmadas.',
            ]);
        }
    }
}
