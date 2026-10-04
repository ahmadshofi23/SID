<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['identifier' => 'email atau nomor ponsel'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'identifier.required' => 'Masukkan email atau nomor ponsel Anda.',
            'password.required' => 'Masukkan kata sandi Anda.',
        ];
    }

    /** Apakah identifier berupa email (menentukan kolom pencarian). */
    public function identifierIsEmail(): bool
    {
        return str_contains((string) $this->input('identifier'), '@');
    }

    /** Normalisasi nomor ponsel: 08xx / +628xx / 628xx -> 628xx */
    public function normalizedIdentifier(): string
    {
        $value = trim((string) $this->input('identifier'));

        if ($this->identifierIsEmail()) {
            return mb_strtolower($value);
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }
}
