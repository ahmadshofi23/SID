<?php

declare(strict_types=1);

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessResidentImport;
use App\Models\ImportJob;
use App\Services\Import\ResidentImportSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Impor massal penduduk — FR-PDK-11, UC-04.
 */
final class ImportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('penduduk.impor');

        return Inertia::render('Kependudukan/Impor/Index', [
            'jobs' => ImportJob::query()
                ->where('type', 'residents')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (ImportJob $job): array => $this->present($job))
                ->values()
                ->all(),
            'columns' => array_map(
                fn (array $c): array => ['judul' => $c[0], 'wajib' => $c[1], 'keterangan' => $c[2]],
                ResidentImportSchema::COLUMNS,
            ),
        ]);
    }

    /** Unduh template Excel resmi. */
    public function template(): StreamedResponse
    {
        $this->authorize('penduduk.impor');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Penduduk');

        $headers = ResidentImportSchema::headers();
        $sheet->fromArray($headers, null, 'A1');

        // Baris contoh membantu petugas memahami format tanggal dan pilihan
        // yang diterima — jauh lebih efektif daripada petunjuk tertulis.
        $sheet->fromArray([
            '3201011503900001', 'Ahmad Hidayat', 'Sukamaju', '1990-03-15', 'L',
            '3201010000000001', 'Kepala Keluarga', 'Jl. Melati No. 12', '001', '001',
            'Krajan', 'Islam', 'Kawin Tercatat', 'SLTA/Sederajat', 'Petani/Pekebun',
            'O', 'Sanusi', 'Rohmah', '081234567890', 'WNI',
        ], null, 'A2');

        foreach (range('A', 'T') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->getStyle('A1:T1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'template-impor-penduduk.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Unggah berkas → PRA-VALIDASI (belum menyimpan apa pun). */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('penduduk.impor');

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ], [
            'file.required' => 'Pilih berkas terlebih dahulu.',
            'file.mimes' => 'Format berkas harus .xlsx atau .csv.',
            'file.max' => 'Ukuran berkas maksimal 10 MB. Bagi menjadi beberapa berkas bila lebih besar.',
        ]);

        $uploaded = $request->file('file');

        /*
         * Berkas disimpan di disk `local` (di luar web root), dengan nama acak.
         * Berkas impor memuat NIK ribuan warga — ia tidak boleh dapat diakses
         * lewat URL, dan nama aslinya tidak boleh menentukan jalurnya.
         */
        $path = $uploaded->store('imports', 'local');

        $job = new ImportJob;
        $job->forceFill([
            'type' => 'residents',
            'original_name' => $uploaded->getClientOriginalName(),
            'storage_path' => $path,
            'status' => ImportJob::STATUS_PENDING,
            'created_by' => $request->user()?->id,
        ])->save();

        ProcessResidentImport::dispatch($job->id, 'validate');

        return redirect()
            ->route('impor.show', $job->id)
            ->with('status', 'Berkas sedang diperiksa. Hasilnya muncul sebentar lagi.');
    }

    public function show(string $id): Response
    {
        $this->authorize('penduduk.impor');

        $job = ImportJob::query()->findOrFail($id);

        return Inertia::render('Kependudukan/Impor/Show', [
            'job' => $this->present($job, withErrors: true),
        ]);
    }

    /** Status untuk polling — UC-04 langkah 3. */
    public function status(string $id): JsonResponse
    {
        $this->authorize('penduduk.impor');

        $job = ImportJob::query()->findOrFail($id);

        return response()->json($this->present($job, withErrors: true));
    }

    /** Tahap 2 — jalankan impor setelah petugas meninjau hasil pra-validasi. */
    public function confirm(string $id): RedirectResponse
    {
        $this->authorize('penduduk.impor');

        $job = ImportJob::query()->findOrFail($id);

        if ($job->status !== ImportJob::STATUS_REVIEW) {
            return back()->with('error',
                'Impor ini tidak dalam keadaan menunggu persetujuan.');
        }

        if ($job->valid_rows === 0) {
            return back()->with('error',
                'Tidak ada baris yang sah untuk diimpor. Perbaiki berkasnya lalu unggah ulang.');
        }

        ProcessResidentImport::dispatch($job->id, 'execute');

        return back()->with('status', 'Impor dimulai. Kemajuannya tampil di halaman ini.');
    }

    public function cancel(string $id): RedirectResponse
    {
        $this->authorize('penduduk.impor');

        $job = ImportJob::query()->findOrFail($id);

        if ($job->isFinished()) {
            return back()->with('error', 'Impor ini sudah selesai dan tidak dapat dibatalkan.');
        }

        // Berkas unggahan dihapus bersama pembatalan: ia memuat NIK ribuan
        // warga dan tidak lagi punya alasan untuk tetap ada.
        Storage::disk('local')->delete($job->storage_path);

        $job->update([
            'status' => ImportJob::STATUS_CANCELLED,
            'finished_at' => now(),
        ]);

        return redirect()->route('impor.index')->with('status', 'Impor dibatalkan.');
    }

    /** @return array<string, mixed> */
    private function present(ImportJob $job, bool $withErrors = false): array
    {
        return [
            'id' => $job->id,
            'original_name' => $job->original_name,
            'status' => $job->status,
            'status_label' => [
                ImportJob::STATUS_PENDING => 'Menunggu',
                ImportJob::STATUS_VALIDATING => 'Memeriksa berkas',
                ImportJob::STATUS_REVIEW => 'Menunggu persetujuan Anda',
                ImportJob::STATUS_PROCESSING => 'Sedang diimpor',
                ImportJob::STATUS_DONE => 'Selesai',
                ImportJob::STATUS_FAILED => 'Gagal',
                ImportJob::STATUS_CANCELLED => 'Dibatalkan',
            ][$job->status] ?? $job->status,
            'total_rows' => $job->total_rows,
            'valid_rows' => $job->valid_rows,
            'invalid_rows' => $job->invalid_rows,
            'processed_rows' => $job->processed_rows,
            'created_count' => $job->created_count,
            'updated_count' => $job->updated_count,
            'skipped_count' => $job->skipped_count,
            'progress' => $job->progress(),
            'failure_reason' => $job->failure_reason,
            'is_finished' => $job->isFinished(),
            'created_at' => $job->created_at->locale('id')->translatedFormat('d M Y, H:i'),
            'errors' => $withErrors ? ($job->errors ?? []) : null,
        ];
    }
}
