<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['reference_key', 'channel', 'category', 'listing_type', 'commission_percent', 'fixed_fee', 'pricing_model', 'charge_basis', 'tiers', 'conditions', 'conditions_verified', 'valid_from', 'valid_until', 'review_on', 'observed_on', 'source', 'notes', 'confidence', 'is_active'])]
class TariffProfile extends Model
{
    protected function casts(): array
    {
        return [
            'tiers' => 'array',
            'commission_percent' => 'decimal:4', 'fixed_fee' => 'decimal:4',
            'conditions_verified' => 'boolean', 'is_active' => 'boolean',
            'valid_from' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d',
            'review_on' => 'date:Y-m-d', 'observed_on' => 'date:Y-m-d',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TariffRevision::class);
    }
}
