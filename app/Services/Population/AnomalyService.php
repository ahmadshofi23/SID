<?php

declare(strict_types=1);

namespace App\Services\Population;

use App\Repositories\ResidentRepository;

/**
 * Laporan anomali data — FR-PDK-13, BR-06.
 *
 * Perannya di proyek ini lebih besar daripada kelihatannya. Karena MySQL tidak
 * punya pencarian toleran salah ketik (KEP-002), petugas yang mengetik nama
 * sedikit berbeda bisa mengira warganya belum terdaftar lalu memasukkannya
 * lagi — risiko R-19. Laporan inilah jaring pengaman utamanya.
 *
 * Seluruh pemeriksaan MENGHORMATI lingkup akses: Kepala Dusun hanya melihat
 * anomali di dusunnya.
 */
final class AnomalyService
{
    public function __construct(private readonly ResidentRepository $residents) {}

    /**
     * @return array<string, array{
     *     label: string, severity: string, description: string,
     *     count: int, items: list<array<string, mixed>>
     * }>
     */
    public function report(int $limitPerCheck = 50): array
    {
        $checks = [
            'nik_duplikat' => [
                'label' => 'NIK terdaftar lebih dari sekali',
                'severity' => 'tinggi',
                'description' => 'Dua penduduk aktif memakai NIK yang sama. '
                    .'Biasanya hasil entri ganda. Satu di antaranya perlu dihapus '
                    .'setelah dipastikan mana yang benar.',
                'items' => $this->residents->duplicateNikGroups($limitPerCheck),
            ],
            'nama_mirip' => [
                'label' => 'Nama sangat mirip dalam satu wilayah',
                'severity' => 'sedang',
                'description' => 'Kemungkinan orang yang sama dimasukkan dua kali '
                    .'dengan ejaan berbeda. Periksa tanggal lahirnya untuk memastikan.',
                'items' => $this->residents->similarNames($limitPerCheck),
            ],
            'kk_tanpa_kepala' => [
                'label' => 'Kartu keluarga tanpa kepala keluarga',
                'severity' => 'sedang',
                'description' => 'Melanggar RULE-02. Biasanya terjadi setelah kepala '
                    .'keluarga meninggal atau pindah dan penggantinya belum ditetapkan.',
                'items' => $this->residents->familiesWithoutHead($limitPerCheck),
            ],
            'tanpa_wilayah' => [
                'label' => 'Penduduk tanpa wilayah (RT)',
                'severity' => 'sedang',
                'description' => 'Penduduk ini tidak terlihat oleh Kepala Dusun mana pun '
                    .'dan tidak masuk statistik per wilayah. Umumnya berasal dari impor '
                    .'yang kode RT-nya tidak dikenali.',
                'items' => $this->residents->withoutRegion($limitPerCheck),
            ],
            'usia_tidak_wajar' => [
                'label' => 'Usia tidak wajar',
                'severity' => 'rendah',
                'description' => 'Usia di atas 110 tahun. Hampir selalu salah ketik tahun lahir.',
                'items' => $this->residents->implausibleAges(110, $limitPerCheck),
            ],
            'tanpa_kk' => [
                'label' => 'Penduduk belum tergabung kartu keluarga',
                'severity' => 'rendah',
                'description' => 'Belum tentu salah — pendatang baru sering belum punya KK. '
                    .'Perlu diperiksa agar tidak tertinggal saat pendataan.',
                'items' => $this->residents->withoutFamily($limitPerCheck),
            ],
        ];

        // `count` ditambahkan di satu tempat agar tidak ditulis ulang
        // (dan berpotensi meleset) pada setiap pemeriksaan.
        return array_map(
            fn (array $check): array => [...$check, 'count' => count($check['items'])],
            $checks,
        );
    }

    /**
     * Ringkasan untuk ditampilkan di dashboard.
     *
     * @return array{total: int, tinggi: int, sedang: int, rendah: int}
     */
    public function summary(): array
    {
        $report = $this->report(limitPerCheck: 1000);

        $counts = ['total' => 0, 'tinggi' => 0, 'sedang' => 0, 'rendah' => 0];

        foreach ($report as $check) {
            $n = count($check['items']);
            $counts['total'] += $n;
            $counts[$check['severity']] += $n;
        }

        return $counts;
    }
}
