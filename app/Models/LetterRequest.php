<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Pengajuan surat — FR-SRT-03 s.d. FR-SRT-07.
 *
 * TABEL DIJAGA: akses hanya melalui App\Repositories\LetterRequestRepository
 * (config/sid.php guarded_tables). Isi form_data dapat memuat data pribadi,
 * dan pengajuan terikat pada wilayah pemohon.
 */
/**
 * @property string $id
 * @property string $letter_type_id
 * @property string $ticket_number
 * @property string $resident_id
 * @property string|null $requested_by
 * @property string $channel
 * @property string|null $purpose
 * @property array<string, mixed> $form_data
 * @property string $status
 * @property int $current_step
 * @property Carbon|null $submitted_at
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property string|null $rejection_reason
 * @property string|null $revision_note
 * @property-read LetterType $letterType
 * @property-read Resident $resident
 * @property-read Letter|null $letter
 */
final class LetterRequest extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'diajukan';

    public const STATUS_NEEDS_REVISION = 'perlu_revisi';

    public const STATUS_VERIFIED = 'diverifikasi';

    public const STATUS_ISSUED = 'diterbitkan';

    public const STATUS_REJECTED = 'ditolak';

    public const STATUS_CANCELLED = 'dibatalkan';

    /**
     * MESIN STATUS — satu-satunya sumber kebenaran transisi (FR-SRT-07).
     *
     * Ditulis sebagai data, bukan rangkaian `if`, agar seluruh transisi yang
     * sah terbaca sekaligus dalam satu layar. Status akhir sengaja memetakan
     * ke daftar kosong: surat yang sudah terbit, ditolak, atau dibatalkan
     * TIDAK dapat dihidupkan kembali. Pemohon mengajukan ulang sebagai
     * permintaan baru, sehingga riwayatnya tetap utuh untuk audit.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [
            self::STATUS_VERIFIED, self::STATUS_NEEDS_REVISION,
            self::STATUS_REJECTED, self::STATUS_CANCELLED,
        ],
        self::STATUS_NEEDS_REVISION => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_VERIFIED => [
            self::STATUS_ISSUED, self::STATUS_NEEDS_REVISION,
            self::STATUS_REJECTED, self::STATUS_CANCELLED,
        ],
        self::STATUS_ISSUED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draf',
            self::STATUS_SUBMITTED => 'Diajukan',
            self::STATUS_NEEDS_REVISION => 'Perlu Revisi',
            self::STATUS_VERIFIED => 'Diverifikasi',
            self::STATUS_ISSUED => 'Diterbitkan',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_CANCELLED => 'Dibatalkan',
        ];
    }

    /** Status yang masih menunggu tindakan petugas — dipakai antrean & SLA. */
    public const OPEN_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_NEEDS_REVISION,
        self::STATUS_VERIFIED,
    ];

    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'submitted_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Terlambat dari SLA — dihitung, bukan disimpan, agar tak pernah usang. */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /** @return BelongsTo<LetterType, $this> */
    public function letterType(): BelongsTo
    {
        return $this->belongsTo(LetterType::class);
    }

    /** @return BelongsTo<Resident, $this> */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<LetterRequestLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(LetterRequestLog::class, 'request_id');
    }

    /** @return HasOne<Letter, $this> */
    public function letter(): HasOne
    {
        return $this->hasOne(Letter::class, 'request_id');
    }
}
