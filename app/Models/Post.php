<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\VillageTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Konten portal: berita, pengumuman, agenda — FR-PRT-02, FR-PRT-03.
 *
 * BUKAN tabel dijaga (config/sid.php guarded_tables): isinya memang
 * dipublikasikan desa dan tidak memuat data pribadi warga. Karena itu ia
 * tidak lewat App\Repositories — tetapi penyaringan "boleh dilihat publik"
 * tetap terpusat di satu tempat, yaitu scopePublished() di bawah.
 */
/**
 * @property string $id
 * @property string $type
 * @property string $slug
 * @property string $title
 * @property string|null $excerpt
 * @property string $body
 * @property string|null $cover_path
 * @property string|null $category_code
 * @property array<int, string>|null $tags
 * @property Carbon|null $event_start_at
 * @property Carbon|null $event_end_at
 * @property string|null $event_location
 * @property string $status
 * @property Carbon|null $published_at
 * @property string|null $author_id
 * @property int $view_count
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property-read User|null $author
 */
final class Post extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'slug_active'];

    public const TYPE_BERITA = 'berita';

    public const TYPE_PENGUMUMAN = 'pengumuman';

    public const TYPE_AGENDA = 'agenda';

    public const TYPES = [self::TYPE_BERITA, self::TYPE_PENGUMUMAN, self::TYPE_AGENDA];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_TERBIT = 'terbit';

    public const STATUS_ARSIP = 'arsip';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_TERBIT, self::STATUS_ARSIP];

    /** Label jenis untuk antarmuka — satu sumber, dipakai Blade & Inertia. */
    public const TYPE_LABELS = [
        self::TYPE_BERITA => 'Berita',
        self::TYPE_PENGUMUMAN => 'Pengumuman',
        self::TYPE_AGENDA => 'Agenda',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'event_start_at' => 'datetime',
            'event_end_at' => 'datetime',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * SATU-SATUNYA definisi "boleh dilihat publik".
     *
     * Dua syaratnya tidak boleh dipisah. `status = terbit` saja akan
     * membocorkan pengumuman yang sengaja dijadwalkan untuk besok — misalnya
     * hasil musyawarah yang belum boleh beredar hari ini. Perbandingan waktu
     * dilakukan di dalam kueri agar tidak ada jalan melewatinya dengan
     * memuat baris lebih dulu lalu memeriksanya di PHP.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_TERBIT)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** Sudah terbit, atau masih menunggu tanggalnya? */
    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_TERBIT
            && $this->published_at !== null
            && $this->published_at->isFuture();
    }

    /** Agenda yang belum lewat — dasar pemisahan "mendatang" dan "sudah lewat". */
    public function isUpcoming(): bool
    {
        $akhir = $this->event_end_at ?? $this->event_start_at;

        return $akhir !== null && $akhir->isFuture();
    }

    /**
     * Rentang waktu agenda dalam kalender desa (KEP-010).
     *
     * Waktu disimpan UTC; "13 Oktober pukul 09.00" hanya punya arti pada zona
     * desa, dan kegiatan yang dimulai pukul 08.00 WIB akan tercetak sebagai
     * 01.00 bila diformat apa adanya.
     */
    public function eventRangeLabel(): string
    {
        if ($this->event_start_at === null) {
            return '';
        }

        $mulai = VillageTime::local($this->event_start_at)->locale('id');
        $label = $mulai->translatedFormat('l, j F Y · H.i');

        if ($this->event_end_at === null) {
            return $label;
        }

        $selesai = VillageTime::local($this->event_end_at)->locale('id');

        // Kegiatan satu hari cukup menyebut jam selesainya saja.
        return $mulai->isSameDay($selesai)
            ? $label.'–'.$selesai->translatedFormat('H.i')
            : $label.' s.d. '.$selesai->translatedFormat('l, j F Y · H.i');
    }
}
