<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ChevronRight, Pencil, History, Users } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import StatusBadge from '@/Components/StatusBadge.vue'
import SensitiveValue from '@/Components/SensitiveValue.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import { usePageData } from '@/composables/usePage'
import type { HistoryEntry, Resident } from '@/types'

const props = defineProps<{
    resident: Resident & Record<string, string | null | number>
    members: Array<{ id: string; full_name: string; relation: string; age: number; status: string; status_label: string }>
    history: HistoryEntry[]
    family: { id: string; kk_number_masked: string } | null
}>()

const { can } = usePageData()

const identitas = [
    ['Nama lengkap', props.resident.full_name],
    ['Tempat, tanggal lahir', `${props.resident.birth_place || '—'}, ${props.resident.birth_date_label}`],
    ['Jenis kelamin', props.resident.gender_label],
    ['Agama', props.resident.religion],
    ['Status perkawinan', props.resident.marital_status],
    ['Pendidikan', props.resident.education],
    ['Pekerjaan', props.resident.occupation],
    ['Golongan darah', props.resident.blood_type],
    ['Kewarganegaraan', props.resident.citizenship],
    ['Disabilitas', props.resident.disability],
] as Array<[string, string | null | number]>

const keluarga = [
    ['Hubungan dalam keluarga', props.resident.family_relation],
    ['Alamat', props.resident.address],
    ['Wilayah', props.resident.region_full],
    ['Nama ayah', props.resident.father_name],
    ['Nama ibu', props.resident.mother_name],
    ['Telepon', props.resident.phone],
] as Array<[string, string | null | number]>
</script>

