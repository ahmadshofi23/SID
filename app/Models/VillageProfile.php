<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Identitas desa — satu baris (id = 1). Menggantikan `tenants` (KEP-001).
 */
/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $district
 * @property string $regency
 * @property string $province
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
