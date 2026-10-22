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
 * tidak menghasilkan rantai bercabang.
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

        return DB::transaction(function () use ($entry): AuditLog {
            $previous = AuditLog::query()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'row_hash']);

            $entry->prev_hash = $previous?->row_hash;
            $entry->row_hash = $entry->computeHash($previous?->row_hash);
            $entry->save();

            return $entry;
        });
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

        return DB::transaction(function () use ($entry): AuditLog {
            $previous = AuditLog::query()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'row_hash']);

            $entry->prev_hash = $previous?->row_hash;
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
     * @return array{valid: bool, checked: int, broken_at: int|null}
     */
    public function verifyChain(?int $limit = null): array
    {
        $previousHash = null;
        $checked = 0;

        $query = AuditLog::query()->orderBy('id');

        foreach ($query->lazy(500) as $entry) {
            if ($entry->prev_hash !== $previousHash
                || $entry->row_hash !== $entry->computeHash($previousHash)) {
                return ['valid' => false, 'checked' => $checked, 'broken_at' => $entry->id];
            }

            $previousHash = $entry->row_hash;
            $checked++;

            if ($limit !== null && $checked >= $limit) {
                break;
            }
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at' => null];
    }

    private function correlationId(): string
    {
        return (string) (request()->attributes->get('correlation_id') ?? Str::uuid7());
    }
}
