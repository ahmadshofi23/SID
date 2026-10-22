<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Pengaduan warga — FR-PGD-01 s.d. FR-PGD-08, UC-13, UC-14.
 *
 * TABEL DIJAGA: akses hanya melalui App\Repositories\ComplaintRepository
 * (config/sid.php guarded_tables). Isi pengaduan sering memuat nama orang
 * yang diadukan, dan pengaduan terikat wilayah pelapornya.
 */
/**
 * @property string $id
 * @property string $ticket_number
 * @property string $tracking_code
 * @property string $category_code
 * @property string $title
 * @property string $body
 * @property bool $is_anonymous
 * @property string|null $reporter_user_id
 * @property string|null $reporter_name
 * @property string|null $reporter_phone
 * @property string|null $region_id
 * @property string|null $location_text
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string $status
 * @property string $priority
 * @property string|null $assigned_to
 * @property Carbon|null $assigned_at
 * @property Carbon|null $triage_due_at
 * @property Carbon|null $due_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolution
 * @property bool $is_published
 * @property string|null $submitted_ip
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $reporter
 * @property-read User|null $assignee
 * @property-read Region|null $region
 * @property-read Collection<int, ComplaintAction> $actions
 */
final class Complaint extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    public const STATUS_NEW = 'baru';

    public const STATUS_ACCEPTED = 'diterima';

    public const STATUS_IN_PROGRESS = 'diproses';

    public const STATUS_DONE = 'selesai';

    public const STATUS_REJECTED = 'ditolak';

    /**
     * MESIN STATUS — FR-PGD-03.
     *
     * Ditulis sebagai data mengikuti LetterRequest::TRANSITIONS, agar seluruh
     * transisi sah terbaca sekaligus. Tiga hal yang perlu diperhatikan:
     *
     *   1. `baru` dapat langsung `ditolak` tanpa lewat `diterima`. Pengaduan
     *      yang jelas bukan urusan desa atau jelas spam tidak perlu ditriase
     *      dulu — memaksanya lewat `diterima` hanya membuat angka SLA desa
     *      tampak lebih sibuk daripada kenyataannya.
     *   2. `diproses` dapat kembali ke `diterima`. Tindak lanjut yang ternyata
     *      salah sasaran dan harus ditugaskan ulang bukan kegagalan yang perlu
     *      ditutup dengan membuat pengaduan baru; pelapor tidak boleh kehilangan
     *      kode pelacakannya karena salah penugasan di dalam kantor.
     *   3. `selesai` dan `ditolak` adalah AKHIR. Pengaduan yang dibuka kembali
     *      akan mengaburkan waktu penyelesaian dan riwayat SLA; keluhan lanjutan
     *      diajukan sebagai pengaduan baru yang merujuk tiket lama.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_NEW => [self::STATUS_ACCEPTED, self::STATUS_REJECTED],
        self::STATUS_ACCEPTED => [
            self::STATUS_IN_PROGRESS, self::STATUS_DONE, self::STATUS_REJECTED,
        ],
        self::STATUS_IN_PROGRESS => [
            self::STATUS_DONE, self::STATUS_ACCEPTED, self::STATUS_REJECTED,
        ],
        self::STATUS_DONE => [],
        self::STATUS_REJECTED => [],
    ];

    /** Status yang masih menunggu tindakan perangkat desa — antrean & SLA. */
    public const OPEN_STATUSES = [
        self::STATUS_NEW,
        self::STATUS_ACCEPTED,
        self::STATUS_IN_PROGRESS,
    ];

    public const PRIORITY_LOW = 'rendah';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'tinggi';

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_NEW => 'Baru',
            self::STATUS_ACCEPTED => 'Diterima',
            self::STATUS_IN_PROGRESS => 'Diproses',
            self::STATUS_DONE => 'Selesai',
            self::STATUS_REJECTED => 'Ditolak',
        ];
    }

    /** @return array<string, string> */
    public static function priorityLabels(): array
    {
        return [
            self::PRIORITY_LOW => 'Rendah',
            self::PRIORITY_NORMAL => 'Normal',
            self::PRIORITY_HIGH => 'Tinggi',
        ];
    }

    protected function casts(): array
    {
        return [
            'is_anonymous' => 'boolean',
            'is_published' => 'boolean',
            'assigned_at' => 'datetime',
            'triage_due_at' => 'datetime',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    /**
     * Terlambat dari SLA — DIHITUNG, tidak disimpan (FR-PGD-06).
     *
     * Alasannya sama dengan LetterRequest::isOverdue(): kolom "terlambat" yang
     * disimpan hanya benar sampai detik berikutnya, dan menjadi dusta begitu
     * penjadwal yang memperbaruinya mati. Perintah `sid:eskalasi-pengaduan`
     * hanya menaikkan prioritas dan mengirim notifikasi; keterlambatan yang
     * TERLIHAT di antrean dan dashboard selalu dihitung dari kueri, sehingga
     * penjadwal yang mati membuat desa kehilangan peringatan — bukan
     * kehilangan kebenaran.
     */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->activeDueAt()?->isPast() === true;
    }

    /**
     * Tenggat yang sedang berlaku: triase selagi `baru`, penyelesaian setelah
     * ditriase. Kedua kolom tetap tersimpan utuh (KEP-018); yang berpindah
     * hanyalah mana di antara keduanya yang sedang ditunggu.
     */
    public function activeDueAt(): ?Carbon
    {
        return $this->status === self::STATUS_NEW
            ? $this->triage_due_at
            : $this->due_at;
    }

    /** Terlambat ditriase — dipakai laporan ketepatan triase, termasuk setelah selesai. */
    public function missedTriage(): bool
    {
        if ($this->triage_due_at === null) {
            return false;
        }

        $triagedAt = $this->actions
            ->firstWhere('to_status', self::STATUS_ACCEPTED)?->created_at;

        return ($triagedAt ?? now())->greaterThan($this->triage_due_at);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return HasMany<ComplaintAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ComplaintAction::class)->orderBy('created_at');
    }

    /**
     * Nama pelapor sebagaimana boleh ditampilkan.
     *
     * Satu tempat, supaya tidak ada layar yang lupa: pengaduan anonim TIDAK
     * punya nama untuk ditampilkan, dan yang muncul harus kata yang jujur —
     * bukan string kosong yang terlihat seperti data hilang.
     *
     * Dibaca dari kolom `reporter_name` yang DISALIN saat pengaduan dibuat,
     * bukan lewat relasi `reporter`. Dua alasan: metode ini dipanggil dari
     * daftar antrean, dan relasi yang tidak di-eager-load di sana akan
     * melanggar preventLazyLoading — berupa HTTP 500 di hadapan petugas,
     * bukan di CI. Dan nama yang ditampilkan di riwayat pengaduan harus tetap
     * nama yang dipakai SAAT melapor, dengan alasan yang sama seperti
     * `actor_name` pada ComplaintAction.
     */
    public function reporterLabel(): string
    {
        if ($this->is_anonymous) {
            return 'Anonim';
        }

        return $this->reporter_name ?? 'Tidak diketahui';
    }
}
