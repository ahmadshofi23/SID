<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaduan & tindak lanjutnya — docs/05-model-data.md §3.5, FR-PGD-01..08.
 *
 * Satu penyimpangan dari skema docs/05 §3.5, dicatat sebagai KEP-018:
 * kolom `triage_due_at` ditambahkan di samping `due_at`. Skema aslinya hanya
 * punya satu kolom tenggat, sementara FR-PGD-06 menetapkan DUA batas yang
 * berjalan berurutan — triase 2 hari dan penyelesaian 14 hari kerja. Satu
 * kolom yang maknanya berpindah mengikuti status akan membuat pertanyaan
 * "berapa persen pengaduan ditriase tepat waktu" tidak dapat dijawab setelah
 * pengaduannya selesai, karena angka tenggat triasenya sudah ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $t): void {
            $t->char('id', 36)->primary();

            /*
             * DUA pengenal, dan keduanya perlu.
             *
             * `ticket_number` adalah nomor administratif yang dipakai petugas
             * dan dirujuk di surat-menyurat. `tracking_code` adalah rahasia
             * yang dipegang PELAPOR — satu-satunya jalan pelapor anonim
             * kembali melihat pengaduannya, karena RULE-09 melarang sistem
             * menyimpan tautan apa pun ke dirinya. Nomor tiket tidak boleh
             * dipakai untuk itu: ia muncul di berkas dan rapat desa, jadi
             * siapa pun yang pernah melihatnya dapat membuka pengaduan orang.
             */
            $t->string('ticket_number', 40);
            $t->string('tracking_code', 20);

            $t->string('category_code', 40);
            $t->string('title', 200);
            $t->text('body');

            $t->boolean('is_anonymous')->default(false);
            $t->char('reporter_user_id', 36)->nullable()
                ->comment('NULL bila anonim — RULE-09, ditegakkan chk_complaints_anon');
            $t->string('reporter_name', 150)->nullable();
            $t->string('reporter_phone', 30)->nullable();

            $t->char('region_id', 36)->nullable();
            $t->string('location_text', 255)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();

            $t->enum('status', ['baru', 'diterima', 'diproses', 'selesai', 'ditolak'])
                ->default('baru');
            $t->enum('priority', ['rendah', 'normal', 'tinggi'])->default('normal');

            $t->char('assigned_to', 36)->nullable();
            $t->dateTime('assigned_at')->nullable();

            /*
             * Tenggat triase disimpan SEKALI saat pengaduan masuk dan tidak
             * pernah diubah lagi — termasuk setelah pengaduan ditriase. Yang
             * membuatnya berguna justru riwayatnya: tanpa nilai yang tetap,
             * laporan ketepatan triase hanya dapat dihitung untuk pengaduan
             * yang BELUM ditangani, yaitu justru yang belum punya hasil.
             */
            $t->dateTime('triage_due_at')->nullable()
                ->comment('Tenggat triase 2 hari kerja — FR-PGD-06, tidak pernah ditimpa');
            $t->dateTime('due_at')->nullable()
                ->comment('Tenggat penyelesaian 14 hari kerja, diisi saat diterima');
            $t->dateTime('resolved_at')->nullable();
            $t->text('resolution')->nullable();

            $t->boolean('is_published')->default(false)
                ->comment('Ringkasan terbit ke portal — opt-in per kasus, FR-PGD-07');

            /*
             * IP penyerah disimpan untuk penyaringan spam (FR-PGD-08), BUKAN
             * untuk mengenali pelapor. Pada pengaduan anonim ia tetap diisi,
             * dan itu memang dibolehkan chk_complaints_anon: IP sebuah warnet
             * atau ponsel yang dipakai bergantian bukan identitas, sedangkan
             * tanpa ia satu skrip dapat membanjiri kotak pengaduan desa tanpa
             * ada satu pun cara menghentikannya.
             */
            $t->string('submitted_ip', 45)->nullable();

            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();

            $t->unique('ticket_number', 'uq_complaints_ticket');
            $t->unique('tracking_code', 'uq_complaints_tracking');
            $t->index(['status', 'created_at'], 'idx_complaints_status');
            $t->index(['assigned_to', 'status'], 'idx_complaints_assigned');
            $t->index(['status', 'due_at'], 'idx_complaints_due');
            $t->index(['status', 'triage_due_at'], 'idx_complaints_triage_due');
            $t->index('reporter_user_id', 'idx_complaints_reporter');

            /*
             * `reporter_user_id` SENGAJA tanpa kunci asing, dan ini bukan
             * kelalaian — MySQL menolaknya: sebuah kolom tidak boleh dipakai
             * CHECK constraint sekaligus menjadi kolom kunci asing yang punya
             * aksi referensial (galat 3823). Pilihannya biner, dan yang
             * dipertahankan adalah CHECK `chk_complaints_anon` di bawah:
             * kunci asing hanya menjaga kerapian tautan, sedangkan CHECK itu
             * menjaga janji anonim kepada warga. Skema docs/05 §3.5 memang
             * juga tidak mendeklarasikan kunci asing di sini.
             *
             * Akibat yang diterima: menghapus akun pengguna meninggalkan id
             * yang menggantung di kolom ini. Itu tidak berbahaya — relasi
             * `reporter()` mengembalikan null dan `reporterLabel()` sudah
             * menyiapkan jawabannya ("Tidak diketahui").
             */
            $t->foreign('assigned_to', 'fk_complaints_assignee')
                ->references('id')->on('users')->nullOnDelete();
            $t->foreign('region_id', 'fk_complaints_region')
                ->references('id')->on('regions')->nullOnDelete();
        });

        /*
         * RULE-09 di tingkat basis data.
         *
         * Pengaduan anonim TIDAK DAPAT menyimpan identitas pelapor, bahkan
         * bila kode aplikasi keliru. Ini bukan pengulangan validasi yang
         * berlebihan: janji anonim adalah satu-satunya alasan seseorang berani
         * melaporkan pungutan liar yang dilakukan perangkat desanya sendiri,
         * dan janji itu dilanggar selamanya oleh satu baris kode yang lupa
         * mengosongkan `reporter_user_id`. Lapis aplikasi dapat salah ditulis
         * ulang; CHECK ini tidak dapat dilewati oleh jalur mana pun —
         * termasuk perbaikan data lewat SQL langsung.
         */
        DB::statement(
            'ALTER TABLE complaints
                ADD CONSTRAINT chk_complaints_anon
                CHECK (
                    is_anonymous = 0
                    OR (reporter_user_id IS NULL
                        AND reporter_name IS NULL
                        AND reporter_phone IS NULL)
                )'
        );

        /*
         * Penolakan tanpa alasan tertulis dilarang di tingkat basis data
         * (FR-PGD-03). Pengaduan yang ditolak tanpa penjelasan adalah bentuk
         * paling halus dari tidak melayani: catatannya "ada" di sistem, tetapi
         * pelapor tidak pernah tahu mengapa laporannya mati.
         */
        DB::statement(
            "ALTER TABLE complaints
                ADD CONSTRAINT chk_complaints_tolak_beralasan
                CHECK (status <> 'ditolak' OR resolution IS NOT NULL)"
        );

        Schema::create('complaint_actions', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('complaint_id', 36);

            /*
             * Nama pelaku DISALIN, tidak hanya dirujuk lewat actor_id.
             *
             * Pola yang sama dengan `letter_request_logs`: riwayat tindak
             * lanjut harus tetap terbaca setelah akun petugasnya dihapus atau
             * berganti nama. Riwayat yang berubah ketika datanya berubah bukan
             * riwayat.
             */
            $t->char('actor_id', 36)->nullable();
            $t->string('actor_name', 150)->nullable();

            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->text('note');

            /*
             * Tidak setiap catatan layak dibaca pelapor. Koordinasi internal
             * ("minta Kasi Pemerintahan cek dulu anggarannya") tetap tercatat
             * demi audit, tetapi dibedakan dari narasi tindak lanjut yang
             * memang ditujukan kepada pelapor (FR-PGD-05). Bawaannya TERLIHAT,
             * supaya catatan yang disembunyikan selalu merupakan pilihan sadar.
             */
            $t->boolean('is_visible_to_reporter')->default(true);

            $t->dateTime('created_at')->useCurrent();

            $t->index(['complaint_id', 'created_at'], 'idx_ca_complaint');

            /*
             * SENGAJA tanpa kunci asing ke `users`, sama seperti
             * `letter_request_logs`: riwayat tidak boleh bergantung pada baris
             * yang masih hidup di tabel lain. `actor_name` di atas sudah
             * menyimpan apa yang perlu dibaca manusia.
             */
            $t->foreign('complaint_id', 'fk_ca_complaint')
                ->references('id')->on('complaints')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_actions');
        Schema::dropIfExists('complaints');
    }
};
