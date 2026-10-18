<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { Clock } from '@lucide/vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import AppButton from '@/Components/AppButton.vue'
</script>

<template>
    <Head title="Akun Belum Aktif" />

    <GuestLayout title="Akun belum dapat dipakai">
        <div class="space-y-5">
            <div class="flex gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-info-soft">
                    <Clock class="size-5 text-info" aria-hidden="true" />
                </span>
                <p class="pt-2 text-content-secondary">
                    Akun Anda belum tertaut ke data kependudukan, atau belum selesai
                    diperiksa petugas desa.
                </p>
            </div>

            <div class="rounded-lg border border-line p-4 text-sm text-content-secondary">
                <p class="font-medium text-content">Yang dapat Anda lakukan</p>
                <ul class="mt-2 space-y-2">
                    <li>Tunggu pemeriksaan petugas — biasanya satu hari kerja.</li>
                    <li>
                        Datang ke kantor desa membawa KTP dan Kartu Keluarga asli bila
                        sudah lebih dari dua hari kerja.
                    </li>
                    <li>
                        Mengurus surat tetap dapat dilakukan di loket tanpa akun, dan
                        statusnya dipantau lewat halaman pelacakan.
                    </li>
                </ul>
            </div>

            <AppButton href="/surat/lacak" variant="secondary" block>
                Lacak pengajuan dengan nomor tiket
            </AppButton>
            <AppButton href="/" variant="ghost" block>Kembali ke beranda desa</AppButton>
        </div>
    </GuestLayout>
</template>
