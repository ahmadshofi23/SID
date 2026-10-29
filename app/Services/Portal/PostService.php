<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\Post;
use App\Services\Audit\AuditLogger;
use App\Services\Upload\FileSignature;
use App\Support\HtmlSanitizer;
use App\Support\VillageTime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Penyuntingan konten portal — FR-PRT-02, FR-PRT-03.
 *
 * Tiga hal yang membuat kelas ini ada, alih-alih menulis langsung dari
 * controller: slug yang tidak boleh berubah diam-diam, waktu terbit yang
 * harus dibaca pada kalender desa, dan isi HTML yang harus dibersihkan
 * sebelum disimpan.
 */
final class PostService
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly AuditLogger $audit,
    ) {}

    /** Disk sampul: PUBLIK, berbeda dari lampiran syarat yang privat. */
    private const COVER_DISK = 'public';

    private const COVER_MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $authorId, ?UploadedFile $cover = null): Post
    {
        return DB::transaction(function () use ($data, $authorId, $cover): Post {
            $post = new Post;
            $post->forceFill([
                'type' => $data['type'],
                'slug' => $this->uniqueSlug((string) $data['title']),
                'author_id' => $authorId,
            ]);

            $this->applyEditableFields($post, $data);
            $this->applyCover($post, $cover);
            $post->save();

            $this->audit->log('post.created', $post, context: [
                'jenis' => $post->type,
                'status' => $post->status,
            ]);

            return $post;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Post $post, array $data, ?UploadedFile $cover = null, bool $removeCover = false): Post
    {
        return DB::transaction(function () use ($post, $data, $cover, $removeCover): Post {
            $statusLama = $post->status;

            /*
             * SLUG TIDAK IKUT BERUBAH saat judul disunting, KECUALI tulisan
             * ini belum pernah terbit.
             *
             * Alasannya bukan kerapian basis data: alamat berita desa beredar
             * di grup WhatsApp warga, dan mengubah slug karena satu salah
             * ketik pada judul akan mematikan setiap tautan yang sudah
             * terlanjur dibagikan. Selama masih draf, belum ada yang bisa
             * membagikannya, jadi slug boleh mengikuti judul.
             */
            if ($post->published_at === null && $post->status === Post::STATUS_DRAFT) {
                $post->slug = $this->uniqueSlug((string) $data['title'], $post->id);
            }

            $this->applyEditableFields($post, $data);

            if ($removeCover) {
                $this->deleteCover($post);
            }

            $this->applyCover($post, $cover);
            $post->save();

            $this->audit->log('post.updated', $post, context: [
                'jenis' => $post->type,
                'status_lama' => $statusLama,
                'status_baru' => $post->status,
            ]);

            return $post;
        });
    }

    public function delete(Post $post, string $reason): void
    {
        /*
         * Soft delete, dan BERKAS SAMPUL TIDAK IKUT DIHAPUS. Pemulihan
         * tulisan yang terhapus tanpa gambarnya adalah pemulihan setengah
         * jadi; berkas yatim jauh lebih murah daripada gambar yang hilang
         * permanen karena satu klik keliru.
         */
        $post->delete();

        $this->audit->log('post.deleted', $post, context: [
            'jenis' => $post->type,
            'alasan' => $reason,
        ]);
    }

    /**
     * Terapkan kolom yang boleh disunting petugas.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyEditableFields(Post $post, array $data): void
    {
        $status = (string) $data['status'];

        $post->forceFill([
            'title' => trim((string) $data['title']),
            'excerpt' => $this->blankToNull($data['excerpt'] ?? null),
            // Profil ARTIKEL: berita boleh memuat tautan dan gambar,
            // template surat tidak (lihat HtmlSanitizer).
            'body' => $this->sanitizer->clean((string) $data['body'], HtmlSanitizer::PROFIL_ARTIKEL),
            'category_code' => $this->blankToNull($data['category_code'] ?? null),
            'tags' => $this->normalizeTags($data['tags'] ?? null),
            'status' => $status,
            'published_at' => $this->resolvePublishedAt($post, $status, $data['published_at'] ?? null),
            'meta_title' => $this->blankToNull($data['meta_title'] ?? null),
            'meta_description' => $this->blankToNull($data['meta_description'] ?? null),
        ]);

        if ($post->type === Post::TYPE_AGENDA) {
            $post->forceFill([
                'event_start_at' => $this->parseVillageTime($data['event_start_at'] ?? null),
                'event_end_at' => $this->parseVillageTime($data['event_end_at'] ?? null),
                'event_location' => $this->blankToNull($data['event_location'] ?? null),
            ]);

            return;
        }

        // Jenis selain agenda tidak boleh membawa sisa kolom agenda dari
        // tulisan yang jenisnya pernah diganti petugas.
        $post->forceFill([
            'event_start_at' => null,
            'event_end_at' => null,
            'event_location' => null,
        ]);
    }

    /**
     * Tanggal terbit: diisi petugas, atau "sekarang" saat pertama diterbitkan.
     *
     * Dibiarkan kosong, basis data akan menolaknya (chk_posts_terbit_bertanggal)
     * — dan memang seharusnya begitu: tulisan berstatus terbit tanpa tanggal
     * tidak akan pernah lolos saringan Post::scopePublished() dan akan
     * tersembunyi selamanya tanpa ada yang mengerti sebabnya.
     */
    private function resolvePublishedAt(Post $post, string $status, mixed $input): ?Carbon
    {
        if ($status !== Post::STATUS_TERBIT) {
            // Tanggal terbit lama DIPERTAHANKAN saat tulisan diarsipkan:
            // ia adalah catatan kapan warga pernah membacanya, bukan tombol.
            return $post->published_at;
        }

        $diisi = $this->parseVillageTime($input);

        return $diisi ?? $post->published_at ?? now();
    }

    /**
     * Baca "2026-10-20T09:00" dari formulir sebagai waktu KALENDER DESA.
     *
     * Petugas mengetik jam kegiatan di Sukamaju, bukan di UTC. Dibaca apa
     * adanya, agenda pukul 08.00 akan tersimpan tujuh jam lebih awal dan
     * muncul di portal sebagai pukul 01.00 (KEP-010).
     */
    private function parseVillageTime(mixed $input): ?Carbon
    {
        $value = is_string($input) ? trim($input) : '';

        if ($value === '') {
            return null;
        }

        return Carbon::parse($value, VillageTime::zone())->utc();
    }

    /**
     * Slug unik se-tabel, termasuk terhadap tulisan yang sudah dihapus.
     *
     * Dibandingkan ke kolom `slug`, BUKAN `slug_active`: tulisan terhapus
     * dapat dipulihkan, dan pemulihan yang gagal karena slug-nya sudah
     * dipakai orang lain adalah kegagalan yang muncul berbulan-bulan setelah
     * sebabnya.
     */
    private function uniqueSlug(string $title, ?string $exceptId = null): string
    {
        $dasar = Str::slug($title) ?: 'tulisan';
        $dasar = Str::limit($dasar, 180, '');
        $slug = $dasar;
        $urut = 1;

        while (
            Post::query()
                ->withTrashed()
                ->where('slug', $slug)
                ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
                ->exists()
        ) {
            $slug = $dasar.'-'.(++$urut);
        }

        return $slug;
    }

    /** @return list<string>|null */
    private function normalizeTags(mixed $input): ?array
    {
        $tags = is_array($input) ? $input : [];

        $bersih = collect($tags)
            ->filter(fn ($t): bool => is_string($t) && trim($t) !== '')
            ->map(fn (string $t): string => Str::limit(trim($t), 40, ''))
            ->unique()
            ->values()
            ->all();

        return $bersih === [] ? null : $bersih;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /* ============================== Sampul ============================== */

    /**
     * Alasan penolakan berkas sampul, atau null bila diterima.
     *
     * Dipisah dari penyimpanan supaya controller dapat menolak SEBELUM
     * tulisannya tersimpan — pola yang sama dengan lampiran syarat surat.
     */
    public function coverRejectionReason(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return 'Gambar gagal diunggah seluruhnya ('.$file->getErrorMessage().'). Coba unggah ulang.';
        }

        if ($file->getSize() > self::COVER_MAX_BYTES) {
            return 'Ukuran gambar melebihi 2 MB. Perkecil dahulu — halaman portal '
                .'dibatasi agar tetap ringan di jaringan desa.';
        }

        $signature = FileSignature::detect((string) $file->getRealPath());

        /*
         * PDF ditolak walau FileSignature menerimanya untuk lampiran surat:
         * sampul berita dipasang di dalam <img>, dan PDF di sana hanya
         * menghasilkan gambar rusak di halaman depan situs desa.
         */
        if ($signature === null || ! in_array($signature->extension, ['jpg', 'png'], true)) {
            return 'Isi berkas bukan gambar JPG atau PNG. Mengubah nama berkas '
                .'tidak mengubah isinya.';
        }

        return null;
    }

    private function applyCover(Post $post, ?UploadedFile $cover): void
    {
        if ($cover === null) {
            return;
        }

        $alasan = $this->coverRejectionReason($cover);
        $signature = FileSignature::detect((string) $cover->getRealPath());

        /*
         * Dua syarat diperiksa bersama walau coverRejectionReason() sudah
         * menutup keduanya: tanpa pemeriksaan null di sini, ekstensi berkas
         * harus ditebak, dan tebakan yang salah menghasilkan .jpg berisi PNG
         * yang gagal ditampilkan sebagian peramban.
         */
        if ($alasan !== null || $signature === null) {
            throw ValidationException::withMessages([
                'cover' => $alasan ?? 'Isi berkas bukan gambar JPG atau PNG.',
            ]);
        }

        // Nama acak, bukan nama asli dari komputer petugas: nama berkas
        // unggahan adalah masukan pengguna dan tidak boleh menjadi bagian URL.
        $path = (string) $cover->storeAs(
            'portal/sampul',
            Str::uuid7()->toString().'.'.$signature->extension,
            self::COVER_DISK,
        );

        $this->deleteCover($post);
        $post->cover_path = $path;
    }

    private function deleteCover(Post $post): void
    {
        if ($post->cover_path !== null) {
            Storage::disk(self::COVER_DISK)->delete($post->cover_path);
            $post->cover_path = null;
        }
    }
}
