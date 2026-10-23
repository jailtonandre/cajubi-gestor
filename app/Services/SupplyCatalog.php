<?php

namespace App\Services;

use App\Models\Supply;
use App\Models\SupplyCost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SupplyCatalog
{
    /**
     * Quantities in compositions use the supply's unit, without implicit conversion.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Supply
    {
        $data['name'] = is_string($data['name'] ?? null) ? trim($data['name']) : ($data['name'] ?? null);
        $data['description'] = is_string($data['description'] ?? null) ? trim($data['description']) : ($data['description'] ?? null);
        if ($data['description'] === '') {
            $data['description'] = null;
        }
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['required', 'in:kg,g,un,l,ml,m'],
            'unit_cost' => $this->costRules(),
        ])->validate();

        return DB::transaction(function () use ($validated): Supply {
            $supply = Supply::create(['name' => $validated['name'], 'description' => $validated['description'], 'unit' => $validated['unit']]);
            $supply->costs()->create(['unit_cost' => $validated['unit_cost']]);

            return $supply;
        });
    }

    public function updateCost(Supply $supply, mixed $unitCost): SupplyCost
    {
        $validated = Validator::make(['unit_cost' => $unitCost], ['unit_cost' => $this->costRules()])->validate();

        return DB::transaction(function () use ($supply, $validated): SupplyCost {
            $locked = Supply::query()->lockForUpdate()->findOrFail($supply->id);

            return $locked->costs()->create($validated);
        });
    }

    /** @param array{name: mixed, description?: mixed, unit: mixed, unit_cost?: mixed} $data */
    public function update(Supply $supply, array $data): Supply
    {
        return DB::transaction(function () use ($supply, $data): Supply {
            $locked = Supply::query()->lockForUpdate()->findOrFail($supply->id);
            $data['name'] = is_string($data['name'] ?? null) ? trim($data['name']) : ($data['name'] ?? null);
            $data['description'] = is_string($data['description'] ?? null) ? trim($data['description']) : ($data['description'] ?? null);
            if ($data['description'] === '') {
                $data['description'] = null;
            }
            if (($data['unit_cost'] ?? '') === '') {
                $data['unit_cost'] = null;
            }
            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'unit' => ['required', Rule::in([$locked->unit])],
                'unit_cost' => ['nullable', ...array_diff($this->costRules(), ['required'])],
            ], ['unit.in' => 'A unidade de medida não pode ser alterada, pois é a referência das quantidades e dos custos cadastrados.'])->validate();

            $locked->update(['name' => $validated['name'], 'description' => $validated['description']]);
            if (($validated['unit_cost'] ?? null) !== null) {
                $this->updateCost($locked, $validated['unit_cost']);
            }

            return $locked->refresh();
        });
    }

    /** @return list<string> */
    private function costRules(): array
    {
        return ['required', 'string', 'regex:/\A[0-9]{1,10}(\.[0-9]{1,4})?\z/'];
    }
}
