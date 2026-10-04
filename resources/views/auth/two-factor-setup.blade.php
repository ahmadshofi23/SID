@extends('layouts.app')
@section('title', 'Aktifkan Verifikasi Dua Langkah')

@section('content')
<div class="sid-container--narrow">
    <div class="sid-card">
        <h2 class="sid-card__title">Aktifkan verifikasi dua langkah</h2>

        <x-alert type="warning" title="Wajib untuk peran Anda">
            Akun dengan peran Admin Desa atau Kepala Desa dapat mengakses data pribadi
            seluruh warga. Verifikasi dua langkah memastikan kata sandi yang bocor saja
            tidak cukup untuk masuk.
        </x-alert>

        <h3>Langkah 1 — Pasang aplikasi autentikator</h3>
        <p class="sid-text-sm">
            Pasang salah satu aplikasi berikut di ponsel Anda: Google Authenticator,
            Microsoft Authenticator, atau Aegis. Aplikasi ini bekerja tanpa internet.
        </p>

        <h3>Langkah 2 — Pindai kode QR</h3>
        <div class="sid-qr">{!! $qrCode !!}</div>
        <p class="sid-text-sm">
            Tidak dapat memindai? Masukkan kode ini secara manual:
        </p>
        <p class="sid-secret">{{ $secret }}</p>

        <h3>Langkah 3 — Masukkan kode dari aplikasi</h3>
        <form method="POST" action="{{ route('two-factor.confirm') }}">
            @csrf
            <x-field
                name="code"
                label="Kode 6 angka"
                :required="true"
                inputmode="numeric"
                autocomplete="one-time-code"
                inputClass="sid-input--code"
            />
            <button type="submit" class="sid-btn sid-btn--primary sid-btn--block">
                Aktifkan
            </button>
        </form>
    </div>
</div>
@endsection
