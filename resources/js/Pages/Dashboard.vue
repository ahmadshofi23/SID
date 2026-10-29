<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import {
    Users, House, Mail, Megaphone, TriangleAlert, ArrowRightLeft, Clock,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppAlert from '@/Components/AppAlert.vue'
import BarChart from '@/Components/BarChart.vue'
import EmptyState from '@/Components/EmptyState.vue'
import ComplaintStatusBadge from '@/Components/ComplaintStatusBadge.vue'
import LetterStatusBadge from '@/Components/LetterStatusBadge.vue'
import { usePageData } from '@/composables/usePage'
import type { DashboardProps } from '@/types'

const props = defineProps<DashboardProps>()

const { user, can } = usePageData()

const nf = new Intl.NumberFormat('id-ID')

/*
 * Tren digambar sebagai batang SURAT MASUK, dengan angka selesai di
 * sampingnya — bukan dua deret bertumpuk.
 *
 * Dua deret dalam satu bagan batang CSS tanpa sumbu hanya terbaca sebagai
 * dua warna yang tidak dapat dibandingkan, dan pembaca layar menerimanya
 * sebagai deretan angka tanpa arti. Yang dicari pimpinan dari kartu ini
 * adalah bentuk bebannya dari bulan ke bulan, bukan selisih tepatnya.
 */
const trenItems = computed(() =>
    (props.tren?.bulanan ?? []).map((m) => ({
        label: m.label,
        total: m.masuk,
    })))

const piramidaItems = computed(() =>
    (props.komposisi?.piramida ?? [])
        .filter((row) => numeric(row.L) + numeric(row.P) > 0)
        .map((row) => ({
            label: row.group,
            total: typeof row.L === 'number' && typeof row.P === 'number'
                ? row.L + row.P
                : '< 5',
        })))

function numeric(value: number | string): number {
    return typeof value === 'number' ? value : 1
}

/*
 * "0 jam" terbaca seperti data yang hilang, padahal artinya justru terbaik:
 * surat loket sering terbit dalam hitungan menit. Di bawah satu jam
 * dikatakan dengan kata, bukan dibulatkan menjadi nol.
 */
const rataSelesai = computed(() => {
    const jam = props.tren?.sla.rata_jam

    if (jam === null || jam === undefined) return '—'
    if (jam < 1) return 'kurang dari 1 jam'

    return `${jam} jam`
})
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <header class="mb-6">
            <h1 class="text-2xl font-semibold">Dashboard</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Selamat datang, {{ user?.name }}.
            </p>
        </header>

        <!-- Kepala Dusun tanpa penugasan wilayah tidak melihat data apa pun.
             Tanpa penjelasan ini, ia akan mengira sistemnya rusak. -->
        <AppAlert
            v-if="user?.region_limited && !user.region_assigned"
            type="warning"
            title="Wilayah Anda belum ditetapkan"
            class="mb-6"
        >
            Anda belum ditugaskan ke wilayah mana pun, sehingga belum dapat melihat
            data penduduk. Hubungi Admin Desa untuk penugasan wilayah.
        </AppAlert>

        <!--
          ============ KPI ============
          Setiap kartu hanya muncul bila datanya BUKAN null. Null berarti
          "tidak berhak melihat", dan kartu bernilai nol bagi orang yang
          memang tidak boleh melihatnya menyatakan sesuatu yang salah.
        -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <article v-if="kependudukan" class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm text-content-secondary">Penduduk aktif</p>
                        <p class="mt-1 text-3xl font-semibold tabular">
                            {{ nf.format(kependudukan.penduduk_aktif) }}
                        </p>
                        <p class="mt-1 text-sm text-content-tertiary">
                            dari {{ nf.format(kependudukan.penduduk_total) }} tercatat
                        </p>
                    </div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                        <Users class="size-5 text-accent-text" aria-hidden="true" />
                    </span>
                </div>
                <p v-if="kependudukan.perubahan_bulan_ini !== null"
                   class="mt-3 inline-flex items-center gap-1.5 text-sm text-content-secondary">
                    <ArrowRightLeft class="size-4 text-content-tertiary" aria-hidden="true" />
                    {{ nf.format(kependudukan.perubahan_bulan_ini) }} perubahan bulan ini
                </p>
            </article>

            <article v-if="kependudukan?.keluarga !== null && kependudukan" class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm text-content-secondary">Kartu keluarga</p>
                        <p class="mt-1 text-3xl font-semibold tabular">
                            {{ nf.format(kependudukan.keluarga ?? 0) }}
                        </p>
                        <p class="mt-1 text-sm text-content-tertiary">dalam lingkup akses Anda</p>
                    </div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                        <House class="size-5 text-accent-text" aria-hidden="true" />
                    </span>
                </div>
            </article>

            <article v-if="layanan" class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm text-content-secondary">Layanan berjalan</p>
                        <p class="mt-1 text-3xl font-semibold tabular">
                            {{ nf.format(layanan.terbuka) }}
                        </p>
                        <p class="mt-1 text-sm text-content-tertiary">
                            {{ nf.format(layanan.terbit) }} surat sudah terbit
                        </p>
                    </div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                        <Mail class="size-5 text-accent-text" aria-hidden="true" />
                    </span>
                </div>
                <!--
                  Keterlambatan disebut DI DALAM kartunya, bukan di tempat
                  lain: angka "37 layanan berjalan" yang tenang padahal dua di
                  antaranya sudah lewat SLA adalah kartu yang menyesatkan.
                -->
                <p v-if="layanan.terlambat > 0"
                   class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-warning">
                    <TriangleAlert class="size-4" aria-hidden="true" />
                    {{ nf.format(layanan.terlambat) }} melewati batas waktu
                </p>
            </article>

            <article v-if="pengaduan" class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm text-content-secondary">Pengaduan terbuka</p>
                        <p class="mt-1 text-3xl font-semibold tabular">
                            {{ nf.format(pengaduan.terbuka) }}
                        </p>
                        <p class="mt-1 text-sm text-content-tertiary">
                            {{ nf.format(pengaduan.selesai) }} sudah ditangani
                        </p>
                    </div>
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                        <Megaphone class="size-5 text-accent-text" aria-hidden="true" />
                    </span>
                </div>
                <p v-if="pengaduan.terlambat > 0"
                   class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-warning">
                    <TriangleAlert class="size-4" aria-hidden="true" />
                    {{ nf.format(pengaduan.terlambat) }} melewati batas waktu
                </p>
            </article>
        </div>

        <div class="mt-6 grid gap-5 lg:grid-cols-2">
            <!--
              ============ Perlu tindakan Anda ============
              KEPUTUSAN DESAIN docs/07 §5.4: dashboard menampilkan PEKERJAAN
              YANG MENUNGGU DIRINYA di posisi menonjol, bukan sekadar angka.
              Tanpa ini, persetujuan menumpuk dan sasaran O-2 (SLA 1 jam)
              tidak tercapai.
            -->
            <section v-if="perlu_tindakan" class="card p-5" aria-labelledby="perlu-tindakan">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <h2 id="perlu-tindakan" class="font-semibold">
                        Perlu tindakan Anda
                        <span v-if="perlu_tindakan.length > 0" class="tabular">
                            ({{ perlu_tindakan.length }})
                        </span>
                    </h2>
                    <Link href="/surat" class="text-sm font-medium text-accent hover:underline">
                        Antrean surat
                    </Link>
                </div>

                <p v-if="perlu_tindakan.length === 0" class="py-6 text-center text-sm text-content-secondary">
                    Tidak ada pengajuan yang menunggu tindakan Anda.
                </p>

                <ul v-else class="space-y-2">
                    <li v-for="tugas in perlu_tindakan" :key="tugas.id">
                        <Link :href="`/surat/${tugas.id}`"
                              class="flex flex-wrap items-center justify-between gap-2 rounded-lg
                                     border border-line p-3 transition-colors hover:bg-surface-hover">
                            <span class="min-w-0">
                                <span class="block font-medium">{{ tugas.letter_type }}</span>
                                <span class="tabular block text-xs text-content-tertiary">
                                    {{ tugas.ticket_number }} · {{ tugas.resident_name }}
                                </span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                <TriangleAlert v-if="tugas.is_overdue"
                                               class="size-4 text-warning" aria-hidden="true" />
                                <LetterStatusBadge :status="tugas.status" :label="tugas.status_label" />
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- ============ Tren layanan ============ -->
            <section v-if="tren" class="card p-5" aria-labelledby="tren-layanan">
                <h2 id="tren-layanan" class="mb-1 font-semibold">Tren layanan surat</h2>
                <p class="mb-3 text-sm text-content-secondary">
                    Pengajuan masuk per bulan, 12 bulan terakhir.
                </p>

                <BarChart
                    :items="trenItems"
                    caption="Jumlah pengajuan surat masuk per bulan"
                    empty-text="Belum ada pengajuan surat."
                />

                <dl class="mt-4 grid gap-3 border-t border-line pt-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-content-tertiary">Rata-rata waktu selesai</dt>
                        <dd class="mt-0.5 font-medium">{{ rataSelesai }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-content-tertiary">Selesai tepat waktu</dt>
                        <dd class="mt-0.5 font-medium"
                            :class="tren.sla.persen_tepat !== null && tren.sla.persen_tepat < 90
                                ? 'text-warning' : ''">
                            {{ tren.sla.persen_tepat === null ? '—' : `${tren.sla.persen_tepat}%` }}
                            <span class="font-normal text-content-tertiary">
                                ({{ tren.sla.tepat_waktu }} dari {{ tren.sla.total }})
                            </span>
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- ============ Pengaduan terbuka ============ -->
            <section v-if="pengaduan_terbuka" class="card p-5" aria-labelledby="pengaduan-terbuka">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <h2 id="pengaduan-terbuka" class="font-semibold">Pengaduan terbuka</h2>
                    <Link v-if="can('pengaduan.lihat')" href="/pengaduan/antrean"
                          class="text-sm font-medium text-accent hover:underline">
                        Antrean pengaduan
                    </Link>
                </div>

                <p v-if="pengaduan_terbuka.length === 0"
                   class="py-6 text-center text-sm text-content-secondary">
                    Tidak ada pengaduan yang menunggu.
                </p>

                <ul v-else class="space-y-2">
                    <li v-for="p in pengaduan_terbuka" :key="p.id">
                        <Link :href="`/pengaduan/antrean/${p.id}`"
                              class="flex flex-wrap items-center justify-between gap-2 rounded-lg
                                     border border-line p-3 transition-colors hover:bg-surface-hover">
                            <span class="min-w-0">
                                <span class="block font-medium">{{ p.title }}</span>
                                <span class="block text-xs"
                                      :class="p.is_overdue ? 'font-medium text-warning' : 'text-content-tertiary'">
                                    {{ p.due_label ?? p.category }}
                                    <template v-if="p.region"> · {{ p.region }}</template>
                                </span>
                            </span>
                            <ComplaintStatusBadge :status="p.status" :label="p.status_label" />
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- ============ Komposisi penduduk ============ -->
            <section v-if="komposisi" class="card p-5" aria-labelledby="komposisi">
                <h2 id="komposisi" class="mb-1 font-semibold">Komposisi penduduk</h2>
                <p class="mb-3 text-sm text-content-secondary">
                    Per kelompok umur.
                    <!--
                      Penyamaran sel kecil dikatakan, bukan disembunyikan:
                      pembaca yang melihat "< 5" perlu tahu bahwa itu keputusan
                      privasi dan bukan data yang hilang (ADR-008).
                    -->
                    Kelompok dengan kurang dari lima orang ditampilkan sebagai
                    &ldquo;&lt; 5&rdquo; untuk melindungi data pribadi.
                </p>

                <BarChart
                    :items="piramidaItems"
                    caption="Jumlah penduduk per kelompok umur"
                    empty-text="Statistik belum pernah disegarkan."
                />

                <p v-if="komposisi.computed_at" class="mt-3 inline-flex items-center gap-1.5
                         text-xs text-content-tertiary">
                    <Clock class="size-3.5" aria-hidden="true" />
                    Angka ringkasan, disegarkan berkala.
                </p>
            </section>
        </div>

        <!--
          Pengguna yang seluruh bagiannya null tidak melihat satu kartu pun.
          Tanpa pesan ini ia hanya melihat halaman kosong dan menyimpulkan
          sistemnya rusak — kelas kegagalan yang sama dengan menu yang
          disembunyikan tanpa penjelasan (KEP-012).
        -->
        <EmptyState
            v-if="!kependudukan && !layanan && !pengaduan && !tren && !komposisi"
            :icon="Users"
            title="Belum ada ringkasan untuk peran Anda"
            description="Peran Anda tidak memiliki akses ke data yang diringkas di dashboard. Gunakan menu di samping untuk membuka halaman yang menjadi kewenangan Anda."
            class="mt-6"
        />
    </AppLayout>
</template>
