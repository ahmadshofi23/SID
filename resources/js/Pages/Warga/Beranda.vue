<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { FilePlus2, FileClock, Search, Users } from '@lucide/vue'
import WargaLayout from '@/Layouts/WargaLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import LetterStatusBadge from '@/Components/LetterStatusBadge.vue'
import EmptyState from '@/Components/EmptyState.vue'
import type { LetterRequestForCitizen } from '@/types'

defineProps<{
    aktif: LetterRequestForCitizen[]
    diri: { id: string; full_name: string; nik_masked: string; birth_date: string } | null
    jumlah_keluarga: number
}>()
</script>

<template>
    <Head title="Layanan Warga" />

    <WargaLayout>
        <header class="mb-6">
            <h1 class="text-2xl font-semibold">
                Selamat datang{{ diri ? `, ${diri.full_name}` : '' }}
            </h1>
            <p class="mt-1 text-sm text-content-secondary">
                Ajukan surat dan pantau statusnya tanpa datang ke kantor desa.
            </p>
        </header>

        <AppButton href="/warga/ajukan" variant="primary" block class="mb-6">
            <FilePlus2 class="size-4" aria-hidden="true" />
            Ajukan Surat
        </AppButton>

        <section aria-labelledby="judul-aktif">
            <div class="mb-3 flex items-end justify-between gap-3">
                <h2 id="judul-aktif" class="text-lg font-semibold">Sedang diproses</h2>
                <Link href="/warga/pengajuan" class="text-sm font-medium text-accent hover:underline">
                    Semua pengajuan &rarr;
                </Link>
            </div>

            <EmptyState
                v-if="aktif.length === 0"
                :icon="FileClock"
                title="Tidak ada pengajuan berjalan"
                description="Pengajuan yang sedang diproses petugas akan tampil di sini."
            />

            <ul v-else class="space-y-3">
                <li v-for="p in aktif" :key="p.id">
                    <Link :href="`/warga/pengajuan/${p.id}`"
                          class="card block p-4 transition-colors hover:bg-surface-hover">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-medium">{{ p.letter_type }}</p>
                                <p class="tabular mt-0.5 text-xs text-content-tertiary">
                                    {{ p.ticket_number }} · a.n. {{ p.resident_name }}
                                </p>
                            </div>
                            <LetterStatusBadge :status="p.status" :label="p.status_label" />
                        </div>

                        <p v-if="p.due_at" class="mt-2 text-xs"
                           :class="p.is_overdue ? 'font-medium text-warning' : 'text-content-secondary'">
                            Perkiraan selesai {{ p.due_at }}
                        </p>
                    </Link>
                </li>
            </ul>
        </section>

        <section class="mt-8 grid gap-3 sm:grid-cols-2" aria-label="Tautan lain">
            <Link href="/warga/keluarga" class="card flex items-start gap-3 p-4 transition-colors hover:bg-surface-hover">
                <Users class="mt-0.5 size-5 shrink-0 text-accent" aria-hidden="true" />
                <span>
                    <span class="block font-medium">Data keluarga saya</span>
                    <span class="block text-sm text-content-secondary">
                        {{ jumlah_keluarga }} anggota pada satu Kartu Keluarga.
                    </span>
                </span>
            </Link>

            <!--
              Pelacakan publik SENGAJA tetap ditautkan dari dalam portal warga.
              Menurut KEP-014 §5 halaman itu tetap hidup sebagai "cek resi"
              tanpa login, dan warga sering memegang tiket pengajuan loket
              milik keluarga yang belum punya akun.
            -->
            <a href="/surat/lacak" class="card flex items-start gap-3 p-4 transition-colors hover:bg-surface-hover">
                <Search class="mt-0.5 size-5 shrink-0 text-accent" aria-hidden="true" />
                <span>
                    <span class="block font-medium">Lacak tiket lain</span>
                    <span class="block text-sm text-content-secondary">
                        Untuk pengajuan di loket atas nama orang lain.
                    </span>
                </span>
            </a>
        </section>
    </WargaLayout>
</template>
