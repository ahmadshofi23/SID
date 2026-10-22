<?php

declare(strict_types=1);

namespace App\Http\Requests\Complaint;

use App\Support\ReferenceData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi formulir pengaduan publik — FR-PGD-01, FR-PGD-08.
 *
 * Penyaringan isi ada DI SINI dan bukan di layanan karena ia tentang bentuk
 * masukan, bukan tentang aturan bisnis: batas panjang, kategori yang dikenali,
 * dan koordinat yang masih di bumi. Aturan yang menyangkut keputusan desa
 * (SLA, RULE-09, pembatasan laju per IP) ada di ComplaintService.
 */
final class StoreComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Terbuka untuk SEMUA, termasuk tamu tanpa akun. Matriks RBAC
         * docs/03 §4.9 memberi PB (publik) "C(anonim)" atas pengaduan: hak
         * mengadu tidak boleh bergantung pada punya tidaknya akun, karena
         * orang yang paling perlu mengadu adalah orang yang paling tidak
         * terdaftar di mana pun.
         */
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_code' => [
                'required', 'string',
                /*
                 * Kategori dibatasi pada tabel referensi, bukan diterima apa
                 * adanya: kode yang tidak dikenali akan membuat pengaduan
                 * tidak pernah muncul di penyaringan mana pun — hilang tanpa
                 * ada yang tahu, yang lebih buruk daripada ditolak.
                 */
                Rule::in(array_keys(ReferenceData::get('complaint_categories'))),
            ],
            'title' => ['required', 'string', 'min:10', 'max:200'],
            'body' => [
                'required', 'string', 'min:30',
                'max:'.(int) config('sid.complaints.body_max_length', 5000),
            ],
            'is_anonymous' => ['sometimes', 'boolean'],

            /*
             * Nama & telepon OPSIONAL bahkan pada pengaduan bernama: warga
             * yang sudah masuk sebagai akunnya tidak perlu menulis ulang
             * namanya, dan warga tanpa akun boleh melapor tanpa nomor telepon.
             * Keduanya DIBUANG seluruhnya bila anonim — penegakannya di
             * ComplaintService, bukan di sini, agar tidak ada jalur yang
             * melewatinya.
             */
            'reporter_name' => ['nullable', 'string', 'max:150'],
            'reporter_phone' => ['nullable', 'string', 'max:30'],

            'region_id' => ['nullable', 'uuid', 'exists:regions,id'],
            'location_text' => ['nullable', 'string', 'max:255'],
            // Rentang koordinat Indonesia, dilonggarkan ke batas bumi: titik
            // yang jelas mustahil biasanya hasil salah tempel, bukan laporan.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category_code.required' => 'Pilih kategori pengaduan.',
            'category_code.in' => 'Kategori yang dipilih tidak dikenali.',
            'title.required' => 'Judul pengaduan wajib diisi.',
            /*
             * Batas MINIMUM dijelaskan sebagai permintaan, bukan sebagai
             * aturan: "Jalan rusak" tanpa keterangan apa pun menghasilkan
             * pengaduan yang tidak dapat ditindaklanjuti dan harus
             * dikembalikan — menghabiskan waktu warga dua kali.
             */
            'title.min' => 'Judul terlalu singkat. Sebutkan apa dan di mana, '
                .'misalnya "Lampu jalan mati di RT 002".',
            'title.max' => 'Judul maksimal 200 karakter. Keterangan panjangnya '
                .'tuliskan di kolom isi.',
            'body.required' => 'Isi pengaduan wajib diisi.',
            'body.min' => 'Ceritakan sedikit lebih rinci — sejak kapan, di mana '
                .'tepatnya, dan apa dampaknya. Petugas memerlukannya untuk menindaklanjuti.',
            'body.max' => 'Isi pengaduan terlalu panjang. Ringkas ke pokok persoalannya.',
            'region_id.exists' => 'Wilayah yang dipilih tidak dikenali.',
        ];
    }
}
