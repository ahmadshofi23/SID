<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import TextField from '@/Components/TextField.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'

const form = useForm({
    nik: '',
    kk_number: '',
    full_name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    verification_method: 'ktp_upload',
    ktp: null as File | null,
    setuju: false as boolean,
})

const perluKtp = computed(() => form.verification_method === 'ktp_upload')

function pilihKtp(event: Event): void {
    const input = event.target as HTMLInputElement
    form.ktp = input.files?.[0] ?? null
}

function submit(): void {
    form.post('/daftar', {
        forceFormData: true,
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head title="Daftar Akun Warga" />

    <GuestLayout
        title="Daftar Akun Warga"
        subtitle="Akun ini dipakai untuk mengajukan surat dan memantau statusnya dari rumah."
    >
        <form class="space-y-5" @submit.prevent="submit">

            <AppAlert type="info" title="Data Anda dicocokkan dengan data kependudukan desa">
                Isi persis seperti yang tertulis pada KTP dan Kartu Keluarga Anda.
                Akun baru dapat dipakai setelah diperiksa petugas desa — biasanya
                satu hari kerja.
            </AppAlert>

            <TextField
                v-model="form.nik"
                label="NIK"
                required
                inputmode="numeric"
                autocomplete="off"
                :error="form.errors.nik"
                hint="16 digit, sesuai KTP."
            />

            <TextField
                v-model="form.kk_number"
                label="Nomor Kartu Keluarga"
                required
                inputmode="numeric"
                autocomplete="off"
                :error="form.errors.kk_number"
                hint="16 digit, tertulis di bagian atas Kartu Keluarga."
            />

            <TextField
                v-model="form.full_name"
                label="Nama lengkap"
                required
                autocomplete="name"
                :error="form.errors.full_name"
                hint="Sesuai KTP, termasuk ejaannya."
            />

            <div class="rounded-lg border border-line p-4">
                <p class="text-sm font-medium">Cara Anda masuk nanti</p>
                <p class="mt-1 text-sm text-content-secondary">
                    Isi salah satu — email atau nomor ponsel. Keduanya boleh.
                </p>

                <div class="mt-3 space-y-4">
                    <TextField v-model="form.email" label="Email" type="email"
                               autocomplete="email" :error="form.errors.email" />
                    <TextField v-model="form.phone" label="Nomor ponsel" type="tel"
                               inputmode="tel" autocomplete="tel" :error="form.errors.phone" />
                </div>
            </div>

            <TextField
                v-model="form.password"
                label="Kata sandi"
                type="password"
                required
                autocomplete="new-password"
                :error="form.errors.password"
                hint="Minimal 10 karakter. Gunakan tiga kata yang mudah Anda ingat."
            />

            <TextField
                v-model="form.password_confirmation"
                label="Ulangi kata sandi"
                type="password"
                required
                autocomplete="new-password"
            />

            <!--
              Dua jalur verifikasi (FR-AUT-09). Tatap muka disediakan sejajar,
              bukan sebagai cadangan: sebagian warga tidak punya ponsel berkamera,
              dan sebagian lagi tidak ingin mengirim foto KTP-nya ke mana pun.
              Keduanya pilihan yang sah.
            -->
            <fieldset>
                <legend class="text-sm font-medium">Cara verifikasi</legend>

                <div class="mt-2 space-y-2">
                    <label class="flex min-h-11 cursor-pointer items-start gap-2.5 rounded-lg border border-line p-3 text-sm">
                        <input v-model="form.verification_method" type="radio" value="ktp_upload"
                               class="mt-0.5 size-4.5 accent-[var(--color-accent)]">
                        <span>
                            <span class="font-medium">Unggah foto KTP</span>
                            <span class="mt-0.5 block text-content-secondary">
                                Petugas memeriksa dari kantor. Foto dihapus segera setelah
                                akun Anda disetujui.
                            </span>
                        </span>
                    </label>

                    <label class="flex min-h-11 cursor-pointer items-start gap-2.5 rounded-lg border border-line p-3 text-sm">
                        <input v-model="form.verification_method" type="radio" value="tatap_muka"
                               class="mt-0.5 size-4.5 accent-[var(--color-accent)]">
                        <span>
                            <span class="font-medium">Tatap muka di kantor desa</span>
                            <span class="mt-0.5 block text-content-secondary">
                                Datang membawa KTP asli pada jam layanan. Tidak ada berkas
                                yang Anda kirim lewat internet.
                            </span>
                        </span>
                    </label>
                </div>

                <p v-if="form.errors.verification_method" role="alert"
                   class="mt-1.5 text-sm font-medium text-danger">
                    {{ form.errors.verification_method }}
                </p>
            </fieldset>

            <div v-if="perluKtp">
                <label for="ktp" class="block text-sm font-medium">Foto KTP</label>
                <input id="ktp" type="file" accept="image/jpeg,image/png,application/pdf"
                       capture="environment"
                       class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0
                              file:bg-surface-hover file:px-4 file:py-2 file:text-sm file:font-medium"
                       @change="pilihKtp">
                <p class="mt-1 text-xs text-content-tertiary">
                    JPG, PNG, atau PDF, maksimal 5 MB. Foto langsung dari kamera sudah cukup.
                </p>
                <p v-if="form.errors.ktp" role="alert" class="mt-1.5 text-sm font-medium text-danger">
                    {{ form.errors.ktp }}
                </p>
            </div>

            <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                <input v-model="form.setuju" type="checkbox"
                       class="mt-0.5 size-4.5 rounded border-line-strong accent-[var(--color-accent)]">
                <span>
                    Saya menyatakan data di atas adalah data saya sendiri dan benar,
                    serta bersedia data tersebut dicocokkan dengan data kependudukan desa.
                </span>
            </label>
            <p v-if="form.errors.setuju" role="alert" class="-mt-3 text-sm font-medium text-danger">
                {{ form.errors.setuju }}
            </p>

            <AppButton type="submit" variant="primary" block :loading="form.processing">
                Kirim pendaftaran
            </AppButton>

            <p class="text-center text-sm">
                Sudah punya akun?
                <Link href="/masuk" class="text-accent hover:underline">Masuk</Link>
            </p>
        </form>
    </GuestLayout>
</template>
