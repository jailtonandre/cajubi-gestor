<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['snapshot'])]
class TariffRevision extends Model
{
    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function tariffProfile(): BelongsTo
    {
        return $this->belongsTo(TariffProfile::class);
    }
}
