<?php

namespace App\Models;

use Database\Factories\CashProjectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'opening_balance', 'starts_on'])]
class CashProjection extends Model
{
    /** @use HasFactory<CashProjectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'starts_on' => 'immutable_date', 'sales_integration_enabled' => 'boolean', 'integration_reviewed_at' => 'immutable_datetime'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
