<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterRequestLog;
use App\Models\Role;
use App\Support\VillageTime;

/**
 * Penerjemah pengajuan surat untuk lapis tampilan.
 *
 * Alasan keberadaannya sama dengan ResidentPresenter: NIK LENGKAP TIDAK BOLEH
 * LEWAT. Penyusunan payload secara ad-hoc di setiap controller cepat sekali
 * berbeda-beda, dan satu `$resident->toArray()` yang lolos akan mengirim
 * `nik_enc` ke peramban.
 */
final class LetterRequestPresenter
{
    /** @return array<string, mixed> */
    public static function row(LetterRequest $request): array
    {
        return [
            'id' => $request->id,
            'ticket_number' => $request->ticket_number,
            'status' => $request->status,
            'status_label' => LetterRequest::statusLabels()[$request->status] ?? $request->status,
            'channel' => $request->channel,
            'current_step' => $request->current_step,
            'step_total' => $request->letterType->verificationStepCount(),
            'letter_type' => [
                'id' => $request->letterType->id,
                'code' => $request->letterType->code,
                'name' => $request->letterType->name,
            ],
            'resident' => [
                'id' => $request->resident->id,
                'full_name' => $request->resident->full_name,
                'nik_masked' => $request->resident->nik_masked,
            ],
            'purpose' => $request->purpose,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'due_at' => $request->due_at?->toIso8601String(),
            'is_overdue' => $request->isOverdue(),
            /*
             * Label batas waktu disusun DI SERVER, bukan di peramban.
             *
             * Sisa waktu harus dihitung pada kalender desa. Peramban
             * petugas memakai zona waktu perangkatnya sendiri, dan selisih
             * tujuh jam cukup membuat "besok malam" terbaca "sisa 2 hari".
             *
             * Dihitung dalam HARI KALENDER, bukan pembulatan selisih jam:
             * batas waktu besok pukul 23.59 adalah "besok", apa pun jam
             * berapa petugas membukanya.
             */
            'due_label' => self::dueLabel($request),
            'created_at' => $request->created_at->toIso8601String(),
        ];
    }

    /**
     * Nama peran yang terbaca manusia.
     *
     * Diambil dari tabel `roles`, bukan dipetakan tetap di kode: desa dapat
     * mengganti nama perannya, dan pesan "bukan kewenangan Anda" yang
     * menyebut kode mentah `kasi_kesejahteraan` tidak membantu siapa pun.
     *
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    private static function roleLabels(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = Role::query()->whereIn('code', $codes)->pluck('name', 'code')->all();

        return array_values(array_map(
            fn (string $code): string => $names[$code] ?? $code,
            $codes,
        ));
    }

    /**
     * "Jatuh tempo besok" / "Terlambat 2 hari" — pada kalender desa.
     */
    private static function dueLabel(LetterRequest $request): string
    {
        if ($request->due_at === null) {
            return '—';
        }

        if (! $request->isOpen()) {
            return VillageTime::local($request->due_at)
                ->locale('id')->translatedFormat('d M Y');
        }

        $today = VillageTime::now()->startOfDay();
        $due = VillageTime::local($request->due_at)->startOfDay();
        $days = (int) $today->diffInDays($due, false);

        return match (true) {
            $days < -1 => 'Terlambat '.abs($days).' hari',
            $days === -1 => 'Terlambat sehari',
            $days === 0 => 'Jatuh tempo hari ini',
            $days === 1 => 'Jatuh tempo besok',
            default => 'Sisa '.$days.' hari',
        };
    }

    /** @return array<string, mixed> */
    public static function detail(LetterRequest $request): array
    {
        return [
            ...self::row($request),
            'form_data' => $request->form_data,
            'rejection_reason' => $request->rejection_reason,
            'revision_note' => $request->revision_note,
            'completed_at' => $request->completed_at?->toIso8601String(),
            'requirements' => $request->letterType->requirements,
            'warning_note' => $request->letterType->warning_note,
            'requires_field_check' => $request->letterType->requires_field_check,
            'allowed_transitions' => LetterRequest::TRANSITIONS[$request->status] ?? [],
            /*
             * Peran yang berwenang pada tahap pemeriksaan SAAT INI.
             *
             * Dikirim agar layar dapat menyembunyikan tombol Verifikasi dari
             * petugas yang bukan pemeriksa tahap ini. Sebelum ini, Kasi
             * Pemerintahan melihat tombol Verifikasi pada SKTM — yang
             * tahap pertamanya milik Kasi Kesejahteraan — menekannya, dan
             * tidak terjadi apa-apa (403 tanpa pesan).
             *
             * Ini KENYAMANAN, bukan kontrol keamanan: penegakannya tetap di
             * LetterRequestService::guardStepAuthority().
             */
            'step_roles' => $request->letterType->rolesForStep($request->current_step),
            'step_role_labels' => self::roleLabels(
                $request->letterType->rolesForStep($request->current_step)
            ),
            'logs' => array_map(
                self::log(...),
                $request->logs->all(),
            ),
            'letter' => $request->letter === null ? null : self::letter($request->letter),
        ];
    }

    /** @return array<string, mixed> */
    public static function log(LetterRequestLog $log): array
    {
        $labels = LetterRequest::statusLabels();

        return [
            'id' => $log->id,
            'from_status' => $log->from_status,
            'to_status' => $log->to_status,
            'from_label' => $log->from_status === null ? null : ($labels[$log->from_status] ?? $log->from_status),
            'to_label' => $labels[$log->to_status] ?? $log->to_status,
            'step' => $log->step,
            'actor_name' => $log->actor_name,
            'note' => $log->note,
            'created_at' => $log->created_at->toIso8601String(),
        ];
    }

    /**
     * Surat terbit.
     *
     * `rendered_html` TIDAK disertakan di sini: ia dikirim hanya pada layar
     * pratinjau/cetak, bukan pada setiap baris daftar. Isi surat memuat data
     * pribadi, dan daftar arsip dapat memuat puluhan baris sekaligus.
     *
     * @return array<string, mixed>
     */
    public static function letter(Letter $letter): array
    {
        return [
            'id' => $letter->id,
            'letter_number' => $letter->letter_number,
            'sequence_number' => $letter->sequence_number,
            'issued_at' => $letter->issued_at->toIso8601String(),
            'signer_name' => $letter->signer_name,
            'signer_position' => $letter->signer_position,
            'valid_until' => $letter->valid_until?->toDateString(),
            'is_expired' => $letter->isExpired(),
            'verification_code' => $letter->verification_code,
            'status' => $letter->status,
            'cancel_reason' => $letter->cancel_reason,
            'print_count' => $letter->print_count,
            /*
             * Status PDF ikut dikirim agar layar dapat membedakan "sedang
             * dirender" dari "gagal" — tanpa itu, satu-satunya yang dapat
             * ditampilkan adalah "menunggu" tanpa akhir (KEP-013).
             */
            'pdf_status' => $letter->pdf_status,
            'pdf_ready' => $letter->hasPdf(),
            'pdf_failed_reason' => $letter->pdf_failed_reason,
        ];
    }
}
