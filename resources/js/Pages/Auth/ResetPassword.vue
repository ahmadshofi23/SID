<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import TextField from '@/Components/TextField.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'

const props = defineProps<{ token: string; identifier: string }>()

const form = useForm({
    token: props.token,
    identifier: props.identifier,
    password: '',
    password_confirmation: '',
})

function submit(): void {
    form.post('/atur-sandi', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head title="Atur Kata Sandi Baru" />

    <GuestLayout title="Atur kata sandi baru">
        <form class="space-y-5" @submit.prevent="submit">
            <AppAlert v-if="form.errors.token" type="danger">
                {{ form.errors.token }}
            </AppAlert>

            <TextField
                v-model="form.identifier"
                label="Email atau nomor ponsel"
                required
                autocomplete="username"
                :error="form.errors.identifier"
            />

            <TextField
                v-model="form.password"
                label="Kata sandi baru"
                type="password"
                required
                autocomplete="new-password"
                :error="form.errors.password"
                hint="Minimal 10 karakter. Lebih mudah dan lebih aman: gabungkan tiga kata yang tidak berhubungan, misalnya kursi-hujan-mangga."
            />

            <TextField
                v-model="form.password_confirmation"
                label="Ulangi kata sandi baru"
                type="password"
                required
                autocomplete="new-password"
            />

            <AppButton type="submit" variant="primary" block :loading="form.processing">
                Simpan kata sandi baru
            </AppButton>
        </form>
    </GuestLayout>
</template>
