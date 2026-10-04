<?php

declare(strict_types=1);

namespace App\Http\Requests\Population;

use App\Rules\NikRule;
use App\Support\Pii\Nik;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFamilyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Nomor KK juga 16 digit, aturan formatnya sama dengan NIK.
            'kk_number' => ['required', 'string', new NikRule],
            'region_id' => ['required', 'uuid', 'exists:regions,id'],
            'address' => ['required', 'string', 'max:255'],
            'rt' => ['nullable', 'string', 'max:10'],
            'rw' => ['nullable', 'string', 'max:10'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'welfare_status_code' => ['nullable', Rule::exists('ref_welfare_statuses', 'code')],
            'house_ownership_code' => ['nullable', Rule::exists('ref_house_ownerships', 'code')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'kk_number' => 'nomor kartu keluarga',
            'region_id' => 'wilayah (RT)',
            'address' => 'alamat',
            'welfare_status_code' => 'status kesejahteraan',
            'house_ownership_code' => 'status kepemilikan rumah',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Kolom :attribute wajib diisi.',
            'exists' => 'Pilihan :attribute tidak dikenali.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['kk_number' => Nik::normalize((string) $this->input('kk_number', ''))]);
    }
}
