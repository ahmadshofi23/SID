<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Surat terbit — ADR-003: snapshot permanen.
 *
 * TABEL DIJAGA (config/sid.php guarded_tables).
 *
 * Baris di sini mewakili dokumen resmi yang sudah keluar dari kantor desa dan
 * mungkin sudah dipegang warga. Ia TIDAK BOLEH diubah: bila isinya salah,
 * surat dibatalkan dan diterbitkan pengganti (RULE-05), sehingga jejak
 * koreksinya tetap terlihat. Larangan ini ditegakkan di `booted()`, bukan
 * sekadar disepakati dalam dokumen.
 */
/**
 * @property string $id
 * @property string $request_id
 * @property string $letter_type_id
 * @property string $letter_number
 * @property int $sequence_number
 * @property Carbon $issued_at
 * @property int $issued_year
 * @property string $issued_by
 * @property string $signer_name
 * @property string $signer_position
 * @property Carbon|null $valid_until
 * @property array<string, mixed> $snapshot
 * @property string $rendered_html
 * @property string|null $pdf_path
 * @property string $verification_code
 * @property string $status
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $replaces_letter_id
 * @property int $print_count
 * @property-read LetterRequest $request
 * @property-read LetterType $letterType
 */
final class Letter extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public const STATUS_VALID = 'sah';

    public const STATUS_CANCELLED = 'dibatalkan';

    /**
     * Kolom yang masih boleh berubah setelah surat terbit.
     *
     * Pembatalan dan pencetakan adalah peristiwa yang terjadi SETELAH terbit,
     * jadi kolomnya memang harus dapat ditulis. Isi surat, nomor, dan
     * snapshot-nya tidak.
     */
    private const MUTABLE_AFTER_ISSUE = [
        'status', 'cancelled_at', 'cancelled_by', 'cancel_reason',
        'print_count', 'pdf_path', 'pdf_hash',
    ];

    protected static function booted(): void
    {
        self::updating(function (self $letter): void {
            $changed = array_keys($letter->getDirty());
            $forbidden = array_diff($changed, self::MUTABLE_AFTER_ISSUE);

            if ($forbidden !== []) {
                throw new LogicException(
                    'Surat yang sudah terbit tidak dapat diubah (ADR-003). Kolom: '
                    .implode(', ', $forbidden)
                    .'. Batalkan surat lalu terbitkan pengganti (RULE-05).'
                );
            }
        });

        self::deleting(function (): void {
            throw new LogicException(
                'Surat terbit tidak dapat dihapus (ADR-003). Gunakan pembatalan.'
            );
        });
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'issued_at' => 'datetime',
            'valid_until' => 'date',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Huruf dan angka yang mudah tertukar saat dibacakan di telepon atau
     * diketik ulang dari kertas (0/O, 1/I/L, 2/Z, 5/S, 8/B) dikeluarkan.
     */
    private const VERIFICATION_ALPHABET = '34679ACDEFGHJKMNPQRTUVWXY';

    /**
     * Kode verifikasi untuk QR — FR-SRT-14.
     *
     * Bukan UUID surat: 32 karakter acak tanpa kaitan dengan data apa pun,
     * sehingga tidak dapat diterka dan tidak membocorkan urutan penerbitan.
     * Memakai random_int(), bukan Str::random() yang di-upper-case — huruf
     * besar-kecil yang diseragamkan akan memangkas entropi diam-diam.
     */
    public static function generateVerificationCode(): string
    {
        $code = '';

        for ($i = 0; $i < 32; $i++) {
            $code .= self::VERIFICATION_ALPHABET[
                random_int(0, strlen(self::VERIFICATION_ALPHABET) - 1)
            ];
        }

        return $code;
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID
            && ($this->valid_until === null || ! $this->valid_until->isPast());
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /** @return BelongsTo<LetterRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(LetterRequest::class, 'request_id');
    }

    /** @return BelongsTo<LetterType, $this> */
    public function letterType(): BelongsTo
    {
        return $this->belongsTo(LetterType::class);
    }
}
