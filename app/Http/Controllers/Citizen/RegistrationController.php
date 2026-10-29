<?php

declare(strict_types=1);

namespace App\Http\Controllers\Citizen;

use App\Http\Controllers\Controller;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use App\Rules\StrongPassword;
use App\Services\Citizen\CitizenRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pendaftaran akun warga — FR-AUT-08, FR-AUT-09, FR-AUT-10, UC-15, BR-10.
 *
 * Prasyarat pengajuan surat daring (KEP-013 §2): tanpa akun yang tertaut ke
 * satu penduduk aktif dan diperiksa petugas, pengajuan daring tidak punya
 * pemohon yang dapat dipercaya.
 *
 * Tanpa konstruktor (KEP-009) — ResidentRepository dan FamilyRepository
 * membawa AccessScope.
 */
final class RegistrationController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Daftar');
    }

    public function store(
        Request $request,
        CitizenRegistrationService $registration,
        ResidentRepository $residents,
        FamilyRepository $families,
    ): RedirectResponse {
        $data = $request->validate([
            'nik' => ['required', 'string', 'size:16'],
            'kk_number' => ['required', 'string', 'size:16'],
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', new StrongPassword],
            'verification_method' => ['required', Rule::in(['ktp_upload', 'tatap_muka'])],
            'ktp' => ['nullable', 'file'],
            'setuju' => ['accepted'],
        ], attributes: [
            'nik' => 'NIK',
            'kk_number' => 'nomor Kartu Keluarga',
            'full_name' => 'nama lengkap',
            'phone' => 'nomor ponsel',
            'verification_method' => 'cara verifikasi',
            'ktp' => 'foto KTP',
            'setuju' => 'persetujuan',
        ]);

        /*
         * Harus ada SATU kontak. Kolom `chk_users_contact` di basis data
         * menegakkan hal yang sama, tetapi kegagalan di sana berupa galat SQL
         * yang tidak dapat dibaca warga.
         *
         * Validasi ini AMAN diberitahukan apa adanya: ia tentang data yang
         * diketik pemohon sendiri, bukan tentang keberadaan warga lain.
         */
        if (($data['email'] ?? null) === null && ($data['phone'] ?? null) === null) {
            throw ValidationException::withMessages([
                'email' => 'Isi email atau nomor ponsel — salah satunya dipakai untuk masuk nanti.',
            ]);
        }

        if ($data['verification_method'] === 'ktp_upload' && ! $request->hasFile('ktp')) {
            throw ValidationException::withMessages([
                'ktp' => 'Unggah foto KTP, atau pilih verifikasi tatap muka di kantor desa.',
            ]);
        }

        if ($request->hasFile('ktp')) {
            $alasan = $registration->ktpRejectionReason($request->file('ktp'));

            if ($alasan !== null) {
                throw ValidationException::withMessages(['ktp' => $alasan]);
            }
        }

        $registration->register($data, $residents, $families, $request->file('ktp'));

        /*
         * SELALU halaman yang sama, apa pun hasilnya di dalam (FR-AUT-10,
         * KEP-017). Pengalihan ke halaman terpisah, bukan `back()`, supaya
         * data yang diketik tidak tertinggal di riwayat formulir pada
         * perangkat yang mungkin dipakai bersama.
         */
        return to_route('daftar.terkirim');
    }

    public function sent(): Response
    {
        return Inertia::render('Auth/DaftarTerkirim');
    }
}
