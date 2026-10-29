<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pencatat audit log — FR-ADM-04.
 *
 * Setiap pencatatan dirantai ke baris sebelumnya (hash chain). Perangkaian
 * dilakukan di dalam transaksi dengan locking agar dua permintaan bersamaan
 * tidak menghasilkan rantai bercabang — lihat append().
 */
final class AuditLogger
{
    public function __construct(private readonly PiiRedactor $redactor) {}

    /** Aksi yang WAJIB dicatat — docs/08 §8. */
    public const ACTION_LOGIN = 'auth.login';

    public const ACTION_LOGIN_FAILED = 'auth.login_failed';

    public const ACTION_LOGOUT = 'auth.logout';

    public const ACTION_LOCKED = 'auth.account_locked';

    public const ACTION_2FA_ENABLED = 'auth.two_factor_enabled';

    public const ACTION_PII_REVEALED = 'data.pii_revealed';

    public const ACTION_EXPORTED = 'data.exported';

    public const ACTION_ROLE_CHANGED = 'user.role_changed';

    public const ACTION_SCOPE_VIOLATION = 'security.scope_violation';

    /**
     * @param  array<array-key, mixed>|null  $changes
     * @param  array<array-key, mixed>|null  $context
     */
    public function log(
        string $action,
        ?Model $entity = null,
        ?array $changes = null,
        ?array $context = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= auth()->user();

        $entry = new AuditLog([
            'actor_id' => $actor?->id,
            'actor_name' => $actor === null ? 'sistem' : $actor->name,
            'actor_roles' => $actor === null
                ? null
                : $actor->roles->pluck('code')->implode(','),
            'action' => $action,
            'entity_type' => $entity === null ? null : class_basename($entity),
            'entity_id' => $entity?->getKey() === null ? null : (string) $entity->getKey(),
            'changes' => $changes === null ? null : $this->redactor->redact($changes),
            'context' => $context === null ? null : $this->redactor->redact($context),
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 300, ''),
            'correlation_id' => $this->correlationId(),
            'created_at' => now(),
        ]);

