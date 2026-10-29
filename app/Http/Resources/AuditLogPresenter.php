<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Support\VillageTime;
use Illuminate\Support\Carbon;

/**
 * Penerjemah baris audit log untuk lapis tampilan — FR-ADM-05.
 *
 * ALAMAT IP DITAMPILKAN APA ADANYA, dan itu keputusan — bukan kelalaian.
 * ---------------------------------------------------------------------
 * Kekhawatiran yang wajar muncul lebih dulu: KEP-018 menyimpan alamat IP
 * pelapor pengaduan sebagai `submitted_ip_hash`, bukan alamat mentah, justru
 * karena **alamat mentah dapat di-JOIN dengan audit log** untuk menunjuk
 * pelapor anonim. Halaman Log Aktivitas tampaknya membuka kembali tautan itu
 * dari arah sebaliknya: alamat di layar, di sebelah baris
 * `complaint.created_anonymous`, tinggal dicocokkan dengan baris `auth.login`
 * pada menit yang sama.
 *
 * Tautan itu TIDAK ADA, dan sudah ditutup di tempat yang benar:
 * `AuditLogger::logWithoutActor()` tidak mengisi `ip_address` sama sekali,
 * dengan keterangan yang menyebutkan alasan yang persis sama. Ditutup pada
 * PENULISANNYA, bukan pada pembacaannya — satu-satunya tempat yang dapat
 * menutupnya untuk semua pembaca sekaligus, termasuk yang membuka MySQL
 * langsung.
 *
 * Menyamarkan alamat di sini untuk "setiap baris tanpa pelaku" karena itu
 * tidak menambah perlindungan apa pun, dan merusak sesuatu: baris tanpa
 * pelaku yang MEMANG membawa alamat adalah tindakan publik —
 * `letter.tracked`, `letter.tracking_failed`, `citizen.registered`, percobaan
 * masuk yang gagal — yaitu tepat baris yang alamatnya satu-satunya petunjuk
 * ketika seseorang mencoba menebak kode pelacakan ribuan kali. Penyamaran di
 * sana membutakan pemantauan penyalahgunaan demi keamanan yang sudah
 * diperoleh di tempat lain.
 *
 * Yang menjaga sifat itu adalah uji atas PENULISANNYA
 * (`AuditLogPageTest::baris_tanpa_pelaku_tidak_pernah_membawa_alamat_ip`),
 * bukan lapis penyamaran di sini. Lihat KEP-021.
 */
final class AuditLogPresenter
{
    /**
     * Satu baris untuk daftar.
     *
     * `changes` dan `context` diteruskan APA ADANYA, dan itu aman karena
     * keduanya sudah dilewatkan `PiiRedactor` SAAT DITULIS
     * (`AuditLogger::log()`), bukan saat dibaca. Menyamarkan di sini akan
     * menjadi lapis kedua yang tampak melindungi tetapi tidak: baris yang
     * lolos tanpa penyamaran sudah tersimpan demikian, dan satu-satunya
     * tempat yang dapat memperbaikinya adalah penulisannya.
     *
     * @return array<string, mixed>
     */
    public static function row(AuditLog $entry): array
    {
        return [
            'id' => $entry->id,
            'action' => $entry->action,
            'actor_id' => $entry->actor_id,
            // "sistem" bila tidak ada pelaku sama sekali; null hanya pada
            // baris RULE-09, yang di layar dikatakan dengan kata.
            'actor_name' => $entry->actor_name,
            'actor_roles' => $entry->actor_roles,
            'entity_type' => $entry->entity_type,
            'entity_id' => $entry->entity_id,
            'changes' => $entry->changes,
            'context' => $entry->context,
            'ip_address' => $entry->ip_address,
            'user_agent' => $entry->user_agent,
            'correlation_id' => $entry->correlation_id,
            'hash_version' => $entry->hash_version,
            'created_at' => self::villageDateTime($entry->created_at),
        ];
    }

    /**
     * Satu baris untuk ekspor CSV — kolom yang sama, tanpa struktur bersarang.
     *
     * `changes` dan `context` menjadi JSON dalam satu sel. Itu tidak nyaman
     * dibaca di Excel, dan tetap lebih baik daripada memecahnya menjadi kolom
     * per medan: medannya berbeda untuk setiap aksi, sehingga berkasnya akan
     * punya ratusan kolom yang hampir seluruhnya kosong.
     *
     * @return list<string|int|null>
     */
    public static function csvRow(AuditLog $entry): array
    {
        return [
            $entry->id,
            self::villageDateTime($entry->created_at),
            $entry->action,
            $entry->actor_name,
            $entry->actor_roles,
            $entry->entity_type,
            $entry->entity_id,
            self::jsonCell($entry->changes),
            self::jsonCell($entry->context),
            $entry->ip_address,
            $entry->user_agent,
            $entry->correlation_id,
            $entry->hash_version,
        ];
    }

    /** @return list<string> */
    public static function csvHeaders(): array
    {
        return [
            'ID', 'Waktu', 'Aksi', 'Pelaku', 'Peran Pelaku', 'Jenis Entitas',
            'ID Entitas', 'Perubahan', 'Konteks', 'Alamat IP', 'User Agent',
            'ID Korelasi', 'Versi Hash',
        ];
    }

    /** @param  array<array-key, mixed>|null  $value */
    private static function jsonCell(?array $value): ?string
    {
        return $value === null
            ? null
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** "15 Oktober 2026, 14.32.07" pada kalender desa (KEP-010). */
    private static function villageDateTime(?Carbon $moment): ?string
    {
        /*
         * DETIK ikut ditampilkan, berbeda dari presenter lain yang berhenti
         * di menit. Pada audit log, urutan dua tindakan dalam satu menit
         * adalah pertanyaan yang benar-benar diajukan orang — "apakah ia
         * membuka NIK itu sebelum atau sesudah permohonannya masuk" — dan
         * tampilan bermenit membuat keduanya terlihat bersamaan.
         */
        return $moment === null
            ? null
            : VillageTime::local($moment)->locale('id')->translatedFormat('d F Y, H.i.s');
    }
}
