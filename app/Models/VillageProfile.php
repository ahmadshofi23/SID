<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Identitas desa — satu baris (id = 1). Menggantikan `tenants` (KEP-001).
 */
/**
 * @property int $id
 *
 * MENGAPA SEMUANYA NULLABLE, walau kolomnya NOT NULL: current()
 * mengembalikan instance KOSONG bila baris profil desa belum ada
 * (pemasangan baru, sebelum seeder dijalankan), sehingga atribut apa pun
 * dapat bernilai null. Sempat ditulis non-nullable untuk lima kolom
 * pertama, dan akibatnya analisis statis menganggap pemeriksaan null di
 * pemanggil sebagai kode mati — padahal justru pemeriksaan itulah yang
 * mencegah halaman kosong pada pemasangan baru.
 * @property string|null $code
 * @property string|null $name
 * @property string|null $district
 * @property string|null $regency
 * @property string|null $province
 * @property string|null $timezone
 * @property string|null $address
 * @property string|null $postal_code
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $website
 * @property string|null $logo_path
 */
final class VillageProfile extends Model
{
    protected $table = 'village_profile';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Selalu baris id = 1; di-cache karena dipakai hampir setiap halaman.
     *
     * Yang di-cache adalah ARRAY ATRIBUT, bukan objek model. Menyimpan objek
     * Eloquent ke cache bersifat rapuh: payload hasil serialisasi terikat pada
     * bentuk kelas saat itu, sehingga setelah kelas berubah (atau antar versi
     * rilis) deserialisasi menghasilkan __PHP_Incomplete_Class dan seluruh
     * halaman gagal dimuat.
     */
    public static function current(): self
    {
        $attributes = cache()->remember(
            'village_profile',
            now()->addHour(),
            fn (): array => self::query()->find(1)?->attributesToArray() ?? []
        );

        return $attributes === []
            ? new self
            : (new self)->forceFill($attributes)->syncOriginal();
    }

    public static function forget(): void
    {
        cache()->forget('village_profile');
    }

    /** "Desa Sukamaju, Kec. Sukaraja, Kab. Contoh" */
    public function fullName(): string
    {
        return sprintf(
            'Desa %s, Kec. %s, Kab. %s', $this->name, $this->district, $this->regency
        );
    }
}
