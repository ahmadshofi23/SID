<?php

declare(strict_types=1);

namespace App\Http\Controllers\Letter;

use App\Http\Controllers\Controller;
use App\Models\ResidentMutation;
use App\Repositories\LetterRequestRepository;
use App\Repositories\ResidentRepository;
use App\Rules\NikRule;
use App\Services\Letter\MutationOfferService;
use App\Support\Pii\Nik;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tawaran mutasi dari surat SKL/SKM — docs/13 §5.4 & §5.5, KEP-015.
 *
 * Tanpa konstruktor (KEP-009): repository dan layanan berlingkup WAJIB lewat
 * injeksi method, karena Route menyimpan instance controller selama proses
 * hidup.
 *
 * Seluruh aksi di sini bertolak dari PENGAJUAN, bukan dari id tawaran: tawaran
 * tidak punya lingkup wilayahnya sendiri, dan satu-satunya pemeriksaan yang
 * sah adalah LetterRequestRepository::findOrFail() — yang mengembalikan 404
 * untuk pengajuan di luar lingkup pengguna, bukan 403 (docs/06 §4).
 */
final class MutationOfferController extends Controller
{
    /**
     * Formulir pendaftaran bayi, terisi dari formulir surat — FR-PDK-01.
     *
     * Ada layar tersendiri hanya untuk kelahiran. Tawaran kematian tidak
     * memerlukannya: seluruh datanya sudah ada di formulir SKM dan sudah
     * tercetak di surat, sehingga cukup satu tombol. Bayi baru lahir BELUM
     * punya NIK — dan NIK itulah yang justru diurus dengan surat ini — jadi
     * petugas harus melengkapinya sendiri (KEP-015).
     */
    public function create(
        string $id,
        LetterRequestRepository $requests,
    ): Response|RedirectResponse {
        $this->authorize('mutasi.tambah');
        $this->authorize('penduduk.tambah');

        $request = $requests->findOrFail($id);
        $request->load(['letterType', 'resident.family', 'letter', 'mutationOffer']);

        $offer = $request->mutationOffer;

        abort_if($offer === null, 404);
        abort_unless($offer->mutation_type === ResidentMutation::TYPE_BIRTH, 404);

        /*
         * Tawaran yang sudah diputuskan: kembalikan ke layar pengajuan, yang
         * sudah menampilkan hasilnya. Bukan 404 — tautannya memang pernah
         * sah, dan petugas yang menekan tombol dua kali dari dua tab pantas
         * mendapat penjelasan, bukan halaman tidak ditemukan.
         */
        if (! $offer->isPending()) {
            return to_route('surat.show', $request->id)
                ->with('warning', 'Tawaran mutasi pada pengajuan ini sudah diputuskan.');
        }

        $form = $request->form_data;

        return Inertia::render('Surat/TawaranMutasi/Kelahiran', [
            'request' => [
                'id' => $request->id,
                'ticket_number' => $request->ticket_number,
                'letter_number' => $request->letter?->letter_number,
                'applicant_name' => $request->resident->full_name,
                /*
                 * Nomor KK TERSAMAR. Petugas perlu memastikan bayi masuk ke
                 * kartu keluarga yang benar, dan untuk itu empat angka
                 * terakhir sudah cukup — nomor penuh hanya boleh dibuka lewat
                 * rute beraudit tersendiri (RULE-07).
                 */
                'family_kk_masked' => $request->resident->family?->kk_number_masked,
                'family_address' => $request->resident->family?->address,
            ],
            /*
             * Nilai awal dari isian surat, dikirim sebagai payload TERPISAH
             * dari `request` agar jelas mana yang disalin dari surat dan mana
             * yang menjadi milik formulir ini.
             */
            'prefill' => [
                'full_name' => self::stringOf($form['nama_anak'] ?? null),
                'gender_label' => self::stringOf($form['jenis_kelamin_anak'] ?? null),
                'birth_place' => self::stringOf($form['tempat_lahir_anak'] ?? null),
                'birth_date' => self::stringOf($form['tanggal_lahir_anak'] ?? null),
                'father_name' => self::stringOf($form['nama_ayah'] ?? null),
                'mother_name' => self::stringOf($form['nama_ibu'] ?? null),
                'birth_weight' => self::stringOf($form['berat_badan'] ?? null),
                'birth_length' => self::stringOf($form['panjang_badan'] ?? null),
                'birth_helper' => self::stringOf($form['penolong_kelahiran'] ?? null),
            ],
        ]);
    }

