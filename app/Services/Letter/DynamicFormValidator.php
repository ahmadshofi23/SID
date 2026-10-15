<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Models\LetterType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validasi isian dinamis terhadap `form_schema` — ADR-002.
 *
 * MENGAPA INI WAJIB: MySQL menyimpan kolom JSON apa adanya; ia tidak
 * memeriksa isinya terhadap skema apa pun. Tanpa kelas ini, satu permintaan
 * HTTP yang dirakit sendiri dapat menyisipkan kunci sembarang ke `form_data`,
 * dan nilainya akan tercetak di surat resmi.
 *
 * Bentuk form_schema (docs/13 §2.3):
 *   {
 *     "fields": [
 *       {"name":"nama_usaha","label":"Nama Usaha","type":"text","required":true,"max":150},
 *       {"name":"penghasilan","label":"Penghasilan","type":"number","min":0},
 *       {"name":"jenis","label":"Jenis","type":"select","options":["Dagang","Jasa"]}
 *     ]
 *   }
 */
final class DynamicFormValidator
{
    /** Tipe isian yang dikenal. Di luar daftar ini ditolak, bukan dilewatkan. */
    public const TYPES = [
        'text', 'textarea', 'number', 'currency', 'date', 'time',
        'select', 'multiselect', 'boolean', 'nik', 'resident',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> hanya field yang didefinisikan skema
     *
     * @throws ValidationException
     */
    public function validate(LetterType $type, array $input): array
    {
        $fields = $this->fields($type);

        if ($fields === []) {
            return [];
        }

        $rules = [];
        $labels = [];

        foreach ($fields as $field) {
            $name = (string) $field['name'];
            $rules['form.'.$name] = $this->rulesFor($field);
            $labels['form.'.$name] = (string) ($field['label'] ?? $name);
        }

        $validated = Validator::make(['form' => $input], $rules, [], $labels)->validate();

        /*
         * Hanya field yang dikenal skema yang dikembalikan.
         *
         * Validator Laravel MELEWATKAN kunci tak dikenal apa adanya, jadi
         * memakai hasil validate() tanpa penyaringan ini akan tetap
         * menyimpan masukan sembarang ke form_data.
         */
        $result = [];

        foreach ($fields as $field) {
            $name = (string) $field['name'];
            $value = data_get($validated, 'form.'.$name);

            if ($value !== null) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Field dari skema, sudah dibersihkan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(LetterType $type): array
    {
        $raw = $type->form_schema['fields'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $fields = [];

        foreach ($raw as $field) {
            if (! is_array($field) || ! is_string($field['name'] ?? null) || $field['name'] === '') {
                continue;
            }

            $fieldType = is_string($field['type'] ?? null) ? $field['type'] : 'text';

            $fields[] = [...$field, 'type' => in_array($fieldType, self::TYPES, true) ? $fieldType : 'text'];
        }

        return $fields;
    }

    /** @return array<int, string> nama field, untuk validasi template */
    public function fieldNames(LetterType $type): array
    {
        return array_map(fn (array $f): string => (string) $f['name'], $this->fields($type));
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, string>
     */
    private function rulesFor(array $field): array
    {
        $rules = [($field['required'] ?? false) === true ? 'required' : 'nullable'];

        $rules = [...$rules, ...match ($field['type']) {
            'number', 'currency' => array_filter([
                'numeric',
                isset($field['min']) ? 'min:'.$field['min'] : 'min:0',
                isset($field['max']) ? 'max:'.$field['max'] : null,
            ]),
            'date' => ['date'],
            'time' => ['date_format:H:i'],
            'boolean' => ['boolean'],
            /*
             * `allow_other` (docs/13 §8) membuat pilihan "Lainnya" dapat
             * diisi bebas, sehingga daftar `in:` tidak dapat diterapkan —
             * petugas memang perlu menuliskan keperluan yang tidak terduga.
             * Yang tetap berlaku: panjangnya dibatasi.
             */
            'select' => ($field['allow_other'] ?? false) === true
                ? ['string', 'max:255']
                : ['string', 'in:'.implode(',', array_map('strval', (array) ($field['options'] ?? [])))],
            'multiselect' => ['array'],
            // UUID penduduk, mis. almarhum pada SKM. Kelayakannya diperiksa
            // LetterContextBuilder lewat repository yang berlingkup.
            'resident' => ['uuid'],
            // NIK divalidasi panjangnya saja di sini; keberadaannya di data
            // penduduk diperiksa terpisah agar tidak membocorkan ada/tidaknya
            // seseorang lewat pesan galat formulir.
            'nik' => ['string', 'digits:16'],
            'textarea' => ['string', 'max:'.(int) ($field['max'] ?? 2000)],
            default => ['string', 'max:'.(int) ($field['max'] ?? 255)],
        }];

        return $rules;
    }
}
