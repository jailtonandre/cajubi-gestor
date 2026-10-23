<?php

namespace App\Models;

use App\Services\CatalogImage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'description', 'unit'])]
class Supply extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleted(function (Supply $supply): void {
            $path = $supply->image_path;
            DB::afterCommit(fn () => app(CatalogImage::class)->deleteUnused($path, 'supplies'));
        });
    }

    public function costs(): HasMany
    {
        return $this->hasMany(SupplyCost::class);
    }

    public function currentCost(): HasOne
    {
        return $this->hasOne(SupplyCost::class)->latestOfMany();
    }
}