    /** Terima tawaran — di sinilah status penduduk benar-benar berubah. */
    public function store(
        Request $httpRequest,
        string $id,
        LetterRequestRepository $requests,
        ResidentRepository $residents,
        MutationOfferService $offers,
    ): RedirectResponse {
        $this->authorize('mutasi.tambah');

        $request = $requests->findOrFail($id);
        $request->load(['letterType', 'resident', 'letter', 'mutationOffer']);

        $offer = $request->mutationOffer;

        abort_if($offer === null, 404);

        if ($offer->mutation_type === ResidentMutation::TYPE_DEATH) {
            $mutation = $offers->acceptDeath($offer, $request);
            $deceased = $residents->find((string) $mutation->resident_id);

            return to_route('surat.show', $request->id)->with(
                'success',
                'Mutasi kematian '.($deceased === null ? 'penduduk' : $deceased->full_name)
                    .' dicatat. Statusnya kini "meninggal".'
            );
        }

        $this->authorize('penduduk.tambah');

        /*
         * NIK dinormalkan SEBELUM divalidasi, sama seperti pada
         * StoreResidentRequest: petugas mengetiknya dari kartu dengan spasi
         * atau tanda hubung, dan NikRule hanya mengenal 16 angka rapat.
         */
        $httpRequest->merge([
            'nik' => Nik::normalize((string) $httpRequest->input('nik', '')),
            'full_name' => trim((string) $httpRequest->input('full_name', '')),
        ]);

        $validated = $httpRequest->validate([
            'nik' => ['required', 'string', new NikRule],
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
        ], attributes: [
            'nik' => 'NIK anak',
            'full_name' => 'nama anak',
        ]);

        $mutation = $offers->acceptBirth($offer, $request, $validated);

        return to_route('penduduk.show', $mutation->resident_id)->with(
            'success',
            $validated['full_name'].' ditambahkan ke kartu keluarga '
                .$request->resident->full_name.' dan mutasi kelahirannya dicatat.'
        );
    }

    /**
     * Tolak tawaran — permanen, dan tidak menyentuh surat sama sekali.
     *
     * Dibuat sebagai aksi tersendiri, bukan sekadar menutup panel di layar:
     * tawaran yang hanya disembunyikan di peramban akan muncul kembali pada
     * petugas berikutnya yang membuka pengajuan yang sama, dan tidak ada
     * jejak bahwa keputusan itu pernah diambil.
     */
    public function decline(
        Request $httpRequest,
        string $id,
        LetterRequestRepository $requests,
        MutationOfferService $offers,
    ): RedirectResponse {
        $this->authorize('mutasi.tambah');

        $request = $requests->findOrFail($id);
        $request->load('mutationOffer');

        $offer = $request->mutationOffer;

        abort_if($offer === null, 404);

        $validated = $httpRequest->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $label = $offer->mutationLabel();
        $offers->decline($offer, $validated['reason'] ?? null);

        return back()->with(
            'success',
            'Tawaran pencatatan mutasi '.mb_strtolower($label).' ditolak. '
                .'Penerbitan surat tidak terpengaruh.'
        );
    }

    /**
     * Isian dinamis bertipe apa pun → teks untuk nilai awal formulir.
     *
     * Angka (berat & panjang badan) tersimpan sebagai angka di `form_data`,
     * sementara TextField di Vue hanya menerima string; mengirim angka
     * mentah membuat vue-tsc menolaknya dan membuat isian tampak kosong.
     */
    private static function stringOf(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }
}
