<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import TextField from '@/Components/TextField.vue'
import AppButton from '@/Components/AppButton.vue'

const props = defineProps<{ challenge: string }>()

const form = useForm({ code: '' })

function submit(): void {
    form.post(`/masuk/dua-faktor/${props.challenge}`, {
        onFinish: () => form.reset('code'),
    })
}
</script>

<template>
    <Head title="Verifikasi Dua Langkah" />

    <GuestLayout
        title="Verifikasi dua langkah"
        subtitle="Buka aplikasi autentikator di ponsel Anda, lalu masukkan 6 angka yang tampil."
    >
        <form class="space-y-5" @submit.prevent="submit">
            <TextField
                v-model="form.code"
                label="Kode verifikasi"
                required
                autofocus
                mono
                inputmode="numeric"
                autocomplete="one-time-code"
                placeholder="000000"
                :error="form.errors.code"
                hint="Kode berganti setiap 30 detik. Kode pemulihan juga dapat dipakai di sini."
            />

            <AppButton type="submit" variant="primary" block :loading="form.processing">
                Verifikasi
            </AppButton>
        </form>

        <p class="mt-5 border-t border-line pt-4 text-sm text-content-tertiary">
            Ponsel hilang atau aplikasi terhapus? Gunakan salah satu kode pemulihan
            yang Anda simpan saat mengaktifkan verifikasi dua langkah. Bila kode itu
            juga tidak ada, hubungi Admin Desa.
        </p>
    </GuestLayout>
</template>
