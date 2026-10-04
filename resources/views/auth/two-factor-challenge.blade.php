@extends('layouts.guest')
@section('title', 'Verifikasi Dua Langkah')

@section('content')
<div class="sid-card sid-card--auth">
    <h2 class="sid-card__title">Verifikasi dua langkah</h2>
    <p class="sid-card__hint">
        Buka aplikasi autentikator di ponsel Anda, lalu masukkan 6 angka yang tampil.
    </p>

    <form method="POST" action="{{ route('two-factor.verify', ['challenge' => $challenge]) }}"
          class="sid-mt-4">
        @csrf

        <x-field
            name="code"
            label="Kode verifikasi"
            :required="true"
            inputmode="numeric"
            autocomplete="one-time-code"
            :autofocus="true"
            inputClass="sid-input--code"
            hint="Kode berganti setiap 30 detik. Anda juga dapat memakai salah satu kode pemulihan."
        />

        <button type="submit" class="sid-btn sid-btn--primary sid-btn--block">Verifikasi</button>
    </form>

    <p class="sid-hint sid-mt-4 sid-mb-0">
        Ponsel hilang atau aplikasi terhapus? Gunakan kode pemulihan yang Anda simpan
        saat mengaktifkan verifikasi dua langkah. Bila kode itu juga tidak ada,
        hubungi Admin Desa.
    </p>
</div>
@endsection
