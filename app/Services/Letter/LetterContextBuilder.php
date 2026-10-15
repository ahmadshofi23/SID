<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Models\LetterRequest;
use App\Models\Official;
use App\Models\Resident;
use App\Models\VillageProfile;
use App\Repositories\ResidentRepository;
use App\Support\ReferenceData;
use App\Support\VillageTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Menyusun konteks variabel template dari data penduduk & desa — docs/13 §2.
 *
 * KETERBUKAAN NIK: `{{nik}}` memang tersedia, karena beberapa surat memang
 * wajib memuat NIK lengkap (surat keterangan waris, surat kuasa) — itu
 * tuntutan dokumen, bukan kebocoran. Yang kami jaga adalah pencatatannya:
 * pemanggil WAJIB mencatat pengungkapan ini ke audit log (RULE-07), dan
 * pratinjau di layar memakai nik_masked.
 */
final class LetterContextBuilder
{
    public function __construct(
        private readonly DynamicFormValidator $forms,
        private readonly ResidentRepository $residents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(
        LetterRequest $request,
        Official $signer,
        Carbon $issuedAt,
        string $letterNumber,
        string $verificationCode,
        bool $revealNik = true,
    ): array {
        $resident = $request->resident;
        $village = VillageProfile::current();
        $type = $request->letterType;

        /*
         * Seluruh tanggal yang TERCETAK memakai kalender desa.
         *
         * $issuedAt berada di UTC (config/app.php). Surat yang terbit
         * 1 Januari pukul 02.00 WIB akan tercetak bertanggal
         * "31 Desember" bila dirender langsung dari UTC — bertentangan
         * dengan nomor suratnya sendiri, yang memakai kalender desa.
         */
        $local = VillageTime::local($issuedAt);

        $validUntil = $type->validity_months !== null
            ? $local->copy()->addMonths($type->validity_months)
            : null;

        return [
            ...$this->residentVariables($resident, $revealNik),
            ...$this->villageVariables($village),

            // Surat
            'nomor_surat' => $letterNumber,
            'judul_surat' => $type->title_on_letter,
            'keperluan' => $request->purpose ?? '',
            'tanggal_terbit' => $local->format('d-m-Y'),
            'tanggal_terbit_panjang' => LetterTemplateRenderer::longDate($local),
            'tempat_tanggal_terbit' => $village->name.', '.LetterTemplateRenderer::longDate($local),
            'masa_berlaku' => $validUntil !== null
                ? LetterTemplateRenderer::longDate($validUntil)
                : 'sampai dengan dicabut',
            'kode_verifikasi' => $verificationCode,
            'qr_verifikasi' => route('surat.verifikasi', ['kode' => $verificationCode]),
            'nomor_tiket' => $request->ticket_number,

            // Penanda tangan
            'penandatangan_nama' => $signer->name,
            'penandatangan_jabatan' => $signer->position,
            'penandatangan_nip' => $signer->nip ?? '',

            // Isian dinamis
            'form' => $this->formVariables($request),

            // Penduduk kedua, mis. almarhum pada SKM (docs/13 §5.5)
            ...$this->secondaryResidentVariables($request, $revealNik),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function residentVariables(Resident $resident, bool $revealNik): array
    {
        $resident->loadMissing(['family', 'region.parent.parent']);
        $family = $resident->family;
        $refs = [
            'religion' => ReferenceData::get('religions'),
            'marital' => ReferenceData::get('marital_statuses'),
            'education' => ReferenceData::get('educations'),
            'occupation' => ReferenceData::get('occupations'),
        ];

        $address = $resident->address ?: ($family->address ?? '');
        $rt = $family->rt ?? '';
        $rw = $family->rw ?? '';
        $dusun = $this->dusunName($resident);

        /*
         * Alamat lengkap dirakit dari bagian yang TERSEDIA.
         *
         * Data penduduk hasil impor sering tidak lengkap. Merangkai dengan
         * penggabungan lurus menghasilkan "Jl. Melati, RT , RW , Dusun"
         * pada surat resmi — cacat yang langsung terlihat warga.
         */
        $parts = array_filter([
            $address,
            $rt !== '' ? 'RT '.$rt : null,
            $rw !== '' ? 'RW '.$rw : null,
            $dusun !== '' ? self::withPrefix('Dusun', $dusun) : null,
        ]);

        return [
            'nama' => $resident->full_name,
            'nik' => $revealNik ? ($resident->revealNik() ?? '') : $resident->nik_masked,
            'nomor_kk' => $family !== null
                ? ($revealNik ? ($family->revealKkNumber() ?? '') : $family->kk_number_masked)
                : '',
            'tempat_lahir' => $resident->birth_place ?? '',
            'tanggal_lahir' => LetterTemplateRenderer::longDate($resident->birth_date),
            'tempat_tanggal_lahir' => trim(
                ($resident->birth_place ?? '').', '.LetterTemplateRenderer::longDate($resident->birth_date),
                ', '
            ),
            'umur' => (string) $resident->age(),
            'jenis_kelamin' => $resident->gender === 'L' ? 'Laki-laki' : 'Perempuan',
            'agama' => $refs['religion'][$resident->religion_code] ?? '',
            'status_perkawinan' => $refs['marital'][$resident->marital_status_code] ?? '',
            'pekerjaan' => $refs['occupation'][$resident->occupation_code] ?? '',
            'pendidikan' => $refs['education'][$resident->education_code] ?? '',
            'kewarganegaraan' => $resident->citizenship === 'WNA' ? 'Asing' : 'Indonesia',
            'alamat' => $address,
            'alamat_lengkap' => implode(', ', $parts),
            'rt' => $rt,
            'rw' => $rw,
            'dusun' => $dusun,
            'nama_ayah' => $resident->father_name ?? '',
            'nama_ibu' => $resident->mother_name ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function villageVariables(VillageProfile $village): array
    {
        return [
            'desa' => $village->name,
            'kecamatan' => $village->district,
            'kabupaten' => $village->regency,
            'provinsi' => $village->province,
            'kode_desa' => $village->code,
            'alamat_kantor' => $village->address ?? '',
            'kode_pos' => $village->postal_code ?? '',
            'telepon_desa' => $village->phone ?? '',
            'email_desa' => $village->email ?? '',
            'website_desa' => $village->website ?? '',
        ];
    }

    /**
     * Isian dinamis, ditambah label pilihan agar template dapat menampilkan
     * teks yang dibaca manusia, bukan kodenya.
     *
     * @return array<string, mixed>
     */
    private function formVariables(LetterRequest $request): array
    {
        $values = $request->form_data;

        foreach ($this->forms->fields($request->letterType) as $field) {
            $name = (string) $field['name'];

            if (($field['type'] ?? '') === 'boolean' && array_key_exists($name, $values)) {
                $values[$name] = $values[$name] ? 'Ya' : 'Tidak';
            }

            if (($field['type'] ?? '') === 'date' && ! empty($values[$name])) {
                $values[$name] = LetterTemplateRenderer::longDate((string) $values[$name]);
            }

            /*
             * Pilihan ganda menjadi satu kalimat.
             *
             * Tanpa ini, perender menerima array dan — karena ia hanya
             * menuliskan nilai skalar — mengosongkannya, sehingga daftar
             * anggota keluarga yang pindah hilang dari surat tanpa jejak.
             */
            if (($field['type'] ?? '') === 'multiselect' && is_array($values[$name] ?? null)) {
                $values[$name] = implode(', ', array_map('strval', $values[$name]));
            }
        }

        return $values;
    }

    /**
     * Konteks contoh untuk pratinjau & validasi template.
     *
     * TINGGAL DI SINI, bukan di controller, karena daftar kuncinya HARUS
     * sama dengan yang dihasilkan build(). Editor template menolak simpan
     * bila ada variabel di luar daftar ini (FR-SRT-02); bila keduanya
     * menyimpang, variabel baru yang sah akan ditolak editor dan petugas
     * tidak punya cara mengetahui penyebabnya.
     *
     * Memakai data FIKTIF, bukan penduduk pertama di basis data: editor
     * template dibuka berulang kali dan tidak boleh menjadi jalan melihat
     * data warga tanpa jejak audit.
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array<int, array<string, mixed>>  $formFields  dari form_schema
     * @return array<string, mixed>
     */
    public static function sampleContext(array $formFields = []): array
    {
        $village = VillageProfile::current();

        return [
            'nama' => 'SITI AMINAH',
            'nik' => '3201234567890123',
            'nomor_kk' => '3201234567890001',
            'tempat_lahir' => 'Sukamaju',
            'tanggal_lahir' => '17 Maret 1985',
            'tempat_tanggal_lahir' => 'Sukamaju, 17 Maret 1985',
            'umur' => '41',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Islam',
            'status_perkawinan' => 'Kawin Tercatat',
            'pekerjaan' => 'Petani',
            'pendidikan' => 'SLTP/Sederajat',
            'kewarganegaraan' => 'Indonesia',
            'alamat' => 'Jl. Melati No. 12',
            'alamat_lengkap' => 'Jl. Melati No. 12, RT 003, RW 002, Dusun Krajan',
            'rt' => '003',
            'rw' => '002',
            'dusun' => 'Krajan',
            'nama_ayah' => 'AHMAD HIDAYAT',
            'nama_ibu' => 'SUMIYATI',
            'desa' => $village->name,
            'kecamatan' => $village->district,
            'kabupaten' => $village->regency,
            'provinsi' => $village->province,
            'kode_desa' => $village->code,
            'alamat_kantor' => $village->address ?? '',
            'kode_pos' => $village->postal_code ?? '',
            'telepon_desa' => $village->phone ?? '',
            'email_desa' => $village->email ?? '',
            'website_desa' => $village->website ?? '',
            'nomor_surat' => '145/SKTM/'.$village->code.'/X/2026',
            'judul_surat' => 'SURAT KETERANGAN',
            'keperluan' => 'pengajuan beasiswa',
            'tanggal_terbit' => '09-10-2026',
            'tanggal_terbit_panjang' => '9 Oktober 2026',
            'tempat_tanggal_terbit' => $village->name.', 9 Oktober 2026',
            'masa_berlaku' => '9 Januari 2027',
            'kode_verifikasi' => 'A3C7KMNP4RQD69FGHJTU7WXY34AC',
            'qr_verifikasi' => url('/surat/verifikasi/CONTOH'),
            'nomor_tiket' => 'T261009-AB3K7',
            'penandatangan_nama' => 'BUDI SANTOSO',
            'penandatangan_jabatan' => 'Kepala Desa',
            'penandatangan_nip' => '',
            'form' => self::sampleFormValues($formFields),
            // Penduduk kedua (mis. almarhum pada SKM) — lihat LetterContextBuilder.
            'meninggal' => [
                'nama' => 'SUPARMAN',
                'nik' => '3201234567890777',
                'tempat_tanggal_lahir' => 'Sukamaju, 2 Januari 1950',
                'alamat_lengkap' => 'Jl. Melati No. 12, RT 003, RW 002, Dusun Krajan',
            ],
        ];
    }

    /**
     * Nilai contoh untuk setiap isian dinamis.
     *
     * Tanpa ini, pratinjau SKTM berbunyi "untuk keperluan: ." — kalimat
     * yang terpotong. Petugas yang sedang menyunting redaksi tidak dapat
     * menilai hasilnya, dan wajar menyimpulkan templatnya rusak.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, string>
     */
    private static function sampleFormValues(array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $options = array_map('strval', (array) ($field['options'] ?? []));

            $values[$name] = match ($field['type'] ?? 'text') {
                // Pilihan memakai opsi PERTAMA yang didefinisikan desa,
                // bukan teks karangan: itulah yang akan benar-benar tercetak.
                'select', 'multiselect' => $options[0] ?? '(pilihan)',
                'number' => '2',
                'currency' => '2500000',
                'date' => '1 Oktober 2026',
                'time' => '08.30',
                'boolean' => 'Ya',
                'nik' => '3201234567890123',
                'resident' => 'SUPARMAN',
                'textarea' => '(keterangan tambahan contoh)',
                default => '(contoh '.Str::lower((string) ($field['label'] ?? $name)).')',
            };
        }

        return $values;
    }

    /**
     * Variabel untuk penduduk kedua yang disebut surat.
     *
     * SKM menerangkan almarhum, bukan pelapor yang mengajukan; templatnya
     * memakai prefiks `meninggal.`. Field bertipe `resident` di form_schema
     * yang namanya berpola `resident_<prefiks>_id` dipetakan ke prefiks itu.
     *
     * Pencariannya lewat repository BERLINGKUP: bila penduduk tersebut di
     * luar wilayah petugas, variabelnya kosong dan kekosongan itu terlihat
     * di pratinjau — lebih baik daripada surat yang memuat data penduduk
     * yang tidak boleh dilihat petugas tersebut.
     *
     * @return array<string, mixed>
     */
    private function secondaryResidentVariables(LetterRequest $request, bool $revealNik): array
    {
        $variables = [];

        foreach ($this->forms->fields($request->letterType) as $field) {
            if (($field['type'] ?? '') !== 'resident') {
                continue;
            }

            $name = (string) $field['name'];

            if (! preg_match('/^resident_(.+)_id$/', $name, $m)) {
                continue;
            }

            $id = $request->form_data[$name] ?? null;

            if (! is_string($id) || $id === '') {
                continue;
            }

            $other = $this->residents->find($id);

            if ($other !== null) {
                $variables[$m[1]] = $this->residentVariables($other, $revealNik);
            }
        }

        return $variables;
    }

    /**
     * Tambahkan awalan hanya bila nama belum memuatnya.
     *
     * Desa memberi nama wilayahnya dengan cara berbeda-beda: sebagian
     * menyimpan "Krajan", sebagian "Dusun Krajan". Menambahkan awalan tanpa
     * memeriksa menghasilkan "Dusun Dusun Krajan" tercetak pada surat resmi —
     * cacat yang langsung terlihat warga dan tidak tertangkap pengujian,
     * karena datanya memang valid.
     */
    private static function withPrefix(string $prefix, string $name): string
    {
        return Str::startsWith(Str::lower($name), Str::lower($prefix).' ')
            ? $name
            : $prefix.' '.$name;
    }

    /** Nama dusun dari rantai wilayah, jika ada. */
    private function dusunName(Resident $resident): string
    {
        $region = $resident->region;

        while ($region !== null) {
            if ($region->level === 'dusun') {
                return $region->name;
            }

            $region = $region->parent;
        }

        return '';
    }

    /**
     * Snapshot untuk disimpan permanen bersama surat — ADR-003.
     *
     * Disimpan TANPA NIK lengkap. Snapshot ada agar surat lama tetap
     * menampilkan data saat terbit, bukan agar NIK tersalin ke tabel kedua
     * dalam bentuk polos; NIK lengkapnya sudah tertanam di `rendered_html`
     * bila templatnya memang memuatnya.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function toSnapshot(array $context, LetterRequest $request): array
    {
        $snapshot = $context;
        unset($snapshot['qr_verifikasi']);

        $family = $request->resident->family;

        $snapshot['nik'] = $request->resident->nik_masked;
        $snapshot['nomor_kk'] = $family === null ? '' : $family->kk_number_masked;

        return $snapshot;
    }
}
