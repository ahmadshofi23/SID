<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import TextField from '@/Components/TextField.vue'
import AppButton from '@/Components/AppButton.vue'

const form = useForm({ identifier: '', password: '', remember: false })

function submit(): void {
    form.post('/masuk', { onFinish: () => form.reset('password') })
}
</script>

<template>
    <Head title="Masuk" />

    <GuestLayout
        title="Masuk"
        subtitle="Gunakan email atau nomor ponsel yang terdaftar di kantor desa."
    >
        <form class="space-y-5" @submit.prevent="submit">
            <TextField
                v-model="form.identifier"
                label="Email atau nomor ponsel"
                required
                autofocus
                autocomplete="username"
                :error="form.errors.identifier"
                hint="Contoh: sekdes@desa.id atau 081234567890"
            />

            <TextField
                v-model="form.password"
                label="Kata sandi"
                type="password"
                required
                autocomplete="current-password"
                :error="form.errors.password"
            />

            <label class="flex min-h-11 cursor-pointer items-center gap-2.5 text-sm">
                <input
                    v-model="form.remember"
                    type="checkbox"
                    class="size-4.5 rounded border-line-strong accent-[var(--color-accent)] cursor-pointer"
                >
                Ingat saya di perangkat ini
            </label>

            <AppButton type="submit" variant="primary" block :loading="form.processing">
                Masuk
            </AppButton>

            <p class="text-center text-sm">
                <Link href="/lupa-sandi" class="text-accent hover:underline">Lupa kata sandi?</Link>
            </p>

            <p class="text-center text-sm text-content-secondary">
                Warga desa dan belum punya akun?
                <Link href="/daftar" class="text-accent hover:underline">Daftar di sini</Link>
            </p>
        </form>
    </GuestLayout>
</template>
