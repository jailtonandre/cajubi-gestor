<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['marketplace', 'order_reference', 'sold_on', 'product_id', 'quantity', 'gross_amount', 'seller_discount', 'marketplace_fees', 'seller_freight', 'freight_source', 'other_adjustment', 'expedition_cost', 'paid_labor', 'expected_on', 'notes', 'reconciliation_closed', 'reconciliation_note'])]
class Sale extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['sold_on' => 'immutable_date', 'expected_on' => 'immutable_date', 'cost_captured_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime', 'cost_snapshot' => 'array', 'product_snapshot' => 'array',
            'quantity' => 'integer', 'version' => 'integer', 'reconciliation_closed' => 'boolean',
            'gross_amount' => 'decimal:2', 'seller_discount' => 'decimal:2', 'marketplace_fees' => 'decimal:2',
            'seller_freight' => 'decimal:2', 'other_adjustment' => 'decimal:2', 'expedition_cost' => 'decimal:2', 'paid_labor' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(SaleReceipt::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SaleRevision::class);
    }
}