        return $this->append($entry);
    }

    /**
     * Catat tindakan TANPA pelaku, apa pun yang sedang masuk — RULE-09.
     *
     * MENGAPA METODE INI ADA, dan mengapa ia tidak dapat digantikan dengan
     * memanggil log() dengan `actor: null`: log() melakukan
     * `$actor ??= auth()->user()`, sehingga null justru berarti "pakai
     * pengguna yang sedang masuk". Itu perilaku yang benar untuk hampir
     * seluruh pencatatan — tetapi ada satu hal yang menuntut sebaliknya.
     *
     * Pengaduan ANONIM tidak boleh punya satu pun jejak yang menautkannya ke
     * seseorang (RULE-09). Baris audit biasa menyimpan actor_id, actor_name,
     * dan actor_roles; pada pengaduan anonim yang dikirim warga yang sedang
     * masuk, satu baris itu berisi tepat tautan yang dilarang ada di tabel
     * pengaduan — lengkap dengan namanya. CHECK `chk_complaints_anon` tidak
     * menjaga audit log.
     *
     * Model SENGAJA tidak dapat diserahkan ke metode ini: baris tanpa pelaku
     * tetapi dengan entity_id masih dapat ditautkan lewat ip_address dan
     * waktunya. Yang tersisa hanyalah fakta bahwa sesuatu terjadi — cukup
     * untuk memantau volume dan penyalahgunaan, tidak cukup untuk menunjuk
     * siapa pun.
     *
     * @param  array<array-key, mixed>|null  $context
     */
    public function logWithoutActor(string $action, ?array $context = null): AuditLog
    {
        $entry = new AuditLog([
            'actor_id' => null,
            'actor_name' => 'sistem',
            'actor_roles' => null,
            'action' => $action,
            'entity_type' => null,
            'entity_id' => null,
            'changes' => null,
            'context' => $context === null ? null : $this->redactor->redact($context),
            /*
             * ip_address TIDAK diisi, berbeda dari log() yang selalu mengisinya
             * dari request(). Alamat IP adalah justru jalan tautnya: baris
             * login warga membawa alamat yang sama, sehingga satu JOIN
             * mengembalikan nama yang baru saja dihapus di atas.
             */
            'ip_address' => null,
            'user_agent' => null,
            'correlation_id' => $this->correlationId(),
            'created_at' => now(),
        ]);

        return $this->append($entry);
    }

    /**
     * Rantai satu baris ke ujung rantai, lalu simpan — SATU-SATUNYA jalan
     * masuk ke audit_logs dari aplikasi.
     *
     * MENGAPA ADA DUA PENGUNCIAN, DAN MENGAPA YANG PERTAMA TIDAK BOLEH
     * DIHAPUS meski nilainya tidak pernah dibaca:
     *
     * Perangkaian menuntut agar tidak ada dua permintaan yang membaca ujung
     * rantai yang sama — tanpa itu keduanya memakai `prev_hash` yang sama dan
     * rantainya bercabang, yang justru hal yang dijaga ADR-005. Penguncian
     * yang jelas untuk itu adalah `ORDER BY id DESC LIMIT 1 FOR UPDATE`, dan
     * itulah yang dipakai sampai KEP-020. Masalahnya: pada InnoDB kunci itu
     * adalah next-key lock di UJUNG indeks — ia mencakup celah sesudah baris
     * terakhir. Dua transaksi yang memegang celah itu lalu sama-sama hendak
     * menyisipkan ke dalamnya saling memblokir, dan MySQL membunuh salah
     * satunya dengan 1213. Dua penulisan audit biasa sudah cukup; tidak perlu
     * sumber daya ketiga. Uji beban Sprint 7 menangkapnya di jalur login, dan
     * bagi petugas yang mengalaminya ia berupa HTTP 500 (docs/23 §7 butir 1).
     *
     * Baris mutex `audit_chain_lock` menghapus penyebabnya. Kunci baris pada
     * pencarian kesetaraan kunci primer tidak mengambil gap lock apa pun,
     * sehingga permintaan kedua hanya MENUNGGU yang pertama selesai alih-alih
     * membentuk siklus dengannya. Dan karena hanya satu transaksi yang dapat
     * berada di antara mutex dan commit-nya, `FOR UPDATE` di bawahnya tidak
     * lagi pernah diperebutkan — ia tetap ada, karena ia yang memastikan
     * ujung rantai dibaca dalam versi TERBARU dan bukan dari snapshot
     * REPEATABLE READ transaksi pemanggil, yang dapat lebih tua daripada
     * baris audit yang baru saja di-commit permintaan lain.
     *
     * Urutannya WAJIB tetap: mutex dulu, ujung rantai sesudahnya.
     *
     * CATATAN UNTUK PEMANGGIL: metode ini hampir selalu berjalan di dalam
     * transaksi pemanggil, sehingga mutex-nya dilepas saat transaksi TERLUAR
     * commit, bukan saat metode ini selesai. Akibatnya setiap penulisan yang
     * diaudit serentak pada satu titik — itu memang sudah begitu sebelum
     * KEP-020 dan bukan hal baru, tetapi ia berarti satu hal yang harus
     * dipatuhi: baris audit dicatat SETELAH penulisan datanya, tidak sebelum.
     * Layanan yang mengunci baris data SESUDAH memanggil pencatat ini
     * mengembalikan kemungkinan deadlock lewat pintu lain — kali ini antara
     * mutex dan tabel datanya.
     */
    private function append(AuditLog $entry): AuditLog
    {
        return DB::transaction(function () use ($entry): AuditLog {
            /*
             * JEBAKAN YANG SAMA SEPERTI LetterNumberService::allocate():
             * `FOR UPDATE` pada baris yang BELUM ADA tidak mengunci apa pun di
             * MySQL — ia hanya mengembalikan nol baris, tanpa satu pun galat
             * yang memberi tahu bahwa mutex-nya baru saja menjadi tiada.
             * Karena itu ketiadaannya diperiksa, bukan diandaikan.
             *
             * Barisnya dibuat migrasinya dan tidak pernah berubah, jadi
             * penyisipannya ada di jalur DINGIN saja — berbeda dari
             * letter_counters, yang barisnya memang berbeda per tahun dan per
             * jenis surat sehingga harus disisipkan setiap kali. Metode ini
             * berjalan pada setiap penulisan yang diaudit di seluruh
             * aplikasi; satu pernyataan SQL yang hampir selalu tidak
             * mengerjakan apa pun bukan hal yang pantas dibayar di situ.
             */
            if (DB::table('audit_chain_lock')->where('id', 1)->lockForUpdate()->first() === null) {
                DB::statement('INSERT INTO audit_chain_lock (id) VALUES (1)
                               ON DUPLICATE KEY UPDATE id = id');

                DB::table('audit_chain_lock')->where('id', 1)->lockForUpdate()->first();
            }

            $previous = AuditLog::query()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'row_hash']);

            $entry->prev_hash = $previous?->row_hash;
            $entry->hash_version = AuditLog::HASH_VERSION;
            $entry->row_hash = $entry->computeHash($previous?->row_hash);
            $entry->save();

            return $entry;
        });
    }

    /**
     * Catat perubahan model: hanya field yang BENAR-BENAR berubah,
     * beserta nilai lama dan baru (FR-PDK-07).
     *
     * `$before` WAJIB ditangkap pemanggil SEBELUM save(), karena Eloquent
     * menyinkronkan atribut asli di dalam save(): sesudahnya `getOriginal()`
     * sudah mengembalikan nilai BARU, sehingga riwayat akan mencatat
     * "lama = baru" dan menjadi tidak berguna.
     *
     * Parameter dibuat wajib (bukan opsional) justru agar hal itu tidak
     * mungkin terlupakan.
     *
     * @param  array<string, mixed>  $before  hasil $model->getOriginal() sebelum save
     */
    public function logModelChange(string $action, Model $model, array $before): ?AuditLog
    {
        $changes = [];

        foreach ($model->getChanges() as $field => $new) {
            if (in_array($field, ['updated_at', 'created_at'], true)) {
                continue;
            }

            $old = $before[$field] ?? null;

            // Abaikan "perubahan" yang sebenarnya tidak mengubah apa pun
            // (mis. '1' menjadi 1) agar riwayat tidak berisi derau.
            if ((string) $old === (string) $new) {
                continue;
            }

            $changes[$field] = ['old' => $old, 'new' => $new];
        }

        if ($changes === []) {
            return null;
        }

        return $this->log($action, $model, $changes);
    }

    /**
     * Pengungkapan NIK penuh — RULE-07. Dipanggil SETIAP KALI petugas menekan
     * tombol "lihat NIK". Tanpa ini, tidak ada cara mengetahui siapa yang
     * mengumpulkan data pribadi warga.
     */
    public function logPiiReveal(Model $entity, string $field): AuditLog
    {
        return $this->log(
            self::ACTION_PII_REVEALED,
            $entity,
            context: ['field' => $field],
        );
    }

    /** Ekspor data — selalu dicatat beserta jumlah baris & filter (SRS §4.9). */
    /** @param  array<string, mixed>  $filters */
    public function logExport(string $entityType, int $rowCount, array $filters): AuditLog
    {
        return $this->log(
            self::ACTION_EXPORTED,
            context: [
                'entity_type' => $entityType,
                'rows' => $rowCount,
                'filters' => $filters,
            ],
        );
    }

    /**
     * Verifikasi keutuhan rantai hash.
     *
     * DUA HAL YANG MEMBUAT METODE INI TAMPAK LEBIH RUMIT DARI SEHARUSNYA,
     * dan keduanya dibayar dengan angka:
     *
     * 1. **`lazyById()`, bukan `lazy()`.** Perbedaannya satu kata dan
     *    keduanya benar — hanya salah satunya yang masih benar pada satu
     *    juta baris. `lazy()` memecah kueri dengan LIMIT/OFFSET, sehingga
     *    MySQL harus MELEWATI seluruh baris sebelum setiap potongan: jumlah
     *    baris yang dilewati tumbuh dengan kuadrat jumlah potongan. Terukur
     *    pada 1,5 juta baris: setiap kali datanya dua kali lebih banyak,
     *    waktunya tiga setengah kali lebih lama (docs/23 §7 butir 5).
     *    `lazyById()` memecahnya dengan `id > terakhir`, yang memakai kunci
     *    primer dan tidak melewati apa pun.
     *
     * 2. **Baris MENTAH, bukan model.** Hash dihitung dari representasi
     *    mentah baris, sehingga 1,5 juta objek Eloquent beserta cast JSON
     *    dua kolomnya tidak perlu dibuat sama sekali. Itu biaya kedua yang
     *    terukur di temuan yang sama.
     *
     * TENTANG BARIS v1 (hash_version = 1, yaitu seluruh baris sebelum
     * KEP-020): hash-nya TIDAK dihitung ulang, dan itu bukan kelonggaran
     * melainkan satu-satunya pernyataan yang jujur. Rumus v1 meng-encode
     * kolom JSON menurut urutan kunci saat penulisan, dan urutan itu HILANG
     * saat MySQL menyimpannya — tidak ada cara mereproduksinya dari apa yang
     * tersimpan. Yang masih dapat diperiksa untuk baris v1 adalah
     * SAMBUNGANNYA (`prev_hash` terhadap `row_hash` baris sebelumnya), dan
     * itu tetap mendeteksi penyisipan serta penghapusan baris. Yang tidak
     * terdeteksi pada baris v1 adalah perubahan medan di tempat — yang pada
     * baris v1 memang tidak pernah terdeteksi: untuk `changes` karena
     * kolomnya tidak ikut di-hash, untuk `context` karena alarmnya berbunyi
     * atas data jujur sehingga tidak dapat dipercaya. Jumlahnya dilaporkan
     * terpisah agar tidak pernah terbaca sebagai baris yang terverifikasi.
     *
     * @return array{valid: bool, checked: int, broken_at: int|null, unverifiable: int}
     */
    public function verifyChain(?int $limit = null): array
    {
        $previousHash = null;
        $checked = 0;
        $unverifiable = 0;

        $query = DB::table('audit_logs')
            ->select([
                'id', 'actor_id', 'actor_name', 'action', 'entity_type', 'entity_id',
                'changes', 'context', 'ip_address', 'created_at',
                'prev_hash', 'row_hash', 'hash_version',
            ]);

        foreach ($query->lazyById(500) as $row) {
            $broken = $row->prev_hash !== $previousHash;

            if (! $broken && (int) $row->hash_version === AuditLog::HASH_VERSION) {
                $broken = $row->row_hash !== AuditLog::hashFromRow((array) $row, $previousHash);
            } elseif (! $broken) {
                $unverifiable++;
            }

            if ($broken) {
                return [
                    'valid' => false,
                    'checked' => $checked,
                    'broken_at' => (int) $row->id,
                    'unverifiable' => $unverifiable,
                ];
            }

            $previousHash = $row->row_hash;
            $checked++;

            if ($limit !== null && $checked >= $limit) {
                break;
            }
        }

        return [
            'valid' => true,
            'checked' => $checked,
            'broken_at' => null,
            'unverifiable' => $unverifiable,
        ];
    }

    private function correlationId(): string
    {
        return (string) (request()->attributes->get('correlation_id') ?? Str::uuid7());
    }
}
