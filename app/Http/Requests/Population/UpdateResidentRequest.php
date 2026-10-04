<?php

declare(strict_types=1);

namespace App\Http\Requests\Population;

use App\Rules\NikRule;

/**
 * Perbedaan dari penambahan: NIK bersifat OPSIONAL.
 *
 * Alasannya adalah kompromi antara keamanan dan kebergunaan:
 *   - Mem-prefill NIK asli ke dalam formulir akan membocorkannya ke HTML
 *     halaman bagi siapa pun yang boleh mengubah data — melewati RULE-07.
 *   - Mewajibkan petugas mengetik ulang 16 angka setiap kali memperbaiki
 *     salah ketik nama adalah undangan untuk membuat kesalahan baru.
 *
 * Maka: kolom dibiarkan kosong, dan NIK hanya diperbarui bila benar-benar diisi.
 */
final class UpdateResidentRequest extends StoreResidentRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['nik'] = ['nullable', 'string', new NikRule];

        return $rules;
    }

    /** @return array<string, mixed> */
    public function residentData(): array
    {
        $data = parent::residentData();

        // Kosong berarti "jangan ubah NIK", bukan "hapus NIK".
        if (($data['nik'] ?? '') === '') {
            unset($data['nik']);
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->input('nik') === '') {
            $this->merge(['nik' => null]);
        }
    }
}
