<?php

namespace App\Models;

use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['submission_key', 'description', 'direction', 'amount', 'expected_on', 'nature', 'realized_on'])]
class CashMovement extends Model
{
    /** @use HasFactory<CashMovementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expected_on' => 'immutable_date', 'realized_on' => 'immutable_date', 'integration_reviewed_at' => 'immutable_datetime'];
    }

    public function cashProjection(): BelongsTo
    {
        return $this->belongsTo(CashProjection::class);
    }
}
