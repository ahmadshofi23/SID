<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Kependudukan — docs/05-model-data.md §3.2
 *
 * Dibuat pada Sprint 1 (bukan Sprint 2) karena dua alasan:
 *   1. users.resident_id memerlukan tabel ini untuk foreign key.
 *   2. Lapis repository + uji otorisasi (risiko R-15) harus divalidasi lebih
 *      awal pada tabel yang paling sensitif. CRUD/UI-nya baru di Sprint 2.
 *
 * Dua aturan bisnis ditegakkan BASIS DATA lewat kolom generated (docs/05 §2.2),
 * karena MySQL tidak punya partial unique index:
 *   RULE-01 nik_hash_active  -> NIK unik hanya di antara penduduk belum dihapus
 *   RULE-02 family_head_key  -> tepat satu kepala keluarga per KK
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $t): void {
            $t->char('id', 36)->primary();

            // Nomor KK: terenkripsi (ADR-004). varbinary, bukan blob.
            $t->binary('kk_number_enc', 255);
            $t->char('kk_number_hash', 64)->comment('HMAC-SHA256 untuk cari & unik');
            $t->string('kk_number_masked', 20)->comment('3201••••••••0001');

            $t->char('region_id', 36)->nullable()->comment('RT tempat tinggal');
            $t->string('address', 255);
            $t->string('rt', 10)->nullable();
            $t->string('rw', 10)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('welfare_status_code', 30)->nullable();
            $t->string('house_ownership_code', 30)->nullable();
            $t->char('created_by', 36)->nullable();
            $t->char('updated_by', 36)->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->dateTime('deleted_at')->nullable();

            // Keunikan bersyarat: nomor KK unik hanya untuk baris belum dihapus.
            $t->char('kk_hash_active', 64)
                ->storedAs('IF(deleted_at IS NULL, kk_number_hash, NULL)');

            $t->unique('kk_hash_active', 'uq_families_kk_active');
            $t->index('region_id', 'idx_families_region');
            $t->index('deleted_at', 'idx_families_deleted');

            $t->foreign('region_id', 'fk_families_region')
                ->references('id')->on('regions')->nullOnDelete();
        });

        Schema::create('residents', function (Blueprint $t): void {
            $t->char('id', 36)->primary();
            $t->char('family_id', 36)->nullable();
            $t->char('region_id', 36)->nullable();

            // Identitas: terenkripsi di lapis aplikasi (ADR-004)
            $t->binary('nik_enc', 255);
            $t->char('nik_hash', 64)->comment('HMAC-SHA256, cari persis & unik');
            $t->string('nik_masked', 20)->comment('3201••••••••1234');
            $t->string('full_name', 150);
            $t->string('full_name_norm', 150)
                ->comment('Huruf kecil, tanpa gelar/tanda baca — untuk pencarian');
            $t->string('birth_place', 100)->nullable();
            $t->date('birth_date');
            $t->enum('gender', ['L', 'P']);
            $t->string('blood_type_code', 10)->nullable();

            // Data berkode -> tabel ref_*
            $t->string('religion_code', 20)->nullable();
            $t->string('marital_status_code', 20)->nullable();
            $t->string('family_relation_code', 30);
            $t->string('education_code', 20)->nullable();
            $t->string('occupation_code', 30)->nullable();
            $t->string('citizenship', 20)->default('WNI');

            // Orang tua (untuk akta & verifikasi silsilah)
            $t->string('father_name', 150)->nullable();
            $t->string('mother_name', 150)->nullable();
            $t->char('father_nik_hash', 64)->nullable();
            $t->char('mother_nik_hash', 64)->nullable();

            // Kontak & domisili
            $t->string('address', 255)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('domicile_address', 255)->nullable();

            // Status kependudukan (RULE-03)
            $t->enum('status', [
                'aktif', 'meninggal', 'pindah_keluar', 'hilang', 'tidak_diketahui',
            ])->default('aktif');
            $t->dateTime('status_changed_at')->nullable();

            // Atribut sosial (program bantuan)
            $t->string('disability_code', 30)->nullable();
            $t->boolean('is_poor')->nullable();
            $t->string('photo_path', 255)->nullable();
            $t->text('notes')->nullable();

            $t->char('created_by', 36)->nullable();
            $t->char('updated_by', 36)->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->dateTime('deleted_at')->nullable();

            // RULE-01 — NIK unik di antara penduduk yang belum dihapus
            $t->char('nik_hash_active', 64)
                ->storedAs('IF(deleted_at IS NULL, nik_hash, NULL)');

            // RULE-02 — tepat satu kepala keluarga per KK
            $t->char('family_head_key', 36)->storedAs(
                "IF(family_relation_code = 'kepala_keluarga' AND deleted_at IS NULL,
                    family_id, NULL)"
            );

            $t->unique('nik_hash_active', 'uq_residents_nik_active');
            $t->unique('family_head_key', 'uq_families_single_head');

            $t->index('nik_hash', 'idx_residents_nik_hash');
            $t->index('full_name_norm', 'idx_residents_name');
            $t->index(['region_id', 'status', 'deleted_at'], 'idx_residents_region');
            $t->index(['family_id', 'deleted_at'], 'idx_residents_family');
            $t->index('birth_date', 'idx_residents_birth');
            $t->index(['status', 'deleted_at'], 'idx_residents_status');
            $t->fullText('full_name', 'ft_residents_name');

            /*
             * RESTRICT, bukan SET NULL.
             * MySQL melarang aksi referensial CASCADE/SET NULL/SET DEFAULT pada
             * kolom yang menjadi BASIS kolom generated — di sini `family_id`
             * adalah basis `family_head_key`. Konsisten dengan RULE-08: keluarga
             * hanya di-soft-delete, tidak pernah dihapus permanen.
             */
            $t->foreign('family_id', 'fk_residents_family')
                ->references('id')->on('families')->restrictOnDelete();
            $t->foreign('region_id', 'fk_residents_region')
                ->references('id')->on('regions')->nullOnDelete();
        });

        /*
         * CATATAN: `CHECK (birth_date <= CURRENT_DATE)` TIDAK dapat dibuat.
         * MySQL (dan PostgreSQL) melarang fungsi non-deterministik di dalam
         * CHECK constraint, karena hasilnya berubah seiring waktu sehingga baris
         * yang sah hari ini bisa menjadi tidak sah besok.
         *
         * Validasi "tanggal lahir tidak boleh di masa depan" ditegakkan di lapis
         * aplikasi: App\Rules\BirthDateRule (FR-PDK-02) dan diuji di
         * tests/Unit/BirthDateRuleTest.php.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('residents');
        Schema::dropIfExists('families');
    }
};
