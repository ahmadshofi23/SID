<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Audit log append-only ber-hash-chain — ADR-005, FR-ADM-04, RULE-12.
 *
 * Model ini MENOLAK pembaruan & penghapusan di lapis aplikasi. Hak akses MySQL
 * (php artisan sid:harden-audit-log) menolaknya di lapis basis data. Dua lapis,
 * karena log yang dapat diubah tidak bernilai sebagai bukti.
 */
/**
 * @property int $id
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string $action
 * @property array<array-key, mixed>|null $changes
 * @property array<array-key, mixed>|null $context
 * @property string|null $prev_hash
 * @property string $row_hash
 * @property int $hash_version
 * @property Carbon $created_at
 */
final class AuditLog extends Model
{
    /**
     * Versi rumus hash yang dipakai baris BARU — KEP-020.
     *
     * Baris v1 (seluruh baris sebelum KEP-020) tidak dapat dihitung ulang,
     * dan itu bukan kelalaian melainkan sifat cacatnya: lihat keterangan
     * panjang pada hashFromRow().
     */
    public const HASH_VERSION = 2;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'context' => 'array',
            'hash_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException('Audit log tidak dapat diubah (append-only).');
        });

        self::deleting(function (): never {
            throw new RuntimeException('Audit log tidak dapat dihapus (append-only).');
        });
    }

    /**
     * Hitung hash baris. Dirantai ke baris sebelumnya sehingga penyisipan,
     * penghapusan, atau perubahan baris di luar aplikasi dapat dideteksi.
     *
     * Perhitungannya diserahkan ke hashFromRow() atas ATRIBUT MENTAH model,
     * bukan atas properti model. Itu bukan selera gaya: lihat butir 1 pada
     * keterangan hashFromRow() — mengambil nilainya lewat `$this->changes`
     * adalah cacat yang bertahan tujuh sprint, dan satu-satunya pencegahnya
     * adalah tidak punya dua jalur yang dapat menyimpang.
     */
    public function computeHash(?string $previousHash): string
    {
        return self::hashFromRow($this->getAttributes(), $previousHash);
    }

    /**
     * Hitung hash satu baris dari representasi MENTAHNYA — satu-satunya
     * tempat rumus hash audit log ditulis.
     *
     * Dipanggil dua kali untuk setiap baris selama hidupnya: sekali saat
     * ditulis (dari atribut model), sekali saat diverifikasi (dari baris
     * basis data mentah, tanpa menghidrasi model — docs/23 §7 butir 5).
     * Kedua pemanggilan WAJIB menghasilkan nilai yang sama, dan dua cacat
     * KEP-020 keduanya berupa pelanggaran syarat itu:
     *
     * 1. **Kolom `changes` tidak pernah ikut.** Rumus lama memakai
     *    `$this->changes` DI DALAM kelas model, dan di dalam kelas nama itu
     *    tidak mengenai kolomnya: ia mengenai properti protected `$changes`
     *    milik Illuminate\Database\Eloquent\Concerns\HasAttributes — daftar
     *    atribut yang berubah setelah save(), yang di sini selalu `[]`. Dari
     *    LUAR kelas, `$entry->changes` justru mengembalikan isi kolomnya,
     *    karena properti protected tidak terjangkau dan PHP jatuh ke
     *    __get(). Dua nilai berbeda untuk satu nama, dan yang terpakai justru
     *    yang kosong — sehingga nilai lama/baru data penduduk dapat diubah
     *    langsung di basis data tanpa merusak rantai, padahal justru kolom
     *    itulah yang berisi datanya.
     *
     * 2. **Urutan kunci JSON tidak kanonik.** Rumus lama meng-encode kedua
     *    kolom JSON menurut urutan kunci yang kebetulan dipakai pemanggil.
     *    MySQL menyimpan kolom JSON dalam bentuk binernya sendiri dan
     *    mengembalikan kuncinya TERURUT (panjang, lalu leksikal), jadi baris
     *    yang ditulis sebagai {"nomor_surat":…,"jenis_surat":…,"tiket":…}
     *    dibaca kembali sebagai {"tiket":…,"jenis_surat":…,"nomor_surat":…}.
     *    json_encode mempertahankan urutan masukan, sehingga hash saat
     *    verifikasi berbeda dari hash saat penulisan — dan baris yang utuh
     *    dilaporkan rusak. Itu bukan cacat kecil: alarm integritas bukti yang
     *    berbunyi atas data jujur akan diabaikan, dan kendali yang diabaikan
     *    sama nilainya dengan kendali yang tidak ada. `sid:verify-audit-chain`
     *    memang berbunyi "RANTAI RUSAK" pada basis data pengembangan sebelum
     *    KEP-020, dan penyebabnya tepat ini.
     *
     * Karena itu kedua kolom JSON di-hash lewat canonicalize(): bentuk yang
     * TIDAK bergantung pada urutan kunci, sehingga apa yang dihitung saat
     * penulisan dan saat verifikasi tidak dapat lagi berbeda karena cara
     * MySQL menyimpannya.
     *
     * @param  array<string, mixed>  $row  atribut model ATAU baris mentah
     */
    public static function hashFromRow(array $row, ?string $previousHash): string
    {
        $payload = json_encode([
            'v' => self::HASH_VERSION,
            'prev' => $previousHash,
            'actor_id' => $row['actor_id'] ?? null,
            'actor_name' => $row['actor_name'] ?? null,
            'action' => $row['action'] ?? null,
            'entity_type' => $row['entity_type'] ?? null,
            'entity_id' => $row['entity_id'] ?? null,
            'changes' => self::canonicalJson($row['changes'] ?? null),
            'context' => self::canonicalJson($row['context'] ?? null),
            'ip_address' => $row['ip_address'] ?? null,
            'created_at' => self::canonicalTimestamp($row['created_at'] ?? null),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $payload);
    }

    /**
     * Bentuk kanonik satu nilai kolom JSON.
     *
     * Menerima apa pun yang dapat berada di atribut itu: string JSON (baik
     * hasil encode PHP saat penulisan maupun bentuk kanonik MySQL saat
     * verifikasi), array yang sudah ter-cast, atau null.
     */
    private static function canonicalJson(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return self::canonicalize($value);
    }

    /**
     * Urutkan kunci objek secara rekursif; biarkan urutan senarai apa adanya.
     *
     * Senarai TIDAK diurutkan karena urutannya bermakna dan MySQL pun
     * mempertahankannya — mengurutkannya akan membuat dua daftar yang
     * berbeda ber-hash sama.
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * Bentuk kanonik `created_at`, dengan milidetik.
     *
     * Kolomnya DATETIME(3), jadi MySQL sudah mengembalikan tepat tiga angka
     * desimal; yang perlu diseragamkan hanyalah atribut saat penulisan, yang
     * masih berupa objek waktu. Carbon::parse SENGAJA tidak dipakai untuk
     * string yang sudah berbentuk benar: fungsi ini dijalankan sekali per
     * baris saat verifikasi, dan pada 1,5 juta baris satu penguraian tanggal
     * yang tidak perlu adalah biaya yang nyata (docs/23 §7 butir 5).
     */
    private static function canonicalTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.v');
        }

        $value = (string) $value;

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $value) === 1) {
            return $value;
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s.v');
    }
}