<template>
    <Head :title="resident.full_name" />

    <AppLayout>
        <nav class="mb-4 flex items-center gap-1 text-sm text-content-tertiary" aria-label="Remah roti">
            <Link href="/kependudukan/penduduk" class="hover:text-content-secondary hover:underline">
                Data Penduduk
            </Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <span class="truncate text-content-secondary">{{ resident.full_name }}</span>
        </nav>

        <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold">{{ resident.full_name }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-content-secondary">
                    <StatusBadge :status="resident.status" :label="resident.status_label" />
                    <span>{{ resident.age }} tahun</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ resident.region_full ?? 'Wilayah belum ditetapkan' }}</span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <AppButton
                    v-if="can('penduduk.ubah')"
                    :href="`/kependudukan/penduduk/${resident.id}/ubah`"
                    variant="secondary"
                >
                    <Pencil class="size-4" aria-hidden="true" />
                    Ubah Data
                </AppButton>

                <ConfirmDialog
                    v-if="can('penduduk.hapus')"
                    :action="`/kependudukan/penduduk/${resident.id}`"
                    :title="`Hapus data ${resident.full_name}?`"
                    body="Data tidak hilang permanen — ia disembunyikan dan masih dapat dipulihkan Admin Desa. Surat yang pernah terbit atas nama orang ini tetap tersimpan."
                    confirm-label="Ya, hapus data"
                />
            </div>
        </header>

        <AppAlert v-if="resident.status !== 'aktif'" type="warning"
                  title="Penduduk tidak aktif" class="mb-5">
            Penduduk berstatus <strong>{{ resident.status_label }}</strong> tidak dihitung
            dalam statistik dan tidak dapat mengajukan surat.
        </AppAlert>

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <!-- Identitas -->
                <section class="card p-5">
                    <h2 class="mb-4 font-semibold">Identitas</h2>

                    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-[minmax(0,11rem)_1fr]">
                        <dt class="text-sm text-content-tertiary">NIK</dt>
                        <dd class="mb-2 sm:mb-0">
                            <SensitiveValue
                                :masked="resident.nik_masked"
                                :reveal-url="`/kependudukan/penduduk/${resident.id}/nik`"
                                :can-reveal="can('data_pribadi.lihat_penuh')"
                            />
                        </dd>

                        <template v-for="[label, value] in identitas" :key="label">
                            <dt class="text-sm text-content-tertiary">{{ label }}</dt>
                            <dd class="mb-2 text-sm sm:mb-0">{{ value || '—' }}</dd>
                        </template>
                    </dl>
                </section>

                <!-- Keluarga & alamat -->
                <section class="card p-5">
                    <h2 class="mb-4 font-semibold">Keluarga &amp; alamat</h2>

                    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-[minmax(0,11rem)_1fr]">
                        <dt class="text-sm text-content-tertiary">Kartu keluarga</dt>
                        <dd class="mb-2 text-sm sm:mb-0">
                            <Link
                                v-if="family"
                                :href="`/kependudukan/keluarga/${family.id}`"
                                class="font-mono text-accent hover:underline"
                            >
                                {{ family.kk_number_masked }}
                            </Link>
                            <span v-else class="text-content-tertiary">
                                Belum tergabung dalam kartu keluarga
                            </span>
                        </dd>

                        <template v-for="[label, value] in keluarga" :key="label">
                            <dt class="text-sm text-content-tertiary">{{ label }}</dt>
                            <dd class="mb-2 text-sm sm:mb-0">{{ value || '—' }}</dd>
                        </template>
                    </dl>
                </section>

                <!-- Riwayat perubahan -->
                <section class="card p-5">
                    <div class="mb-1 flex items-center gap-2">
                        <History class="size-4 text-content-tertiary" aria-hidden="true" />
                        <h2 class="font-semibold">Riwayat perubahan</h2>
                    </div>
                    <p class="mb-4 text-sm text-content-tertiary">
                        Setiap perubahan tercatat permanen dan tidak dapat dihapus.
                    </p>

                    <p v-if="history.length === 0" class="text-sm text-content-tertiary">
                        Belum ada riwayat tercatat.
                    </p>

                    <ol v-else class="space-y-0">
                        <li
                            v-for="(entry, i) in history"
                            :key="i"
                            class="relative border-l-2 border-line py-3 pl-5 last:pb-0"
                        >
                            <span
                                class="absolute -left-[5px] top-4 size-2 rounded-full bg-accent"
                                aria-hidden="true"
                            />
                            <p class="text-sm font-medium">{{ entry.aksi }}</p>
                            <p class="mt-0.5 text-xs text-content-tertiary">
                                {{ entry.pelaku }} · {{ entry.waktu }}
                            </p>

                            <ul v-if="entry.perubahan.length" class="mt-1.5 space-y-0.5">
                                <li
                                    v-for="change in entry.perubahan"
                                    :key="change.field"
                                    class="text-sm text-content-secondary"
                                >
                                    {{ change.field }}:
                                    <span class="text-content-tertiary line-through">{{ change.lama }}</span>
                                    <span aria-hidden="true"> → </span>
                                    <span class="sr-only"> menjadi </span>
                                    <span class="font-medium text-content">{{ change.baru }}</span>
                                </li>
                            </ul>

                            <p v-if="entry.alasan" class="mt-1.5 text-sm text-content-secondary">
                                Alasan: {{ entry.alasan }}
                            </p>
                        </li>
                    </ol>
                </section>
            </div>

            <!-- Anggota keluarga -->
            <aside v-if="members.length" class="lg:col-span-1">
                <section class="card p-5 lg:sticky lg:top-20">
                    <div class="mb-4 flex items-center gap-2">
                        <Users class="size-4 text-content-tertiary" aria-hidden="true" />
                        <h2 class="font-semibold">Anggota keluarga ({{ members.length }})</h2>
                    </div>

                    <ul class="divide-y divide-[var(--color-line)]">
                        <li v-for="m in members" :key="m.id" class="py-2.5 first:pt-0 last:pb-0">
                            <Link
                                v-if="m.id !== resident.id"
                                :href="`/kependudukan/penduduk/${m.id}`"
                                class="text-sm font-medium text-accent hover:underline"
                            >
                                {{ m.full_name }}
                            </Link>
                            <span v-else class="text-sm font-semibold">
                                {{ m.full_name }}
                                <span class="font-normal text-content-tertiary">(orang ini)</span>
                            </span>

                            <p class="text-xs text-content-tertiary">
                                {{ m.relation }} · {{ m.age }} tahun
                            </p>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
