<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Lampiran syarat pengajuan surat — FR-SRT-03, NFR-SEC-05.
 *
 * TIDAK termasuk tabel yang dijaga (config/sid.php guarded_tables), tetapi
 * tetap tidak boleh dicapai langsung lewat id-nya: setiap barisnya menggantung
 * pada satu pengajuan, dan lingkup wilayah/keluarga ada pada pengajuan itu.
 * Satu-satunya jalan yang dipakai pemanggil adalah
 * LetterRequestRepository::findOrFail() lalu relasi `attachments` — sehingga
 * berkas milik desa tetangga menghasilkan 404, bukan 403 (docs/06 §4).
 *
 * @property string $id
 * @property string $request_id
 * @property int|null $requirement_index
 * @property string|null $requirement_label
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property string $extension
 * @property int $size_bytes
 * @property string $sha256
 * @property string|null $uploaded_by
 * @property string|null $uploaded_by_name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read LetterRequest $request
 */
final class LetterAttachment extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requirement_index' => 'integer',
            'size_bytes' => 'integer',
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

    /** Dapat ditampilkan langsung di peramban, bukan hanya diunduh. */
    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * Nama berkas saat diunduh.
     *
     * Dirakit ulang dari nama asli yang sudah dibersihkan DITAMBAH ekstensi
     * hasil deteksi isi — bukan ekstensi yang dikirim peramban. Berkas yang
     * diunggah bernama `ktp.pdf` tetapi isinya JPEG akan turun sebagai
     * `ktp.jpg`, sehingga tidak ada yang membukanya dengan pembaca yang salah.
     */
    public function downloadName(): string
    {
        $base = pathinfo($this->original_name, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $base) ?? '');

        return ($base === '' ? 'lampiran' : Str::limit($base, 80, '')).'.'.$this->extension;
    }
}
