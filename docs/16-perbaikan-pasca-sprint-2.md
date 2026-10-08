# 16 — Perbaikan Pasca-Sprint 2
**Tanggal:** 2026-10-08 · **Pemicu:** HTTP 500 pada halaman Wilayah yang dilaporkan pengguna

---

## 1. Ringkasan

| | |
|---|---|
| Gejala | `LazyLoadingViolationException` — HTTP 500 di `/kependudukan/wilayah` |
| Penyebab langsung | `RegionService::tree()` memuat dua tingkat relasi; penyaji menelusuri tiga |
| **Penyebab sesungguhnya** | **Pengaman yang seharusnya menangkap ini tidak pernah aktif** |
| Dampak | Satu halaman tidak dapat dibuka sama sekali |
| Status | Diperbaiki, diuji, dan diverifikasi balik |

---

## 2. Mengapa ini lolos dari 134 uji

Laporan Sprint 2 §3.3 menyatakan bahwa larangan *lazy loading* dinyalakan saat
pengujian agar kelas bug ini tertangkap otomatis.

**Pernyataan itu keliru.** Perubahannya tidak pernah masuk ke kode.

Penggantian teks pada `tests/TestCase.php` menargetkan pola dengan nama kelas
berkualifikasi penuh, sementara berkasnya memakai nama yang sudah di-*import*.
Penggantian gagal tanpa pesan apa pun, dan saya melaporkan keberhasilan tanpa
memverifikasi isinya.

```php
// Yang dilaporkan ada di tests/TestCase.php
Model::preventLazyLoading(true);

// Yang sebenarnya ada di sana sampai 2026-10-08
Model::preventLazyLoading(false);
```

Begitu larangan benar-benar dinyalakan, **dua** pelanggaran langsung muncul — dan
keduanya sudah ada sejak Sprint 2.

### Pelajaran proses
Setiap penggantian teks otomatis kini **wajib** memverifikasi bahwa polanya cocok
sebelum menulis berkas. Penggantian yang gagal diam-diam lebih berbahaya daripada
yang gagal berisik: ia menghasilkan laporan yang salah, dan keputusan berikutnya
diambil di atas keyakinan yang keliru.

---

## 3. Perbaikan

### 3.1 `tests/TestCase.php` — larangan lazy loading dinyalakan
Sekarang benar-benar aktif saat pengujian, sama seperti di pengembangan dan staging.

### 3.2 `RegionService::tree()` — asumsi kedalaman dihapus

**Sebelum** — memuat dua tingkat, penyaji menelusuri tiga:
```php
Region::query()->with(['children.children'])->whereNull('parent_id')->get();
```

**Sesudah** — satu kueri, pohon dirakit di memori, kedalaman bebas:
```php
$all = Region::query()->orderBy('sort_order')->orderBy('code')->get();
$byParent = $all->groupBy('parent_id');

foreach ($all as $region) {
    $region->setRelation('children', new EloquentCollection(
        $byParent->get($region->id, collect())->all()
    ));
}

return $all->filter(fn (Region $r): bool => $r->parent_id === null)->values();
```

Menambah satu `.children` lagi hanya memindahkan masalahnya satu tingkat ke bawah.
Merakit di memori menghapus asumsinya sama sekali — dan untuk puluhan wilayah satu
desa, satu kueri jauh lebih murah daripada kueri bertingkat.

### 3.3 `Region::booted()` — sinkronisasi path turunan
Pelanggaran kedua, tersembunyi di jalur simpan: saat kode wilayah berubah, path
seluruh turunannya dihitung ulang lewat `$region->children` — relasi yang tidak
pernah dimuat di alur itu.

```php
foreach ($region->children()->get() as $child) {   // kueri eksplisit
    $child->setRelation('parent', $region);        // buildPath() anak siap
    $child->save();                                // rekursi ke bawah
}
```

`setRelation('parent', ...)` juga menghilangkan satu kueri per simpul saat rekursi.

---

## 4. Pengaman baru

| Uji | Menjaga |
|-----|---------|
| `halaman_wilayah_merender_pohon_penuh` | Halaman Wilayah merender pohon **3 tingkat** dan memeriksa simpul terdalam — tempat bug aslinya meledak |
| `preventLazyLoading(true)` di `TestCase` | Seluruh uji kini menangkap relasi yang lupa di-*eager-load*, berikut N+1 query |

**Verifikasi balik:** bug aslinya dikembalikan dengan sengaja; kedua uji gagal dan
menyebutkan `lazy load [children] on model [App\Models\Region]`. Setelah dipulihkan,
135 uji lolos.

---

## 5. Status

```
135 uji · 348 asersi · semua lolos
PHPStan level 6 · 0 error     vue-tsc · 0 error     Pint · bersih
```

Halaman Wilayah diverifikasi langsung di peramban: pohon Dusun → RW → RT tampil utuh.
