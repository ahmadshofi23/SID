<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import TextField from '@/Components/TextField.vue'
import AppButton from '@/Components/AppButton.vue'

const form = useForm({ identifier: '' })
</script>

<template>
    <Head title="Lupa Kata Sandi" />

    <GuestLayout
        title="Lupa kata sandi"
        subtitle="Masukkan email atau nomor ponsel yang terdaftar. Kami akan mengirimkan tautan untuk mengatur ulang kata sandi Anda."
    >
        <form class="space-y-5" @submit.prevent="form.post('/lupa-sandi')">
            <TextField
                v-model="form.identifier"
                label="Email atau nomor ponsel"
                required
                autofocus
                autocomplete="username"
                :error="form.errors.identifier"
            />

            <AppButton type="submit" variant="primary" block :loading="form.processing">
                Kirim tautan
            </AppButton>

            <p class="text-center text-sm">
                <Link href="/masuk" class="text-accent hover:underline">Kembali ke halaman masuk</Link>
            </p>
        </form>
    </GuestLayout>
</template>
