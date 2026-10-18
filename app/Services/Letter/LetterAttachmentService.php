<?php

declare(strict_types=1);

namespace App\Services\Letter;

use App\Models\LetterAttachment;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Services\Audit\AuditLogger;
use App\Services\Upload\FileSignature;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Penerimaan & penyimpanan lampiran syarat — Sprint 5, KEP-013 §1.
 *
 * Alur yang ditegakkan di sini persis alur docs/04 §6 baris "Berkas":
 *   unggah → validasi magic bytes → nama acak → penyimpanan privat → akses
 *   lewat permintaan berlingkup (bukan URL yang dapat beredar).
 */
final class LetterAttachmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Periksa SELURUH berkas sebelum satu pun disimpan.
     *
     * Dipanggil terpisah dari store() karena pembuatan pengajuan menyimpan
     * barisnya lebih dulu. Bila berkas kedua baru ditolak setelah berkas
     * pertama tersimpan, petugas mendapat pengajuan setengah jadi dengan
     * lampiran yang tidak ia maksud — dan tidak ada cara membatalkannya
     * selain menghapus satu per satu.
     *
     * @param  array<string, UploadedFile>  $files  kunci = nama field pada formulir
     *
     * @throws ValidationException
     */
    public function assertAllValid(array $files): void
    {
        $errors = [];

        foreach ($files as $field => $file) {
            $message = $this->rejectionReason($file);

            if ($message !== null) {
                $errors[$field] = [$message];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Alasan penolakan, atau null bila berkas diterima.
     *
     * Pesannya ditulis untuk petugas di loket: ia harus tahu apa yang perlu
     * ia lakukan terhadap berkas di tangannya, bukan aturan apa yang dilanggar.
     */
    public function rejectionReason(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            /*
             * Unggahan yang terpotong di tengah jalan — koneksi kantor desa
             * putus, atau berkasnya melampaui upload_max_filesize PHP.
             * Keadaan ini HARUS bersuara: tanpa pesan, berkas yang gagal
             * naik terlihat persis sama dengan berkas yang tidak pernah
             * dipilih (KEP-013).
             */
            return 'Berkas gagal diunggah seluruhnya ('.$file->getErrorMessage()
                .'). Coba unggah ulang.';
        }

        $max = $this->maxBytes();

        if ($file->getSize() > $max) {
            return 'Ukuran berkas '.$this->humanSize((int) $file->getSize())
                .', melebihi batas '.$this->humanSize($max).' per berkas.';
        }

        $signature = FileSignature::detect($file->getRealPath());

        if ($signature === null) {
            return 'Isi berkas bukan '.FileSignature::acceptedLabels()
                .'. Mengubah nama berkas tidak mengubah isinya — '
                .'unggah hasil pindai atau fotonya langsung.';
        }

        if (! $signature->matchesClaimedExtension($file->getClientOriginalExtension())) {
            /*
             * Isinya SAH tetapi tidak sesuai namanya. Ini jarang berniat
             * jahat — biasanya berkas hasil "simpan sebagai" yang salah —
             * namun tetap ditolak: berkas yang namanya berdusta akan dibuka
             * dengan aplikasi yang salah oleh petugas berikutnya.
             */
            return 'Nama berkas berakhiran .'.strtolower($file->getClientOriginalExtension())
                .', tetapi isinya '.$signature->label.'. Perbaiki nama berkasnya lalu unggah ulang.';
        }

        return null;
    }

    /**
     * Simpan satu berkas untuk sebuah pengajuan.
     *
     * @throws ValidationException
     */
    public function store(
        LetterRequest $request,
        UploadedFile $file,
        ?int $requirementIndex,
        string $field = 'berkas',
    ): LetterAttachment {
        $this->assertAllValid([$field => $file]);
        $this->assertNotIssued($request, 'menambah lampiran');

        if ($this->countFor($request) >= $this->maxPerRequest()) {
            throw ValidationException::withMessages([
                $field => ['Pengajuan ini sudah memuat '.$this->maxPerRequest()
                    .' berkas — batas maksimum. Hapus salah satu sebelum menambah.'],
            ]);
        }

        /** @var FileSignature $signature */
        $signature = FileSignature::detect($file->getRealPath());

        $contents = (string) file_get_contents($file->getRealPath());

        /*
         * Nama berkas DIACAK ULANG (NFR-SEC-05).
         *
         * Nama asli tidak pernah menyentuh sistem berkas: ia dapat memuat
         * `../`, karakter yang bermakna khusus di S3, atau nama pemohon —
         * yang berarti daftar objek penyimpanan menjadi daftar warga yang
         * mengurus surat. Nama asli hanya disimpan di basis data, untuk
         * ditampilkan dan dipakai kembali saat berkasnya diunduh.
         */
        $path = 'lampiran-pengajuan/'.$request->id.'/'
            .Str::lower((string) Str::ulid()).'.'.$signature->extension;

        $disk = $this->diskName();

        Storage::disk($disk)->put($path, $contents);

        $attachment = new LetterAttachment;
        $attachment->forceFill([
            'request_id' => $request->id,
            'requirement_index' => $requirementIndex,
            'requirement_label' => $this->labelFor($request, $requirementIndex),
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
            'mime' => $signature->mime,
            'extension' => $signature->extension,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'uploaded_by' => auth()->id(),
            'uploaded_by_name' => auth()->user()?->name,
        ])->save();

        $this->audit->log('letter_request.attachment_uploaded', $request, context: [
            'tiket' => $request->ticket_number,
            'syarat' => $attachment->requirement_label,
            'tipe' => $signature->label,
            'ukuran_bita' => $attachment->size_bytes,
        ]);

        return $attachment;
    }

    /**
     * Hapus lampiran — hanya selama suratnya BELUM terbit.
     *
     * @throws ValidationException
     */
    public function delete(LetterRequest $request, LetterAttachment $attachment): void
    {
        $this->assertNotIssued($request, 'menghapus lampiran');

        /*
         * Baris basis data dihapus lebih dulu, berkasnya menyusul.
         *
         * Urutan sebaliknya meninggalkan baris yang menunjuk berkas yang
         * sudah tidak ada bila penghapusan di S3 berhasil tetapi
         * penghapusan barisnya gagal — tepat gejala "berkas hilang" yang
         * membingungkan. Berkas yatim di penyimpanan jauh lebih jinak.
         */
        $disk = $attachment->disk;
        $path = $attachment->path;
        $label = $attachment->requirement_label;
        $name = $attachment->original_name;

        $attachment->delete();

        Storage::disk($disk)->delete($path);

        $this->audit->log('letter_request.attachment_deleted', $request, context: [
            'tiket' => $request->ticket_number,
            'syarat' => $label,
            'nama_berkas' => $name,
        ]);
    }

    /**
     * Surat yang sudah terbit IKUT MENGUNCI lampirannya (ADR-003).
     *
     * Surat terbit adalah snapshot permanen; berkas syarat yang menjadi
     * dasar penerbitannya adalah bagian dari arsip yang sama. Bila lampiran
     * masih dapat dihapus setelah surat keluar, pertanyaan "atas dasar apa
     * surat ini terbit?" tidak lagi dapat dijawab saat pemeriksaan.
     *
     * @throws ValidationException
     */
    public function assertNotIssued(LetterRequest $request, string $action): void
    {
        if ($request->status === LetterRequest::STATUS_ISSUED) {
            throw ValidationException::withMessages([
                'lampiran' => ['Surat sudah terbit, sehingga '.$action
                    .' tidak lagi dapat dilakukan. Lampirannya menjadi bagian arsip '
                    .'yang mendasari penerbitan surat.'],
            ]);
        }
    }

    public function countFor(LetterRequest $request): int
    {
        return LetterAttachment::query()->where('request_id', $request->id)->count();
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) config('sid.uploads.disk', 'local');
    }

    /**
     * Batasan unggahan untuk ditampilkan di formulir.
     *
     * Dipindahkan ke sini dari controller karena DUA layar memakainya —
     * pengajuan loket oleh petugas dan pengajuan daring oleh warga. Dua
     * salinan berarti batas ukuran di layar warga kelak berbeda dari yang
     * benar-benar ditegakkan server, dan warga diberi tahu angka yang salah.
     *
     * @return array{accept: string, max_bytes: int, max_label: string, types_label: string}
     */
    public function constraints(): array
    {
        return [
            'accept' => FileSignature::acceptAttribute(),
            'max_bytes' => $this->maxBytes(),
            'max_label' => $this->humanSize($this->maxBytes()),
            'types_label' => FileSignature::acceptedLabels(),
        ];
    }

    /**
     * Berkas syarat dari permintaan, berkunci `syarat.<indeks>`.
     *
     * @return array<string, UploadedFile>
     */
    public function requirementFilesFrom(Request $request): array
    {
        $files = [];

        foreach ((array) $request->file('syarat', []) as $index => $file) {
            if ($file instanceof UploadedFile) {
                $files['syarat.'.$index] = $file;
            }
        }

        return $files;
    }

    /** Indeks syarat dari nama field, atau null bila di luar daftar syarat. */
    public function requirementIndex(string $field, LetterType $type): ?int
    {
        $index = (int) str_replace('syarat.', '', $field);

        return isset($type->requirements[$index]) ? $index : null;
    }

    /**
     * Syarat WAJIB yang belum ada berkasnya — BR-10.
     *
     * MENGAPA HANYA DIPAKAI JALUR DARING: di loket, petugas memegang berkas
     * fisik dan sering memindainya belakangan, sehingga memblokir pengajuan
     * karena berkas belum diunggah akan memulangkan warga yang sudah berdiri
     * di depan meja. Warga yang mengajukan dari rumah tidak punya petugas
     * yang dapat menyusulkan berkasnya, jadi di sana syarat wajib benar-benar
     * wajib — dan kekurangannya harus DISEBUTKAN satu per satu, bukan ditolak
     * dengan "lengkapi syarat".
     *
     * @param  array<string, UploadedFile>  $files
     * @return array<string, string> kunci field => pesan
     */
    public function missingRequiredRequirements(LetterType $type, array $files): array
    {
        $missing = [];

        foreach ($type->requirements as $index => $requirement) {
            if (($requirement['required'] ?? true) !== true) {
                continue;
            }

            if (! isset($files['syarat.'.$index])) {
                $missing['syarat.'.$index] = 'Unggah "'.($requirement['label'] ?? 'dokumen syarat')
                    .'" — syarat ini wajib untuk '.$type->name.'.';
            }
        }

        return $missing;
    }

    public function maxBytes(): int
    {
        return (int) config('sid.uploads.max_bytes', 5 * 1024 * 1024);
    }

    public function maxPerRequest(): int
    {
        return (int) config('sid.uploads.max_per_request', 20);
    }

    /** Salin label syarat pada saat berkas diunggah. */
    private function labelFor(LetterRequest $request, ?int $index): ?string
    {
        if ($index === null) {
            return null;
        }

        $requirement = $request->letterType->requirements[$index] ?? null;

        return is_array($requirement) && is_string($requirement['label'] ?? null)
            ? Str::limit($requirement['label'], 255, '')
            : null;
    }

    public function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
        }

        return number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.').' KB';
    }
}
