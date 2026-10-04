<?php

declare(strict_types=1);

namespace App\Http\Requests\Population;

use App\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'level' => ['required', Rule::in(Region::LEVELS)],
            'parent_id' => ['nullable', 'uuid', 'exists:regions,id'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'head_name' => ['nullable', 'string', 'max:150'],
            'head_phone' => ['nullable', 'string', 'max:30'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'level' => 'tingkat wilayah',
            'parent_id' => 'wilayah induk',
            'code' => 'kode',
            'name' => 'nama wilayah',
            'head_name' => 'nama kepala wilayah',
            'head_phone' => 'nomor telepon kepala wilayah',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Kolom :attribute wajib diisi.',
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, titik, dan tanda hubung.',
        ];
    }
}
