<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Izin granular — FR-ADM-02.
 *
 * Satu izin = satu tindakan konkret. Sengaja TIDAK memakai izin kasar seperti
 * "penduduk.kelola", karena matriks RBAC (docs/03-srs.md §4.9) membedakan
 * Operator (boleh tambah & ubah, TIDAK boleh hapus) dari Admin Desa.
 */
final class PermissionSeeder extends Seeder
{
    /** @var array<string, array<string, string>> modul => [kode => nama] */
    public const PERMISSIONS = [
        'kependudukan' => [
            'penduduk.lihat' => 'Melihat data penduduk',
            'penduduk.tambah' => 'Menambah penduduk',
            'penduduk.ubah' => 'Mengubah data penduduk',
            'penduduk.hapus' => 'Menghapus penduduk',
            'penduduk.impor' => 'Impor data penduduk massal',
            'penduduk.ekspor' => 'Ekspor data penduduk',
            'penduduk.usul_koreksi' => 'Mengusulkan koreksi data penduduk',
            'keluarga.lihat' => 'Melihat kartu keluarga',
            'keluarga.tambah' => 'Menambah kartu keluarga',
            'keluarga.ubah' => 'Mengubah kartu keluarga',
            'keluarga.hapus' => 'Menghapus kartu keluarga',
            'mutasi.lihat' => 'Melihat mutasi penduduk',
            'mutasi.tambah' => 'Mencatat mutasi penduduk',
            'mutasi.ubah' => 'Mengubah mutasi penduduk',
            'statistik.lihat' => 'Melihat statistik kependudukan',
            'wilayah.lihat' => 'Melihat data wilayah',
            'wilayah.kelola' => 'Mengelola wilayah (dusun/RW/RT)',
        ],
        'data_pribadi' => [
            // Izin terpisah & paling dibatasi. Setiap pemakaiannya dicatat
            // di audit log (RULE-07, AuditLogger::logPiiReveal).
            'data_pribadi.lihat_penuh' => 'Melihat NIK & nomor KK secara penuh',
        ],
        'persuratan' => [
            'jenis_surat.lihat' => 'Melihat daftar jenis surat',
            'jenis_surat.kelola' => 'Mengelola jenis surat & template',
            'surat.lihat' => 'Melihat pengajuan & arsip surat',
            'surat.ajukan' => 'Membuat pengajuan surat',
            'surat.ubah' => 'Mengubah pengajuan surat',
            'surat.verifikasi' => 'Memverifikasi pengajuan surat',
            'surat.setujui' => 'Menyetujui & menerbitkan surat',
            'surat.batalkan' => 'Membatalkan surat terbit',
            'surat.ekspor' => 'Ekspor data layanan surat',
        ],
        'pengaduan' => [
            'pengaduan.lihat' => 'Melihat pengaduan',
            'pengaduan.tugaskan' => 'Menugaskan pengaduan',
            'pengaduan.tindaklanjut' => 'Menindaklanjuti pengaduan',
        ],
        'portal' => [
            'portal.kelola' => 'Mengelola konten portal',
            'portal.terbitkan' => 'Menerbitkan konten portal',
        ],
        'keuangan' => [
            'keuangan.lihat' => 'Melihat data keuangan desa',
            'keuangan.kelola' => 'Mengelola data APBDes',
            'keuangan.publikasi' => 'Mempublikasikan APBDes',
            'keuangan.ekspor' => 'Ekspor data keuangan',
        ],
        'aset' => [
            'aset.lihat' => 'Melihat aset desa',
            'aset.kelola' => 'Mengelola aset desa',
            'aset.ekspor' => 'Ekspor data aset',
        ],
        'bantuan' => [
            'bantuan.lihat' => 'Melihat data bantuan sosial',
            'bantuan.kelola' => 'Mengelola data bantuan sosial',
            'bantuan.ekspor' => 'Ekspor data bantuan sosial',
        ],
        'laporan' => [
            'dashboard.lihat' => 'Melihat dashboard',
            'laporan.lihat' => 'Melihat laporan',
            'laporan.ekspor' => 'Ekspor laporan',
        ],
        'administrasi' => [
            'pengguna.kelola' => 'Mengelola pengguna & peran',
            'pengguna.verifikasi' => 'Memverifikasi akun warga',
            'perangkat.kelola' => 'Mengelola perangkat desa',
            'pengaturan.lihat' => 'Melihat pengaturan sistem',
            'pengaturan.ubah' => 'Mengubah pengaturan sistem',
            'audit.lihat' => 'Melihat log aktivitas',
            'audit.ekspor' => 'Ekspor log aktivitas',
        ],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $module => $permissions) {
            foreach ($permissions as $code => $name) {
                Permission::query()->updateOrCreate(
                    ['code' => $code],
                    ['module' => $module, 'name' => $name]
                );
            }
        }
    }

    /** @return list<string> */
    public static function allCodes(): array
    {
        return array_merge(...array_map(
            fn (array $group) => array_keys($group),
            array_values(self::PERMISSIONS)
        ));
    }
}
