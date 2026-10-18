<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { UserCheck, FileImage, Building2, TriangleAlert } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import EmptyState from '@/Components/EmptyState.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

interface PendingResident {
    id: string
    nik_masked: string
    full_name: string
    birth_date: string | null
    address: string | null
    region: string | null
}

interface Pending {
    id: string
    name: string
    email: string | null
    phone: string | null
    verification_method: string | null
    has_document: boolean
    created_at: string | null
    resident: PendingResident | null
}

defineProps<{
    pending: Pending[]
    recent: { id: string; name: string; status: string; verified_at: string | null; note: string | null }[]
}>()

const approve = useForm({})

function setujui(id: string): void {
    approve.post(`/pengaturan/verifikasi-warga/${id}/setujui`, { preserveScroll: true })
}

function tanggal(iso: string | null): string {
    if (!iso) return '—'
    return new Date(iso).toLocaleDateString('id-ID', {
        day: 'numeric', month: 'long', year: 'numeric',
    })
}

const metodeLabel: Record<string, string> = {
    ktp_upload: 'Unggah foto KTP',
    tatap_muka: 'Tatap muka di kantor',
}
</script>

<template>
    <Head title="Verifikasi Akun Warga" />

    <AppLayout>
        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Verifikasi Akun Warga</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Pendaftaran warga yang menunggu diperiksa. Akun baru dapat dipakai
                masuk setelah Anda setujui.
            </p>
        </header>

        <AppAlert type="warning" class="mb-5" title="Cocokkan tiga hal sebelum menyetujui">
            Nama dan alamat pada data kependudukan, identitas pada KTP, dan orang
            yang Anda hadapi. Menyetujui akun berarti memberi orang itu akses ke
            data satu keluarga penuh — termasuk mengajukan surat atas nama mereka.
        </AppAlert>

        <EmptyState
            v-if="pending.length === 0"
            :icon="UserCheck"
            title="Tidak ada yang menunggu"
            description="Pendaftaran akun warga yang baru masuk akan muncul di sini."
        />

        <ul v-else class="space-y-4">
            <li v-for="orang in pending" :key="orang.id" class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ orang.name }}</p>
                        <p class="mt-0.5 text-sm text-content-secondary">
                            Mendaftar {{ tanggal(orang.created_at) }} ·
                            {{ metodeLabel[orang.verification_method ?? ''] ?? 'Tidak disebutkan' }}
                        </p>
                    </div>

                    <span v-if="orang.verification_method === 'tatap_muka'"
                          class="inline-flex items-center gap-1.5 rounded-md bg-info-soft px-2 py-0.5 text-xs font-medium text-info">
                        <Building2 class="size-3.5" aria-hidden="true" />
                        Minta warga datang
                    </span>
                </div>

                <!--
                  Panel data penduduk kosong bila pemohon berada di luar lingkup
                  wilayah petugas — bukan 403. Petugas tetap melihat barisnya,
                  tetapi tidak datanya, dan dapat meneruskan ke Admin Desa.
                -->
                <div v-if="orang.resident" class="mt-4 grid gap-4 rounded-lg bg-surface-sunken p-4 text-sm sm:grid-cols-2">
                    <div>
                        <p class="text-content-tertiary">Nama pada data kependudukan</p>
                        <p class="font-medium">{{ orang.resident.full_name }}</p>
                    </div>
                    <div>
                        <p class="text-content-tertiary">NIK</p>
                        <p class="tabular font-medium">{{ orang.resident.nik_masked }}</p>
                    </div>
                    <div>
                        <p class="text-content-tertiary">Tanggal lahir</p>
                        <p class="tabular font-medium">{{ tanggal(orang.resident.birth_date) }}</p>
                    </div>
                    <div>
                        <p class="text-content-tertiary">Alamat</p>
                        <p class="font-medium">{{ orang.resident.address ?? '—' }}</p>
                        <p class="text-content-secondary">{{ orang.resident.region ?? '' }}</p>
                    </div>
                </div>

                <AppAlert v-else type="warning" class="mt-4"
                          title="Data penduduk tidak terlihat dari akun Anda">
                    Pemohon berada di luar wilayah yang Anda tangani. Teruskan ke
                    Admin Desa untuk diperiksa.
                </AppAlert>

                <div class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <p class="text-content-tertiary">Email</p>
                        <p class="font-medium">{{ orang.email ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-content-tertiary">Nomor ponsel</p>
                        <p class="tabular font-medium">{{ orang.phone ?? '—' }}</p>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <AppButton v-if="orang.has_document" variant="secondary"
                               :href="`/pengaturan/verifikasi-warga/${orang.id}/dokumen`">
                        <FileImage class="size-4" aria-hidden="true" />
                        Lihat foto KTP
                    </AppButton>

                    <span v-else-if="orang.verification_method === 'ktp_upload'"
                          class="inline-flex items-center gap-1.5 text-sm text-warning">
                        <TriangleAlert class="size-4" aria-hidden="true" />
                        Foto KTP tidak tersimpan
                    </span>

                    <AppButton variant="primary" :disabled="!orang.resident"
                               :loading="approve.processing" @click="setujui(orang.id)">
                        <UserCheck class="size-4" aria-hidden="true" />
                        Setujui &amp; aktifkan
                    </AppButton>

                    <div class="ml-auto">
                        <ConfirmDialog
                            :action="`/pengaturan/verifikasi-warga/${orang.id}/tolak`"
                            method="post"
                            title="Tolak pendaftaran ini?"
                            :body="`Akun ${orang.name} tidak akan dapat dipakai masuk. Alasan di bawah dibacakan kepada warga bila ia datang bertanya.`"
                            confirm-label="Tolak pendaftaran"
                            trigger-label="Tolak"
                            reason-placeholder="Contoh: nama pada KTP berbeda dengan data kependudukan"
                        />
                    </div>
                </div>

                <p class="mt-3 text-xs text-content-tertiary">
                    Foto KTP dihapus dari sistem segera setelah Anda menyetujui atau
                    menolak — desa tidak menyimpan arsip foto KTP warganya.
                </p>
            </li>
        </ul>

        <section v-if="recent.length > 0" class="mt-10" aria-labelledby="judul-riwayat">
            <h2 id="judul-riwayat" class="text-lg font-semibold">Diperiksa belakangan ini</h2>

            <ul class="card mt-3 divide-y divide-line">
                <li v-for="orang in recent" :key="orang.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 p-4 text-sm">
                    <span class="font-medium">{{ orang.name }}</span>
                    <span :class="orang.status === 'aktif' ? 'text-success' : 'text-content-secondary'">
                        {{ orang.status === 'aktif' ? 'Disetujui' : 'Ditolak' }}
                    </span>
                    <span class="text-content-tertiary">{{ tanggal(orang.verified_at) }}</span>
                    <span v-if="orang.note" class="w-full text-content-secondary">{{ orang.note }}</span>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
