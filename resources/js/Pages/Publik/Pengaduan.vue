<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { Landmark, Megaphone, ShieldOff, Send } from '@lucide/vue'
import TextField from '@/Components/TextField.vue'
import TextareaField from '@/Components/TextareaField.vue'
import SelectField from '@/Components/SelectField.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import { usePageData } from '@/composables/usePage'

const props = defineProps<{
    kategori: Record<string, string>
    wilayah: Record<string, string>
    pelapor: { name: string; phone: string | null } | null
}>()

const { village, user } = usePageData()

const form = useForm({
    category_code: '',
    title: '',
    body: '',
    is_anonymous: false as boolean,
    reporter_name: props.pelapor?.name ?? '',
    reporter_phone: props.pelapor?.phone ?? '',
    region_id: '',
    location_text: '',
})

/*
 * Kolom identitas DIKOSONGKAN di peramban begitu "anonim" dicentang, bukan
 * hanya disembunyikan.
 *
 * Kolom yang tersembunyi tetapi masih berisi tetap terkirim ke server, dan
 * meski ComplaintService membuangnya (RULE-09), warga tidak dapat melihat
 * bahwa itu terjadi. Yang ia lihat adalah kolom namanya hilang sementara ia
 * tidak tahu apakah namanya ikut terkirim — dan rasa tidak yakin itu sendiri
 * sudah merusak janji anonim, karena orang yang ragu tidak akan melapor.
 */
function toggleAnonim(value: boolean): void {
    form.is_anonymous = value

    if (value) {
        form.reporter_name = ''
        form.reporter_phone = ''
    }
}

const anonimTerpilih = computed(() => form.is_anonymous === true)
</script>

