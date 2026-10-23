<?php

namespace App\Models;

use App\Services\CatalogImage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'sku', 'net_weight_grams', 'description', 'status'])]
class Product extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleted(function (Product $product): void {
            $path = $product->image_path;
            DB::afterCommit(fn () => app(CatalogImage::class)->deleteUnused($path, 'products'));
        });
    }

    protected function casts(): array
    {
        return ['net_weight_grams' => 'decimal:3'];
    }

    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class);
    }
}
