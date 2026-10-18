<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Tawaran pencatatan mutasi dari surat SKL/SKM — docs/13 §5.4 & §5.5.
 *
 * TAWARAN, bukan perintah. Dokumen katalog surat memakai kata "menawarkan"
 * dengan sengaja: yang menekan tombolnya harus petugas, dan penolakannya
 * harus mungkin tanpa menghalangi penerbitan surat. Karena itu baris ini
 * dibuat SETELAH surat terbit dan tidak pernah menjadi syarat penerbitan.
 *
 * Seperti LetterAttachment, tabel ini tidak termasuk tabel yang dijaga
 * (config/sid.php guarded_tables) tetapi juga tidak boleh dicapai langsung
 * lewat id-nya: lingkup wilayah/keluarga ada pada pengajuannya. Satu-satunya
 * jalan adalah LetterRequestRepository::findOrFail() lalu relasi
 * `mutationOffer`, sehingga tawaran milik desa tetangga menghasilkan 404,
 * bukan 403 (docs/06 §4).
 *
 * @property string $id
 * @property string $request_id
 * @property string $letter_id
 * @property string $mutation_type
 * @property string $status
 * @property string|null $resident_mutation_id
 * @property Carbon|null $decided_at
 * @property string|null $decided_by
 * @property string|null $decided_by_name
 * @property string|null $decline_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read LetterRequest $request
 * @property-read Letter $letter
 * @property-read ResidentMutation|null $mutation
 */
final class LetterMutationOffer extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    public const STATUS_PENDING = 'menunggu';

    public const STATUS_ACCEPTED = 'diterima';

    public const STATUS_DECLINED = 'ditolak';

    /**
     * Jenis surat yang menawarkan mutasi, beserta jenis mutasinya.
     *
     * Dipetakan dari KODE jenis surat, bukan dari id-nya: kode `SKL`/`SKM`
     * adalah bagian dari katalog surat (docs/13) dan sama di setiap desa,
     * sementara id-nya acak per pemasangan.
     */
    public const OFFERED_BY_LETTER_CODE = [
        'SKL' => ResidentMutation::TYPE_BIRTH,
        'SKM' => ResidentMutation::TYPE_DEATH,
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<LetterRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(LetterRequest::class, 'request_id');
    }

    /** @return BelongsTo<Letter, $this> */
    public function letter(): BelongsTo
    {
        return $this->belongsTo(Letter::class, 'letter_id');
    }

    /**
     * Mutasi hasil tawaran yang diterima — null selama masih menunggu.
     *
     * @return BelongsTo<ResidentMutation, $this>
     */
    public function mutation(): BelongsTo
    {
        return $this->belongsTo(ResidentMutation::class, 'resident_mutation_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** "Kelahiran" / "Kematian" — label mutasi yang ditawarkan. */
    public function mutationLabel(): string
    {
        return ResidentMutation::typeLabels()[$this->mutation_type] ?? $this->mutation_type;
    }
}