<template>
    <Head title="Sampaikan Pengaduan" />

    <!--
      Halaman PUBLIK tanpa AppLayout, sama seperti pelacakan surat: pembukanya
      warga yang sering tidak punya akun sama sekali, dan menu panel admin di
      sini tidak ada gunanya bagi mereka.
    -->
    <div class="min-h-dvh bg-background px-4 py-10 text-content">
        <main class="mx-auto max-w-xl">
            <header class="mb-6 text-center">
                <span class="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-accent">
                    <Megaphone class="size-6 text-content-inverse" aria-hidden="true" />
                </span>
                <h1 class="text-xl font-semibold">Sampaikan Pengaduan</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Pemerintah Desa {{ village.name }}, Kec. {{ village.district }},
                    Kab. {{ village.regency }}
                </p>
            </header>

            <!--
              Yang dijanjikan desa dikatakan DI DEPAN, sebelum formulirnya:
              warga yang tidak tahu pengaduannya akan ditanggapi dalam
              hitungan hari cenderung tidak menuliskannya dengan serius.
            -->
            <AppAlert type="info" class="mb-5">
                <p class="font-medium">Yang terjadi setelah Anda mengirim</p>
                <p class="mt-1">
                    Pengaduan Anda diperiksa petugas desa dalam <strong>2 hari kerja</strong>,
                    dan ditangani dalam <strong>14 hari kerja</strong> setelah diterima.
                    Anda akan menerima kode pelacakan untuk memantau tindak lanjutnya.
                </p>
            </AppAlert>

            <section class="card p-5" aria-label="Formulir pengaduan">
                <form class="space-y-4" @submit.prevent="form.post('/pengaduan')">
                    <SelectField
                        v-model="form.category_code"
                        label="Kategori"
                        :options="kategori"
                        required
                        :error="form.errors.category_code"
                    />

                    <TextField
                        v-model="form.title"
                        label="Judul pengaduan"
                        placeholder="Lampu jalan mati di RT 002"
                        required
                        maxlength="200"
                        hint="Sebutkan apa dan di mana, dalam satu baris."
                        :error="form.errors.title"
                    />

                    <TextareaField
                        v-model="form.body"
                        label="Isi pengaduan"
                        :rows="6"
                        required
                        :maxlength="5000"
                        hint="Sejak kapan, di mana tepatnya, dan apa dampaknya bagi warga."
                        :error="form.errors.body"
                    />

                    <!-- ============ Lokasi (opsional) ============ -->
                    <fieldset class="space-y-4 border-t border-line pt-4">
                        <legend class="text-sm font-medium text-content">
                            Lokasi <span class="font-normal text-content-tertiary">(opsional)</span>
                        </legend>

                        <SelectField
                            v-model="form.region_id"
                            label="RT"
                            :options="wilayah"
                            placeholder="— Tidak disebutkan —"
                            hint="Menyebut RT membuat pengaduan Anda langsung terlihat oleh perangkat dusun setempat."
                            :error="form.errors.region_id"
                        />

                        <TextField
                            v-model="form.location_text"
                            label="Keterangan lokasi"
                            placeholder="Depan balai desa, sebelah pos ronda"
                            maxlength="255"
                            :error="form.errors.location_text"
                        />
                    </fieldset>

                    <!-- ============ Identitas pelapor ============ -->
                    <fieldset class="space-y-4 border-t border-line pt-4">
                        <legend class="text-sm font-medium text-content">Identitas pelapor</legend>

                        <!--
                          Pilihan anonim ditaruh DI ATAS kolom nama, bukan di
                          bawahnya: warga yang sudah menuliskan namanya lalu
                          menemukan pilihan anonim di bawah akan ragu apakah
                          nama yang sudah ia ketik tetap terkirim. Yang
                          dicentang lebih dulu menentukan kolom apa yang muncul.
                        -->
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-line-control p-3
                                      transition-colors hover:border-content-tertiary">
                            <input
                                type="checkbox"
                                :checked="anonimTerpilih"
                                class="mt-0.5 size-4 shrink-0 rounded border-line-control text-accent
                                       focus:ring-2 focus:ring-accent"
                                @change="toggleAnonim(($event.target as HTMLInputElement).checked)"
                            />
                            <span class="min-w-0 text-sm">
                                <span class="flex items-center gap-1.5 font-medium">
                                    <ShieldOff class="size-4 shrink-0 text-content-tertiary" aria-hidden="true" />
                                    Kirim sebagai anonim
                                </span>
                                <span class="mt-1 block text-content-secondary">
                                    Nama dan nomor telepon Anda tidak disimpan sama sekali.
                                </span>
                            </span>
                        </label>

                        <!--
                          Akibat anonim dikatakan TERANG-TERANGAN, bukan
                          disembunyikan di catatan kaki: kode pelacakan adalah
                          satu-satunya jalan kembali ke pengaduannya, dan
                          warga yang kehilangannya tidak dapat ditolong siapa
                          pun — termasuk petugas desa.
                        -->
                        <AppAlert v-if="anonimTerpilih" type="warning">
                            <p class="font-medium">Simpan kode pelacakan Anda</p>
                            <p class="mt-1">
                                Karena tidak ada identitas yang disimpan, sistem tidak punya
                                cara menghubungkan pengaduan ini dengan Anda. Kode pelacakan
                                yang muncul setelah mengirim adalah satu-satunya jalan untuk
                                memantau tindak lanjutnya, dan kode itu
                                <strong>tidak dapat dikirim ulang</strong>.
                            </p>
                        </AppAlert>

                        <template v-else>
                            <TextField
                                v-model="form.reporter_name"
                                label="Nama"
                                placeholder="Nama Anda"
                                maxlength="150"
                                hint="Boleh dikosongkan. Petugas memakainya untuk menyapa Anda saat menindaklanjuti."
                                :error="form.errors.reporter_name"
                            />
                            <TextField
                                v-model="form.reporter_phone"
                                label="Nomor telepon / WhatsApp"
                                placeholder="08xxxxxxxxxx"
                                maxlength="30"
                                hint="Boleh dikosongkan. Dipakai hanya bila petugas perlu menanyakan kejelasan."
                                :error="form.errors.reporter_phone"
                            />
                        </template>
                    </fieldset>

                    <AppButton type="submit" variant="primary" block :loading="form.processing">
                        <Send class="size-4" aria-hidden="true" />
                        Kirim pengaduan
                    </AppButton>
                </form>
            </section>

            <footer class="mt-6 space-y-2 text-center text-xs text-content-tertiary">
                <p>
                    Sudah pernah mengadu?
                    <Link href="/pengaduan/lacak" class="text-accent hover:underline">
                        Lacak dengan kode pelacakan
                    </Link>
                </p>
                <p>
                    <Link v-if="user" href="/dashboard" class="hover:text-content-secondary">
                        Kembali ke panel
                    </Link>
                    <Link v-else href="/" class="hover:text-content-secondary">
                        <Landmark class="mr-1 inline size-3" aria-hidden="true" />
                        Beranda Desa {{ village.name }}
                    </Link>
                </p>
            </footer>
        </main>
    </div>
</template>
