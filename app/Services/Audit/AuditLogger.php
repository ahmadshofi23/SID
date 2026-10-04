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
     * Catat perubahan model: hanya field yang BENAR-BENAR berubah,
     * beserta nilai lama dan baru (FR-PDK-07).
     */
    public function logModelChange(string $action, Model $model): ?AuditLog
    {
        $changes = [];

        foreach ($model->getChanges() as $field => $new) {
            if (in_array($field, ['updated_at', 'created_at'], true)) {
                continue;
            }

            $changes[$field] = [
                'old' => $model->getOriginal($field),
                'new' => $new,
            ];
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
