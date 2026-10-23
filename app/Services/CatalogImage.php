<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Supply;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class CatalogImage
{
    /** @return list<string> */
    public static function rules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];
    }

    /** @param Closure(): (Product|Supply) $save */
    public function save(Product|Supply|null $existing, ?UploadedFile $image, bool $remove, Closure $save): Product|Supply
    {
        $newPath = null;

        try {
            return DB::transaction(function () use ($existing, $image, $remove, $save, &$newPath): Product|Supply {
                $previousPath = $existing ? $existing->newQuery()->lockForUpdate()->findOrFail($existing->getKey())->image_path : null;
                $record = $save();
                $directory = $record instanceof Product ? 'products' : 'supplies';

                if ($image) {
                    $newPath = $image->store($directory, 'public');
                    if (! $newPath) {
                        throw new RuntimeException('Não foi possível armazenar a imagem. Tente novamente.');
                    }
                }

                if ($image || $remove) {
                    $record->image_path = $newPath;
                    if (! $record->saveOrFail()) {
                        throw new RuntimeException('Não foi possível salvar a imagem.');
                    }
                    DB::afterCommit(fn () => $this->deleteUnused($previousPath, $directory));
                }

                return $record;
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                $this->deleteUnused($newPath, explode('/', $newPath)[0]);
            }
            throw $exception;
        }
    }

    public function deleteUnused(?string $path, string $directory): void
    {
        if (! in_array($directory, ['products', 'supplies'], true) || ! $path || ! preg_match('~\A'.$directory.'/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)\z~D', $path)) {
            return;
        }

        try {
            if (Product::query()->where('image_path', $path)->exists() || Supply::query()->where('image_path', $path)->exists()) {
                return;
            }
            if (! Storage::disk('public')->delete($path)) {
                report(new RuntimeException('Não foi possível remover a imagem: '.$path));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
