<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Peran & pemetaan izin — terjemahan langsung matriks RBAC docs/03-srs.md §4.9.
 *
 * Catatan penting:
 *  - Tidak ada peran "Super Admin" (KEP-001: single-tenant).
 *  - Operator TIDAK punya izin hapus maupun setujui — pemisahan kewenangan
 *    (RULE-06). Ini bukan kelalaian, melainkan rancangan.
 *  - Pengawas (BPD/Camat) read-only + ekspor, tanpa satu pun izin tulis.
 *  - Hanya Admin Desa yang punya data_pribadi.lihat_penuh.
 */
final class RoleSeeder extends Seeder
{
    /** @var array<string, array{name: string, description: string, permissions: list<string>}> */
    public const ROLES = [
        Role::ADMIN_DESA => [
            'name' => 'Admin Desa',
            'description' => 'Penanggung jawab data desa (umumnya Sekretaris Desa)',
            'permissions' => ['*'],
        ],
        Role::KEPALA_DESA => [
            'name' => 'Kepala Desa',
            'description' => 'Pimpinan desa: persetujuan & pemantauan',
            'permissions' => [
                'dashboard.lihat', 'laporan.lihat', 'laporan.ekspor',
                'penduduk.lihat', 'penduduk.ekspor', 'keluarga.lihat',
                'mutasi.lihat', 'wilayah.lihat', 'statistik.lihat',
                'surat.lihat', 'surat.setujui', 'surat.ekspor',
                'pengaduan.lihat',
                'portal.lihat', 'portal.terbitkan',
                'keuangan.lihat', 'keuangan.publikasi',
                'aset.lihat', 'bantuan.lihat',
                'pengaturan.lihat', 'audit.lihat',
                'jenis_surat.lihat',
            ],
        ],
        /*
         * KETIGA KASI MEMEGANG `pengaduan.tugaskan`, bukan hanya Admin Desa.
         *
         * Matriks RBAC docs/03 §4.9 memberi Kasi (KK) "R,U,A" atas pengaduan,
         * dan penugasan adalah salah satu bentuk "U" — mengubah pengaduan.
         * Dibatasi ke Admin Desa saja, setiap pelimpahan pekerjaan antar bidang
         * harus menunggu satu akun ber-2FA yang dipegang satu orang, yang justru
         * menjamin SLA FR-PGD-06 terlampaui. Kepala Dusun TIDAK ikut: matriks
         * memberinya "R(wilayah)" — ia membaca pengaduan di dusunnya, tidak
         * menindaklanjutinya, sehingga ia juga tidak dapat menjadi penerima
         * tugas (lihat ComplaintService::assign()).
         */
        Role::KASI_PEMERINTAHAN => [
            'name' => 'Kasi Pemerintahan',
            'description' => 'Pelaksana teknis bidang pemerintahan & kependudukan',
            'permissions' => [
                'dashboard.lihat', 'laporan.lihat', 'laporan.ekspor',
                'penduduk.lihat', 'penduduk.ubah', 'keluarga.lihat', 'keluarga.ubah',
                'mutasi.lihat', 'mutasi.tambah', 'mutasi.ubah', 'wilayah.lihat',
                'statistik.lihat',
                'surat.lihat', 'surat.ajukan', 'surat.ubah', 'surat.verifikasi',
                'pengaduan.lihat', 'pengaduan.tindaklanjut', 'pengaduan.tugaskan',
                'portal.lihat', 'portal.kelola',
            ],
        ],
        Role::KASI_KESEJAHTERAAN => [
            'name' => 'Kasi Kesejahteraan',
            'description' => 'Pelaksana teknis bidang kesejahteraan & bantuan sosial',
            'permissions' => [
                'dashboard.lihat', 'laporan.lihat', 'laporan.ekspor',
                'penduduk.lihat', 'keluarga.lihat', 'wilayah.lihat',
                'surat.lihat', 'surat.ajukan', 'surat.verifikasi',
                'pengaduan.lihat', 'pengaduan.tindaklanjut', 'pengaduan.tugaskan',
                'bantuan.lihat', 'bantuan.kelola', 'bantuan.ekspor',
            ],
        ],
        Role::KASI_PELAYANAN => [
            'name' => 'Kasi Pelayanan',
            'description' => 'Pelaksana teknis bidang pelayanan masyarakat',
            'permissions' => [
                'dashboard.lihat', 'laporan.lihat',
                'penduduk.lihat', 'keluarga.lihat', 'wilayah.lihat',
                'surat.lihat', 'surat.ajukan', 'surat.ubah', 'surat.verifikasi',
                'surat.ekspor',
                'pengaduan.lihat', 'pengaduan.tindaklanjut', 'pengaduan.tugaskan',
                'portal.lihat', 'portal.kelola',
            ],
        ],
        Role::KAUR_KEUANGAN => [
            'name' => 'Kaur Keuangan',
            'description' => 'Urusan keuangan desa',
            'permissions' => [
                'dashboard.lihat', 'laporan.lihat', 'laporan.ekspor',
                'keuangan.lihat', 'keuangan.kelola', 'keuangan.ekspor',
                'aset.lihat', 'aset.kelola', 'aset.ekspor',
                'penduduk.lihat', 'wilayah.lihat',
            ],
        ],
        Role::KAUR_PERENCANAAN => [
            'name' => 'Kaur Perencanaan',
            'description' => 'Urusan perencanaan pembangunan desa',
            'permissions' => [
                'dashboard.lihat', 'laporan.lihat', 'laporan.ekspor',
                'keuangan.lihat', 'aset.lihat',
                'penduduk.lihat', 'wilayah.lihat',
                'pengaduan.lihat',
            ],
        ],
        Role::KEPALA_DUSUN => [
            'name' => 'Kepala Dusun',
            'description' => 'Wilayah: melihat & mengusulkan koreksi data dusunnya',
            'permissions' => [
                'dashboard.lihat',
                'penduduk.lihat', 'penduduk.usul_koreksi',
                'keluarga.lihat', 'mutasi.lihat', 'wilayah.lihat',
                'surat.lihat', 'pengaduan.lihat',
                'bantuan.lihat',
            ],
        ],
        Role::OPERATOR => [
            'name' => 'Operator Desa',
            'description' => 'Entri data harian — TANPA hak hapus & TANPA hak menyetujui (RULE-06)',
            'permissions' => [
                'dashboard.lihat',
                'penduduk.lihat', 'penduduk.tambah', 'penduduk.ubah',
                'keluarga.lihat', 'keluarga.tambah', 'keluarga.ubah',
                'mutasi.lihat', 'mutasi.tambah', 'wilayah.lihat', 'statistik.lihat',
                'surat.lihat', 'surat.ajukan', 'surat.ubah',
                'pengaduan.lihat',
                'portal.lihat', 'portal.kelola',
                'laporan.lihat',
            ],
        ],
        Role::WARGA => [
            'name' => 'Warga',
            'description' => 'Layanan mandiri — hanya data dirinya & satu KK (RULE-11)',
            'permissions' => [
                'penduduk.lihat',   // dibatasi keluarga oleh ResidentRepository
                'keluarga.lihat',
                'surat.lihat', 'surat.ajukan',
                'pengaduan.lihat',
            ],
        ],
        Role::PENGAWAS => [
            'name' => 'Pengawas (BPD/Camat)',
            'description' => 'Pemantauan — hanya baca & ekspor laporan, tanpa hak tulis',
            'permissions' => [
                'dashboard.lihat', 'laporan.lihat', 'laporan.ekspor',
                'penduduk.lihat', 'penduduk.ekspor',
                'keluarga.lihat', 'mutasi.lihat', 'wilayah.lihat', 'statistik.lihat',
                'surat.lihat', 'surat.ekspor',
                'pengaduan.lihat',
                'portal.lihat',
                'keuangan.lihat', 'keuangan.ekspor',
                'aset.lihat', 'aset.ekspor',
                'bantuan.lihat', 'bantuan.ekspor',
                'audit.lihat',
            ],
        ],
    ];

    public function run(): void
    {
        $allPermissions = Permission::query()->pluck('id', 'code');

        foreach (self::ROLES as $code => $definition) {
            $role = Role::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ]
            );

            $codes = $definition['permissions'] === ['*']
                ? $allPermissions->keys()->all()
                : $definition['permissions'];

            $ids = collect($codes)
                ->map(fn (string $c) => $allPermissions[$c] ?? null)
                ->filter()
                ->values()
                ->all();

            $role->permissions()->sync($ids);
        }
    }
}
