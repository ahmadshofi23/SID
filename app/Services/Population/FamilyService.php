<?php

declare(strict_types=1);

namespace App\Services\Population;

use App\Models\Family;
use App\Models\Resident;
use App\Repositories\FamilyRepository;
use App\Repositories\ResidentRepository;
use App\Services\Audit\AuditLogger;
use App\Support\Pii\Nik;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan bisnis kartu keluarga — FR-PDK-08, FR-PDK-09.
 */
final class FamilyService
{
    public function __construct(
        private readonly FamilyRepository $families,
        private readonly ResidentRepository $residents,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Family
    {
        $kkNumber = Nik::normalize((string) $data['kk_number']);

        $this->guardKkUnique($kkNumber, null);

        return DB::transaction(function () use ($data, $kkNumber): Family {
            $family = new Family;
            $family->setKkNumber($kkNumber);
            $family->fill($this->fillable($data));
            $family->save();

            $this->audit->log('family.created', $family, context: [
                'alamat' => $family->address,
            ]);

            return $family;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Family $family, array $data): Family
    {
        if (isset($data['kk_number'])) {
            $kkNumber = Nik::normalize((string) $data['kk_number']);

            if ($kkNumber !== $family->kk_number_enc) {
                $this->guardKkUnique($kkNumber, $family->id);
                $family->setKkNumber($kkNumber);
            }
        }

        return DB::transaction(function () use ($family, $data): Family {
            $before = $family->getOriginal();

            $family->fill($this->fillable($data));
            $family->save();

            $this->audit->logModelChange('family.updated', $family, $before);

            return $family;
        });
    }

    /**
     * Pindahkan penduduk ke kartu keluarga lain — FR-PDK-09.
     *
     * Kasus nyata: anak menikah dan membentuk KK baru, atau orang tua ikut
     * tinggal dengan anaknya.
     */
    public function moveMember(
        Resident $resident,
        Family $target,
        string $newRelation,
        string $reason,
    ): void {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pemindahan wajib diisi agar riwayat dapat ditelusuri.',
            ]);
        }

        if ($newRelation === 'kepala_keluarga') {
            $existingHead = $this->residents->membersOfFamily($target->id)
                ->first(fn (Resident $r) => $r->isHeadOfFamily() && $r->id !== $resident->id);

            if ($existingHead !== null) {
                throw ValidationException::withMessages([
                    'family_relation_code' => sprintf(
                        'Kartu keluarga tujuan sudah memiliki kepala keluarga: %s.',
                        $existingHead->full_name
                    ),
                ]);
            }
        }

        $origin = $resident->family;

        /*
         * Peringatkan bila KK asal menjadi tanpa kepala keluarga (RULE-02).
         * Tidak diblokir: dalam praktik, kepala keluarga memang kadang pindah
         * lebih dulu dan penggantinya ditetapkan kemudian. Memblokir akan
         * membuat petugas tidak dapat mencatat kenyataan di lapangan.
         */
        $originLosesHead = $origin !== null
            && $resident->isHeadOfFamily()
            && $origin->id !== $target->id;

        DB::transaction(function () use (
            $resident, $target, $newRelation, $reason, $origin, $originLosesHead
        ): void {
            $this->audit->log('family.member_moved', $resident, changes: [
                'family_id' => ['old' => $resident->family_id, 'new' => $target->id],
                'family_relation_code' => [
                    'old' => $resident->family_relation_code, 'new' => $newRelation,
                ],
            ], context: [
                'alasan' => $reason,
                'kk_asal' => $origin?->kk_number_masked,
                'kk_tujuan' => $target->kk_number_masked,
                'kk_asal_kehilangan_kepala' => $originLosesHead,
            ]);

            $resident->family_id = $target->id;
            $resident->family_relation_code = $newRelation;
            $resident->region_id = $target->region_id;
            $resident->address = $target->address;
            $resident->save();
        });
    }

    public function delete(Family $family, string $reason): void
    {
        $memberCount = $this->residents->membersOfFamily($family->id)->count();

        if ($memberCount > 0) {
            throw ValidationException::withMessages([
                'family' => sprintf(
                    'Kartu keluarga ini masih memiliki %d anggota. Pindahkan '
                    .'anggotanya ke kartu keluarga lain terlebih dahulu.',
                    $memberCount
                ),
            ]);
        }

        DB::transaction(function () use ($family, $reason): void {
            $this->audit->log('family.deleted', $family, context: ['alasan' => $reason]);
            $family->delete();
        });
    }

    public function revealKkNumber(Family $family): ?string
    {
        $this->audit->logPiiReveal($family, 'kk_number');

        return $family->kk_number_enc;
    }

    private function guardKkUnique(string $kkNumber, ?string $exceptId): void
    {
        if ($this->families->kkNumberTakenByOther($kkNumber, $exceptId)) {
            throw ValidationException::withMessages([
                'kk_number' => 'Nomor kartu keluarga ini sudah terdaftar. '
                    .'Periksa kembali, atau hubungi Admin Desa.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillable(array $data): array
    {
        $allowed = [
            'region_id', 'address', 'rt', 'rw', 'postal_code',
            'welfare_status_code', 'house_ownership_code',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }
}
