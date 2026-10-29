<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import {
    Download, FileText, Users, ArrowRightLeft, Mail, Megaphone, Info,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import TextField from '@/Components/TextField.vue'
import BarChart from '@/Components/BarChart.vue'
import type { ReportProps } from '@/types'

const props = defineProps<ReportProps>()

const nf = new Intl.NumberFormat('id-ID')

const periode = ref({ ...props.periode })

watch(periode, (value) => {
    router.get('/laporan', { dari: value.dari, sampai: value.sampai },
        { preserveState: true, preserveScroll: true, replace: true })
}, { deep: true })

/* Parameter periode ikut pada setiap tautan unduh, supaya berkas yang
   terunduh persis berisi angka yang sedang dilihat di layar — bukan periode
   bawaan yang kebetulan berbeda. */
const q = computed(() =>
    new URLSearchParams({ dari: periode.value.dari, sampai: periode.value.sampai }).toString())

const kategoriLabel: Record<string, string> = {
    jenis_kelamin: 'Jenis kelamin',
    agama: 'Agama',
    pendidikan: 'Pendidikan',
    pekerjaan: 'Pekerjaan',
    status_perkawinan: 'Status perkawinan',
}

const trenItems = computed(() =>
    props.layanan.tren.map((m) => ({ label: m.label, total: m.masuk })))

/*
 * Tanda hubung bila belum ada yang selesai, dan kata bila di bawah satu jam.
 * "0 jam" terbaca seperti data hilang padahal artinya justru terbaik: surat
 * loket sering terbit dalam hitungan menit.
 */
function rataJam(jam: number | null): string {
    if (jam === null) return '—'
    if (jam < 1) return 'kurang dari 1 jam'

    return `${jam} jam`
}
</script>

<template>
    <Head title="Laporan" />

    <AppLayout title="Laporan">
        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Laporan</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Rekap penduduk, mutasi, layanan surat, dan pengaduan.
            </p>
        </header>

        <!--
          KETERANGAN LINGKUP WAJIB TERLIHAT, bukan catatan kaki.
          Dua orang dapat membuka halaman ini dan melihat angka berbeda — yang
          satu Admin Desa, yang satu Kepala Dusun — dan itu BENAR. Tanpa
          kalimat ini keduanya akan dibandingkan sebagai dua versi dari angka
          yang sama, lalu salah satunya dianggap salah; yang dicurigai
          biasanya sistemnya.
        -->
        <AppAlert type="info" class="mb-5">
            <p class="flex flex-wrap items-center gap-1.5">
                <Info class="size-4 shrink-0" aria-hidden="true" />
                <span>
                    Angka di halaman ini mengikuti lingkup akses Anda:
                    <strong>{{ lingkup }}</strong>.
                </span>
            </p>
        </AppAlert>

        <div class="card mb-5 grid gap-4 p-4 sm:max-w-md sm:grid-cols-2">
            <TextField v-model="periode.dari" type="date" label="Dari tanggal" />
            <TextField v-model="periode.sampai" type="date" label="Sampai tanggal" />
        </div>

        <div class="space-y-5">
            <!-- ============ FR-LAP-03: rekap penduduk ============ -->
            <section class="card p-5" aria-labelledby="rekap-penduduk">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="rekap-penduduk" class="flex items-center gap-2 font-semibold">
                            <Users class="size-4 text-content-tertiary" aria-hidden="true" />
                            Rekap penduduk
                        </h2>
                        <p class="mt-1 text-sm text-content-secondary">
                            {{ nf.format(penduduk.total) }} penduduk aktif.
                        </p>
                    </div>
                    <AppButton v-if="izin.ekspor" size="sm" href="/laporan/penduduk.csv" external>
                        <Download class="size-4" aria-hidden="true" />
                        Unduh CSV
                    </AppButton>
                </div>

                <h3 class="mb-2 text-sm font-medium">Per wilayah</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-left text-xs text-content-tertiary">
                                <th scope="col" class="py-2 pr-3 font-medium">Dusun</th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium">Total</th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium">Laki-laki</th>
                                <th scope="col" class="py-2 text-right font-medium">Perempuan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="w in penduduk.per_wilayah" :key="w.region"
                                class="border-b border-line last:border-0">
                                <th scope="row" class="py-2 pr-3 text-left font-normal">{{ w.region }}</th>
                                <td class="tabular py-2 pr-3 text-right font-medium">{{ nf.format(w.total) }}</td>
                                <td class="tabular py-2 pr-3 text-right">{{ nf.format(w.laki) }}</td>
                                <td class="tabular py-2 text-right">{{ nf.format(w.perempuan) }}</td>
                            </tr>
                            <tr v-if="penduduk.per_wilayah.length === 0">
                                <td colspan="4" class="py-4 text-center text-content-tertiary">
                                    Belum ada data penduduk.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div v-for="(baris, kategori) in penduduk.per_kategori" :key="kategori">
                        <h3 class="mb-2 text-sm font-medium">{{ kategoriLabel[kategori] ?? kategori }}</h3>
                        <BarChart
                            :items="baris.map((b) => ({ label: b.label, total: b.jumlah }))"
                            :caption="`Jumlah penduduk per ${kategoriLabel[kategori] ?? kategori}`"
                        />
                    </div>
                </div>
            </section>

            <!-- ============ FR-LAP-04: mutasi ============ -->
            <section class="card p-5" aria-labelledby="rekap-mutasi">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="rekap-mutasi" class="flex items-center gap-2 font-semibold">
                            <ArrowRightLeft class="size-4 text-content-tertiary" aria-hidden="true" />
                            Mutasi penduduk
                        </h2>
                        <p class="mt-1 text-sm text-content-secondary">
                            {{ nf.format(mutasi.total) }} peristiwa pada periode terpilih.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="izin.ekspor" size="sm" :href="`/laporan/mutasi.csv?${q}`" external>
                            <Download class="size-4" aria-hidden="true" />
                            Unduh CSV
                        </AppButton>
                        <AppButton size="sm" :href="`/laporan/buku-mutasi.pdf?${q}`" external>
                            <FileText class="size-4" aria-hidden="true" />
                            Buku Mutasi
                        </AppButton>
                    </div>
                </div>

                <BarChart
                    :items="mutasi.per_jenis.map((b) => ({ label: b.label, total: b.jumlah }))"
                    caption="Jumlah mutasi penduduk per jenis"
                />
            </section>

            <!-- ============ FR-LAP-05: layanan surat ============ -->
            <section class="card p-5" aria-labelledby="rekap-layanan">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="rekap-layanan" class="flex items-center gap-2 font-semibold">
                            <Mail class="size-4 text-content-tertiary" aria-hidden="true" />
                            Layanan surat
                        </h2>
                        <p class="mt-1 text-sm text-content-secondary">
                            Tepat waktu
                            {{ layanan.sla.persen_tepat === null ? '—' : `${layanan.sla.persen_tepat}%` }}
                            dari {{ nf.format(layanan.sla.total) }} surat selesai.
                        </p>
                    </div>
                    <AppButton v-if="izin.ekspor" size="sm" :href="`/laporan/layanan.csv?${q}`" external>
                        <Download class="size-4" aria-hidden="true" />
                        Unduh CSV
                    </AppButton>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-left text-xs text-content-tertiary">
                                <th scope="col" class="py-2 pr-3 font-medium">Jenis surat</th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium">Total</th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium">Terbit</th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium">Ditolak</th>
                                <th scope="col" class="py-2 pr-3 text-right font-medium">Rata-rata</th>
                                <th scope="col" class="py-2 text-right font-medium">Tepat SLA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="j in layanan.per_jenis" :key="j.code"
                                class="border-b border-line last:border-0">
                                <th scope="row" class="py-2 pr-3 text-left font-normal">{{ j.name }}</th>
                                <td class="tabular py-2 pr-3 text-right font-medium">{{ nf.format(j.total) }}</td>
                                <td class="tabular py-2 pr-3 text-right">{{ nf.format(j.selesai) }}</td>
                                <td class="tabular py-2 pr-3 text-right">{{ nf.format(j.ditolak) }}</td>
                                <!--
                                  Tanda hubung, bukan nol: jenis surat yang
                                  belum punya satu pun surat selesai TIDAK
                                  "selesai dalam 0 jam".
                                -->
                                <td class="tabular py-2 pr-3 text-right">{{ rataJam(j.rata_jam) }}</td>
                                <td class="tabular py-2 text-right"
                                    :class="j.persen_tepat !== null && j.persen_tepat < 90 ? 'text-warning' : ''">
                                    {{ j.persen_tepat === null ? '—' : `${j.persen_tepat}%` }}
                                </td>
                            </tr>
                            <tr v-if="layanan.per_jenis.length === 0">
                                <td colspan="6" class="py-4 text-center text-content-tertiary">
                                    Belum ada pengajuan surat pada periode ini.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3 class="mb-2 mt-5 text-sm font-medium">Tren 12 bulan</h3>
                <BarChart :items="trenItems" caption="Jumlah pengajuan surat masuk per bulan" />
            </section>

            <!-- ============ Pengaduan ============ -->
            <section v-if="pengaduan" class="card p-5" aria-labelledby="rekap-pengaduan">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="rekap-pengaduan" class="flex items-center gap-2 font-semibold">
                            <Megaphone class="size-4 text-content-tertiary" aria-hidden="true" />
                            Pengaduan
                        </h2>
                        <p class="mt-1 text-sm text-content-secondary">
                            {{ nf.format(pengaduan.terbuka) }} terbuka
                            <span v-if="pengaduan.terlambat > 0" class="font-medium text-warning">
                                · {{ nf.format(pengaduan.terlambat) }} melewati batas waktu
                            </span>
                        </p>
                    </div>
                    <AppButton v-if="izin.ekspor" size="sm" href="/laporan/pengaduan.csv" external>
                        <Download class="size-4" aria-hidden="true" />
                        Unduh CSV
                    </AppButton>
                </div>

                <BarChart
                    :items="pengaduan.per_status.map((b) => ({ label: b.label, total: b.jumlah }))"
                    caption="Jumlah pengaduan per status"
                />
            </section>

            <!-- ============ FR-LAP-06: buku administrasi ============ -->
            <section class="card p-5" aria-labelledby="buku-administrasi">
                <h2 id="buku-administrasi" class="flex items-center gap-2 font-semibold">
                    <FileText class="size-4 text-content-tertiary" aria-hidden="true" />
                    Buku administrasi desa
                </h2>
                <p class="mt-1 text-sm text-content-secondary">
                    Format Permendagri 47/2016, siap cetak.
                </p>

                <!--
                  Apa yang tercetak dikatakan SEBELUM tombolnya: buku induk
                  adalah dokumen yang difotokopi dan dibawa keluar kantor desa.
                -->
                <p class="mt-3 text-sm text-content-secondary">
                    Buku Induk memuat satu baris per penduduk.
                    <template v-if="izin.nik_penuh">
                        Karena peran Anda berwenang melihat NIK lengkap,
                        <strong>NIK akan tercetak utuh</strong> dan pencetakannya dicatat
                        dalam log aktivitas.
                    </template>
                    <template v-else>
                        NIK tercetak sebagian untuk melindungi data pribadi.
                    </template>
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <AppButton href="/laporan/buku-induk.pdf" external>
                        <FileText class="size-4" aria-hidden="true" />
                        Buku Induk Penduduk
                    </AppButton>
                    <AppButton :href="`/laporan/buku-mutasi.pdf?${q}`" external>
                        <FileText class="size-4" aria-hidden="true" />
                        Buku Mutasi Penduduk
                    </AppButton>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
