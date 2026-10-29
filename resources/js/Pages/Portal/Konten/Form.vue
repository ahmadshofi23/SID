<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ChevronRight, ExternalLink } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import TextField from '@/Components/TextField.vue'
import TextareaField from '@/Components/TextareaField.vue'
import SelectField from '@/Components/SelectField.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import { usePageData } from '@/composables/usePage'

interface PostForm {
    id: string
    type: string
    title: string
    slug: string
    status: string
    is_scheduled: boolean
    excerpt: string | null
    body: string
    category_code: string | null
    tags: string[]
    event_location: string | null
    meta_title: string | null
    meta_description: string | null
    cover_url: string | null
    published_at_lokal: string | null
    event_start_at_lokal: string | null
    event_end_at_lokal: string | null
}

const props = defineProps<{
    post: PostForm | null
    defaultType: string
    typeLabels: Record<string, string>
}>()

const { can } = usePageData()
const p = props.post

const form = useForm({
    type: p?.type ?? props.defaultType,
    title: p?.title ?? '',
    excerpt: p?.excerpt ?? '',
    body: p?.body ?? '',
    category_code: p?.category_code ?? '',
    tags: (p?.tags ?? []).join(', '),
    status: p?.status ?? 'draft',
    published_at: p?.published_at_lokal ?? '',
    event_start_at: p?.event_start_at_lokal ?? '',
    event_end_at: p?.event_end_at_lokal ?? '',
    event_location: p?.event_location ?? '',
    meta_title: p?.meta_title ?? '',
    meta_description: p?.meta_description ?? '',
    cover: null as File | null,
    hapus_sampul: false,
})

const isAgenda = computed(() => form.type === 'agenda')

/*
 * Pilihan status menyesuaikan izin. Menyembunyikan "Terbit" bagi yang tidak
 * berhak BUKAN kontrol keamanan — PostController tetap menolaknya — melainkan
 * supaya petugas tidak menulis sepanjang satu halaman lalu baru ditolak saat
 * menyimpan (KEP-012: penolakan izin harus terlihat, bukan mengejutkan).
 */
const statusOptions = computed<Record<string, string>>(() => {
    if (can('portal.terbitkan')) {
        return { draft: 'Draf', terbit: 'Terbit', arsip: 'Arsip' }
    }

    // Tanpa izin terbitkan: satu-satunya pilihan adalah keadaan sekarang.
    // Tulisan yang sudah terbit tetap tampil apa adanya agar penyunting tahu
    // mengapa ia tidak dapat menurunkannya, bukan sekadar kehilangan pilihan.
    const terkunci: Record<string, string> = p?.status === 'terbit'
        ? { terbit: 'Terbit (hanya pimpinan dapat menariknya kembali)' }
        : { draft: 'Draf' }

    return terkunci
})

function submit(): void {
    const options = { forceFormData: true, preserveScroll: true }

    if (p) {
        // POST, bukan PUT: unggahan multipart tidak terkirim lewat PUT di PHP.
        form.post(`/portal/konten/${p.id}`, options)
        return
    }

    form.post('/portal/konten', options)
}

function pilihSampul(event: Event): void {
    const input = event.target as HTMLInputElement
    form.cover = input.files?.[0] ?? null
    if (form.cover) form.hapus_sampul = false
}
</script>

