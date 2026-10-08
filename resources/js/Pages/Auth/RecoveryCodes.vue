<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppAlert from '@/Components/AppAlert.vue'
import AppButton from '@/Components/AppButton.vue'

defineProps<{ codes: string[] }>()
</script>

<template>
    <Head title="Kode Pemulihan" />

    <AppLayout>
        <div class="mx-auto max-w-xl">
            <h1 class="text-2xl font-semibold">Verifikasi dua langkah aktif</h1>

            <AppAlert type="danger" title="Simpan kode ini sekarang" class="mt-4">
                Kode berikut <strong>hanya ditampilkan satu kali</strong>. Sistem hanya
                menyimpan bentuk teracaknya, sehingga tidak dapat menampilkannya lagi.
                Catat di tempat aman — bukan di ponsel yang sama dengan aplikasi
                autentikator Anda.
            </AppAlert>

            <div class="card mt-5 p-5">
                <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <li
                        v-for="code in codes"
                        :key="code"
                        class="rounded-lg border border-dashed border-line-strong bg-surface-sunken
                               px-3 py-2.5 text-center font-mono text-sm tracking-widest"
                    >
                        {{ code }}
                    </li>
                </ul>

                <p class="mt-4 text-sm text-content-tertiary">
                    Setiap kode hanya dapat dipakai sekali, dan dipakai hanya bila ponsel
                    Anda hilang atau aplikasi autentikator terhapus.
                </p>

                <AppButton href="/dashboard" variant="primary" block class="mt-5">
                    Saya sudah menyimpannya — lanjutkan
                </AppButton>
            </div>
        </div>
    </AppLayout>
</template>
