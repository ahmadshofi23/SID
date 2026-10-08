<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { Smartphone, QrCode, KeyRound } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import TextField from '@/Components/TextField.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'

defineProps<{ secret: string; qrCode: string }>()

const form = useForm({ code: '' })

function submit(): void {
    form.post('/dua-faktor/pasang', { onFinish: () => form.reset('code') })
}

const steps = [
    {
        icon: Smartphone,
        title: 'Pasang aplikasi autentikator',
        body: 'Google Authenticator, Microsoft Authenticator, atau Aegis. '
            + 'Aplikasi ini bekerja tanpa internet.',
    },
    { icon: QrCode, title: 'Pindai kode QR', body: null },
    { icon: KeyRound, title: 'Masukkan kode dari aplikasi', body: null },
]
</script>

<template>
    <Head title="Aktifkan Verifikasi Dua Langkah" />

    <AppLayout>
        <div class="mx-auto max-w-2xl">
            <h1 class="text-2xl font-semibold">Aktifkan verifikasi dua langkah</h1>

            <AppAlert type="warning" title="Wajib untuk peran Anda" class="mt-4">
                Akun dengan peran Admin Desa atau Kepala Desa dapat mengakses data pribadi
                seluruh warga. Verifikasi dua langkah memastikan kata sandi yang bocor saja
                tidak cukup untuk masuk.
            </AppAlert>

            <div class="card mt-5 divide-y divide-[var(--color-line)]">
                <!-- Langkah 1 -->
                <section class="flex gap-4 p-5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                        <component :is="steps[0].icon" class="size-4.5 text-accent-text" aria-hidden="true" />
                    </span>
                    <div>
                        <h2 class="font-semibold">1. {{ steps[0].title }}</h2>
                        <p class="mt-1 text-sm text-content-secondary">{{ steps[0].body }}</p>
                    </div>
                </section>

                <!-- Langkah 2 -->
                <section class="flex gap-4 p-5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                        <component :is="steps[1].icon" class="size-4.5 text-accent-text" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold">2. {{ steps[1].title }}</h2>

                        <!-- QR selalu berlatar putih, termasuk di mode gelap:
                             pemindai memerlukan kontras gelap-di-atas-terang. -->
                        <div class="mt-3 inline-flex rounded-xl border border-line bg-white p-4">
                            <div class="size-48 [&>svg]:size-full" v-html="qrCode" />
                        </div>

                        <p class="mt-3 text-sm text-content-secondary">
                            Tidak dapat memindai? Masukkan kode ini secara manual:
                        </p>
                        <p class="mt-1.5 break-all rounded-lg border border-line bg-surface-sunken
                                  px-3 py-2 font-mono text-sm tracking-wide">
                            {{ secret }}
                        </p>
                    </div>
                </section>

                <!-- Langkah 3 -->
                <section class="flex gap-4 p-5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-accent-soft">
                        <component :is="steps[2].icon" class="size-4.5 text-accent-text" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold">3. {{ steps[2].title }}</h2>

                        <form class="mt-3 max-w-xs space-y-4" @submit.prevent="submit">
                            <TextField
                                v-model="form.code"
                                label="Kode 6 angka"
                                required
                                mono
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                placeholder="000000"
                                :error="form.errors.code"
                            />
                            <AppButton type="submit" variant="primary" block :loading="form.processing">
                                Aktifkan
                            </AppButton>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