<template>
    <Head :title="p ? 'Ubah Tulisan' : 'Tulis Baru'" />

    <AppLayout>
        <nav class="mb-4 flex items-center gap-1 text-sm text-content-tertiary" aria-label="Remah roti">
            <Link href="/portal/konten" class="hover:text-content-secondary hover:underline">Konten Portal</Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <span class="text-content-secondary">{{ p ? p.title : 'Tulis baru' }}</span>
        </nav>

        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <h1 class="text-2xl font-semibold">{{ p ? 'Ubah tulisan' : 'Tulis baru' }}</h1>

            <AppButton v-if="p && p.status === 'terbit' && !p.is_scheduled"
                       variant="secondary" :href="`/${p.type}/${p.slug}`">
                <ExternalLink class="size-4" aria-hidden="true" />
                Lihat di portal
            </AppButton>
        </header>

        <AppAlert v-if="p?.is_scheduled" type="info" class="mb-5" title="Belum terlihat warga">
            Tulisan ini sudah berstatus terbit, tetapi tanggal terbitnya belum tiba.
            Ia akan muncul di portal dengan sendirinya — tidak perlu ada yang menekan
            tombol apa pun.
        </AppAlert>

        <form class="max-w-3xl space-y-5" @submit.prevent="submit">

            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Isi tulisan</legend>

                <div class="mt-3 space-y-4">
                    <SelectField v-model="form.type" label="Jenis" required
                                 :options="typeLabels" :error="form.errors.type"
                                 hint="Agenda menampilkan waktu & lokasi kegiatan di portal." />

                    <TextField v-model="form.title" label="Judul" required
                               :error="form.errors.title" />

                    <p v-if="p" class="text-xs text-content-tertiary">
                        Alamat: <code class="tabular">/{{ p.type }}/{{ p.slug }}</code>
                        <span v-if="p.published_at_lokal">
                            — tidak berubah lagi meski judul disunting, agar tautan yang
                            sudah dibagikan warga tetap hidup.
                        </span>
                    </p>

                    <TextareaField v-model="form.excerpt" label="Ringkasan" :rows="2"
                                   :error="form.errors.excerpt"
                                   hint="Satu–dua kalimat yang tampil di daftar berita dan saat tautan dibagikan." />

                    <TextareaField v-model="form.body" label="Isi" required :rows="14"
                                   :error="form.errors.body"
                                   hint="Boleh memuat HTML sederhana: paragraf, daftar, tabel, tautan, gambar. Tag lain dibuang saat disimpan." />
                </div>
            </fieldset>

            <fieldset v-if="isAgenda" class="card p-5">
                <legend class="px-1 text-sm font-semibold">Waktu & tempat kegiatan</legend>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <TextField v-model="form.event_start_at" label="Mulai" type="datetime-local"
                               required :error="form.errors.event_start_at" />
                    <TextField v-model="form.event_end_at" label="Selesai" type="datetime-local"
                               :error="form.errors.event_end_at"
                               hint="Kosongkan bila belum pasti." />
                    <div class="sm:col-span-2">
                        <TextField v-model="form.event_location" label="Lokasi" required
                                   :error="form.errors.event_location"
                                   hint="Contoh: Balai Desa Sukamaju." />
                    </div>
                </div>

                <p class="mt-3 text-xs text-content-tertiary">
                    Waktu dibaca pada kalender desa, bukan UTC — yang Anda ketik di sini
                    sama persis dengan yang dibaca warga di portal.
                </p>
            </fieldset>

            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Gambar utama</legend>

                <div v-if="p?.cover_url && !form.hapus_sampul" class="mt-3">
                    <img :src="p.cover_url" alt="Gambar utama saat ini"
                         class="aspect-video w-full max-w-sm rounded-lg object-cover">
                    <label class="mt-2 flex items-center gap-2 text-sm">
                        <input v-model="form.hapus_sampul" type="checkbox" class="size-4 rounded border-line-control">
                        Hapus gambar ini
                    </label>
                </div>

                <div class="mt-3">
                    <label class="block text-sm font-medium" for="sampul">
                        {{ p?.cover_url ? 'Ganti gambar' : 'Pilih gambar' }}
                    </label>
                    <input id="sampul" type="file" accept="image/jpeg,image/png"
                           class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0
                                  file:bg-surface-hover file:px-4 file:py-2 file:text-sm file:font-medium"
                           @change="pilihSampul">
                    <p class="mt-1 text-xs text-content-tertiary">
                        JPG atau PNG, maksimal 2 MB. Gambar besar memperlambat halaman
                        portal di jaringan desa.
                    </p>
                    <p v-if="form.errors.cover" class="mt-1 text-sm text-danger">{{ form.errors.cover }}</p>
                </div>
            </fieldset>

            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Penerbitan</legend>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <SelectField v-model="form.status" label="Status" required
                                 :options="statusOptions" :error="form.errors.status" />

                    <TextField v-model="form.published_at" label="Tanggal terbit"
                               type="datetime-local" :error="form.errors.published_at"
                               :disabled="form.status !== 'terbit'"
                               hint="Isi dengan tanggal mendatang untuk menjadwalkan. Kosong = sekarang." />

                    <TextField v-model="form.category_code" label="Kategori"
                               :error="form.errors.category_code" />
                    <TextField v-model="form.tags" label="Label"
                               :error="form.errors.tags"
                               hint="Pisahkan dengan koma." />
                </div>
            </fieldset>

            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Mesin pencari (opsional)</legend>

                <div class="mt-3 space-y-4">
                    <TextField v-model="form.meta_title" label="Judul untuk mesin pencari"
                               :error="form.errors.meta_title"
                               hint="Kosongkan untuk memakai judul tulisan." />
                    <TextareaField v-model="form.meta_description" label="Deskripsi untuk mesin pencari"
                                   :rows="2" :error="form.errors.meta_description"
                                   hint="Kosongkan untuk memakai ringkasan." />
                </div>
            </fieldset>

            <div class="flex flex-wrap gap-2">
                <AppButton type="submit" variant="primary" :loading="form.processing">
                    {{ p ? 'Simpan perubahan' : 'Simpan tulisan' }}
                </AppButton>
                <AppButton variant="secondary" href="/portal/konten">Batal</AppButton>
            </div>
        </form>

        <div v-if="p" class="mt-6 max-w-3xl border-t border-line pt-5">
            <ConfirmDialog
                :action="`/portal/konten/${p.id}`"
                method="delete"
                title="Hapus tulisan ini?"
                :body="`&quot;${p.title}&quot; akan hilang dari portal. Gambarnya tetap disimpan agar tulisan masih dapat dipulihkan.`"
                confirm-label="Hapus tulisan"
                trigger-label="Hapus tulisan"
                reason-placeholder="Contoh: pengumuman dibatalkan rapat desa"
            />
        </div>
    </AppLayout>
</template>
