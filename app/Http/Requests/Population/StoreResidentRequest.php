<?php

declare(strict_types=1);

namespace App\Http\Requests\Population;

use App\Rules\BirthDateRule;
use App\Rules\NikRule;
use App\Support\Pii\Nik;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi penduduk — FR-PDK-01, FR-PDK-02, FR-PDK-03.
 *
 * Seluruh kolom berkode divalidasi terhadap TABEL REFERENSI, bukan daftar
 * tertulis di kode. Dengan begitu menambah satu jenis pekerjaan cukup lewat
 * data, tanpa rilis baru (FR-PDK-03, NFR-MNT-05).
 */
class StoreResidentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nik' => ['required', 'string', new NikRule],
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['required', 'date', new BirthDateRule],
            'gender' => ['required', Rule::in(['L', 'P'])],

            'family_id' => ['nullable', 'uuid', 'exists:families,id'],
            'family_relation_code' => [
                'required', Rule::exists('ref_family_relations', 'code')->where('is_active', true),
            ],
            'region_id' => ['nullable', 'uuid', 'exists:regions,id'],

            'religion_code' => ['nullable', Rule::exists('ref_religions', 'code')],
            'marital_status_code' => ['nullable', Rule::exists('ref_marital_statuses', 'code')],
            'education_code' => ['nullable', Rule::exists('ref_educations', 'code')],
            'occupation_code' => ['nullable', Rule::exists('ref_occupations', 'code')],
            'blood_type_code' => ['nullable', Rule::exists('ref_blood_types', 'code')],
            'disability_code' => ['nullable', Rule::exists('ref_disabilities', 'code')],

            'citizenship' => ['nullable', Rule::in(['WNI', 'WNA'])],
            'father_name' => ['nullable', 'string', 'max:150'],
            'mother_name' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'domicile_address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'is_poor' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'nik' => 'NIK',
            'full_name' => 'nama lengkap',
            'birth_place' => 'tempat lahir',
            'birth_date' => 'tanggal lahir',
            'gender' => 'jenis kelamin',
            'family_id' => 'kartu keluarga',
            'family_relation_code' => 'hubungan dalam keluarga',
            'region_id' => 'wilayah',
            'religion_code' => 'agama',
            'marital_status_code' => 'status perkawinan',
            'education_code' => 'pendidikan',
            'occupation_code' => 'pekerjaan',
            'phone' => 'nomor telepon',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Kolom :attribute wajib diisi.',
            'exists' => 'Pilihan :attribute tidak dikenali. Pilih dari daftar yang tersedia.',
            'phone.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, dan tanda + ( ) -.',
            'email.email' => 'Format email belum benar. Contoh: nama@contoh.id',
            'full_name.min' => 'Nama lengkap terlalu pendek.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nik' => Nik::normalize((string) $this->input('nik', '')),
            'full_name' => trim((string) $this->input('full_name', '')),
        ]);
    }

    /**
     * Peringatan (bukan galat) atas isi NIK yang tidak lazim — FR-PDK-02.
     *
     * Ditampilkan setelah penyimpanan berhasil, agar petugas memeriksa ulang
     * tanpa terhalang menyimpan data warga yang nyata ada.
     *
     * @return list<string>
     */
    public function nikWarnings(): array
    {
        return Nik::warnings(
            (string) $this->input('nik'),
            $this->input('birth_date') === null ? null : (string) $this->input('birth_date'),
            $this->input('gender') === null ? null : (string) $this->input('gender'),
        );
    }

    /** @return array<string, mixed> */
    public function residentData(): array
    {
        $data = $this->validated();
        $data['citizenship'] ??= 'WNI';
        $data['is_poor'] = $this->boolean('is_poor');

        return $data;
    }
}
