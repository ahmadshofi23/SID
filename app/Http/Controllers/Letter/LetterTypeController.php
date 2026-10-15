<?php

declare(strict_types=1);

namespace App\Http\Controllers\Letter;

use App\Http\Controllers\Controller;
use App\Models\LetterType;
use App\Services\Letter\DynamicFormValidator;
use App\Services\Letter\LetterContextBuilder;
use App\Services\Letter\LetterNumberService;
use App\Services\Letter\LetterTemplateRenderer;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jenis surat & template — FR-SRT-01, FR-SRT-02.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class LetterTypeController extends Controller
{
    public function index(LetterNumberService $numbers): Response
    {
        $this->authorize('jenis_surat.lihat');

        $types = LetterType::query()->orderBy('sort_order')->get();

        return Inertia::render('Surat/JenisSurat/Index', [
            'types' => $types->map(fn (LetterType $t): array => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'sla_days' => $t->sla_days,
                'validity_months' => $t->validity_months,
                'step_count' => $t->stepCount(),
                'is_active' => $t->is_active,
                'is_public' => $t->is_public,
                'requires_field_check' => $t->requires_field_check,
                'has_warning' => $t->warning_note !== null,
                'field_count' => count($t->form_schema['fields'] ?? []),
                // Nomor berikutnya, agar petugas melihat deret yang berjalan.
                'next_number' => $numbers->preview($t),
            ])->all(),
            'counts' => [
                'active' => $types->where('is_active', true)->count(),
                'total' => $types->count(),
            ],
        ]);
    }

    public function edit(string $id, DynamicFormValidator $forms): Response
    {
        $this->authorize('jenis_surat.kelola');

        $type = LetterType::query()->findOrFail($id);

        return Inertia::render('Surat/JenisSurat/Form', [
            'type' => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'title_on_letter' => $type->title_on_letter,
                'classification_code' => $type->classification_code,
                'description' => $type->description,
                'number_pattern' => $type->number_pattern,
                'sla_days' => $type->sla_days,
                'validity_months' => $type->validity_months,
                'fee' => (float) $type->fee,
                'is_active' => $type->is_active,
                'is_public' => $type->is_public,
                'requires_field_check' => $type->requires_field_check,
                'warning_note' => $type->warning_note,
                'template_html' => $type->template_html,
                'requirements' => $type->requirements,
                'approval_flow' => $type->approval_flow,
                'form_fields' => $forms->fields($type),
            ],
            'available_variables' => $this->variableCatalog($forms->fieldNames($type)),
        ]);
    }

    public function update(
        Request $request,
        string $id,
        DynamicFormValidator $forms,
        LetterTemplateRenderer $renderer,
    ): RedirectResponse {
        $this->authorize('jenis_surat.kelola');

        $type = LetterType::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'title_on_letter' => ['required', 'string', 'max:150'],
            'classification_code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'number_pattern' => ['required', 'string', 'max:150'],
            'sla_days' => ['required', 'integer', 'min:1', 'max:365'],
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'fee' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'is_public' => ['required', 'boolean'],
            'requires_field_check' => ['required', 'boolean'],
            'warning_note' => ['nullable', 'string', 'max:1000'],
            'template_html' => ['required', 'string', 'max:65000'],
        ]);

        /*
         * Pola nomor WAJIB memuat {urut}.
         *
         * Pola tanpa nomor urut akan menghasilkan nomor yang sama untuk
         * setiap surat, dan indeks unik pada `letters.letter_number` akan
         * menolak surat kedua — layanan surat berhenti total pada hari itu,
         * dengan galat basis data yang tidak dapat dipahami petugas.
         */
        if (! str_contains($validated['number_pattern'], '{urut}')
            && ! preg_match('/\{urut:\d\}/', $validated['number_pattern'])) {
            return back()->withErrors([
                'number_pattern' => 'Pola nomor wajib memuat {urut} atau {urut:3}. '
                    .'Tanpa nomor urut, surat kedua dan seterusnya tidak dapat diterbitkan.',
            ])->withInput();
        }

        /*
         * Template ditolak bila memuat variabel tak dikenal — FR-SRT-02.
         *
         * Perender memang mengosongkan variabel asing sebagai jaring
         * pengaman, tetapi ruang kosong di surat resmi juga cacat. Lebih
         * baik petugas mengetahuinya di editor, saat masih dapat diperbaiki.
         */
        $context = LetterContextBuilder::sampleContext($forms->fields($type));
        $unknown = $renderer->unknownVariables(
            $validated['template_html'],
            $context,
            $forms->fieldNames($type),
        );

        if ($unknown !== []) {
            return back()->withErrors([
                'template_html' => 'Variabel tidak dikenal: {{'
                    .implode('}}, {{', $unknown).'}}. '
                    .'Periksa ejaannya pada daftar variabel di samping editor.',
            ])->withInput();
        }

        /*
         * Template DIBERSIHKAN saat disimpan, bukan hanya saat dirender.
         *
         * Menyimpan bentuk bersih berarti apa yang dilihat petugas di editor
         * saat membuka kembali adalah apa yang benar-benar akan tercetak.
         * Kalau hanya dibersihkan saat render, editor terus menampilkan tag
         * yang sebenarnya diabaikan, dan petugas mengira ia berfungsi.
         */
        $sanitizer = app(HtmlSanitizer::class);
        $altered = $sanitizer->wouldChange($validated['template_html']);
        $validated['template_html'] = $sanitizer->clean($validated['template_html']);

        $type->fill([...$validated, 'updated_by' => $request->user()?->id]);
        $type->save();

        return to_route('jenis-surat.index')->with(
            $altered ? 'warning' : 'success',
            $altered
                ? 'Jenis surat '.$type->code.' diperbarui. Sebagian tag HTML dibuang '
                    .'karena tidak diizinkan pada template surat (mis. gaya dari Word, '
                    .'gambar, atau skrip). Periksa hasilnya dengan tombol Pratinjau.'
                : 'Jenis surat '.$type->code.' diperbarui.',
        );
    }

    /**
     * Ubah status aktif. Dipisah dari update() karena ini satu-satunya
     * tindakan yang sering dipakai dan tidak menyentuh template.
     */
    public function toggle(Request $request, string $id): RedirectResponse
    {
        $this->authorize('jenis_surat.kelola');

        $type = LetterType::query()->findOrFail($id);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $type->is_active = $validated['is_active'];
        $type->updated_by = $request->user()?->id;
        $type->save();

        return back()->with(
            'success',
            'Jenis surat '.$type->code.($type->is_active ? ' diaktifkan.' : ' dinonaktifkan.'),
        );
    }

    /** Pratinjau template dengan data contoh — tanpa menyentuh data nyata. */
    public function preview(
        Request $request,
        LetterTemplateRenderer $renderer,
        DynamicFormValidator $forms,
    ): JsonResponse {
        $this->authorize('jenis_surat.kelola');

        $validated = $request->validate([
            'template_html' => ['required', 'string', 'max:65000'],
            'letter_type_id' => ['nullable', 'uuid', 'exists:letter_types,id'],
        ]);

        /*
         * Jenis surat dikirim agar isian dinamisnya ikut terisi contoh.
         * Pratinjau dengan isian kosong menghasilkan kalimat terpotong
         * ("untuk keperluan: ."), dan petugas tidak dapat menilai redaksinya.
         */
        $type = isset($validated['letter_type_id'])
            ? LetterType::query()->find($validated['letter_type_id'])
            : null;

        $context = LetterContextBuilder::sampleContext(
            $type === null ? [] : $forms->fields($type)
        );

        return response()->json([
            'html' => $renderer->render($validated['template_html'], $context),
        ]);
    }

    /**
     * Daftar variabel yang boleh dipakai, untuk panel bantuan di editor.
     *
     * @param  array<int, string>  $formFields
     * @return array<string, array<int, string>>
     */
    private function variableCatalog(array $formFields): array
    {
        return [
            'Data pemohon' => [
                'nama', 'nik', 'nomor_kk', 'tempat_lahir', 'tanggal_lahir',
                'tempat_tanggal_lahir', 'umur', 'jenis_kelamin', 'agama',
                'status_perkawinan', 'pekerjaan', 'pendidikan', 'kewarganegaraan',
                'alamat', 'alamat_lengkap', 'rt', 'rw', 'dusun',
                'nama_ayah', 'nama_ibu',
            ],
            'Data desa' => [
                'desa', 'kecamatan', 'kabupaten', 'provinsi', 'kode_desa',
                'alamat_kantor', 'kode_pos', 'telepon_desa', 'email_desa', 'website_desa',
            ],
            'Data surat' => [
                'nomor_surat', 'judul_surat', 'keperluan', 'tanggal_terbit',
                'tanggal_terbit_panjang', 'tempat_tanggal_terbit', 'masa_berlaku',
                'kode_verifikasi', 'nomor_tiket',
            ],
            'Penanda tangan' => [
                'penandatangan_nama', 'penandatangan_jabatan', 'penandatangan_nip',
            ],
            'Isian formulir' => array_map(fn (string $f): string => 'form.'.$f, $formFields),
            'Pemformat' => [
                'upper', 'lower', 'kapital', 'rupiah', 'angka_huruf', 'tanggal_panjang',
            ],
        ];
    }
}
