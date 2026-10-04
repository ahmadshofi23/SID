@extends('layouts.guest')
@section('title', 'Atur Kata Sandi Baru')

@section('content')
<div class="sid-card sid-card--auth">
    <h2 class="sid-card__title">Atur kata sandi baru</h2>

    <form method="POST" action="{{ route('password.update') }}" class="sid-mt-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        @error('token')
            <x-alert type="danger">{{ $message }}</x-alert>
        @enderror

        <x-field name="identifier" label="Email atau nomor ponsel"
                 :value="$identifier" :required="true" autocomplete="username" />

        <div class="sid-field">
            <label class="sid-label" for="password">
                Kata sandi baru
                <span class="sid-label__required" aria-hidden="true">*</span>
            </label>
            <input id="password" name="password" type="password" required
                   autocomplete="new-password" aria-describedby="password-hint"
                   class="sid-input @error('password') sid-input--invalid @enderror">
            @error('password')
                <p class="sid-error" role="alert">{{ $message }}</p>
            @else
                <p class="sid-hint" id="password-hint">
                    Minimal {{ config('sid.auth.password_min_length') }} karakter.
                    Lebih mudah dan lebih aman: gabungkan tiga kata yang tidak
                    berhubungan, misalnya "kursi-hujan-mangga".
                </p>
            @enderror
        </div>

        <div class="sid-field">
            <label class="sid-label" for="password_confirmation">
                Ulangi kata sandi baru
                <span class="sid-label__required" aria-hidden="true">*</span>
            </label>
            <input id="password_confirmation" name="password_confirmation" type="password"
                   required autocomplete="new-password" class="sid-input">
        </div>

        <button type="submit" class="sid-btn sid-btn--primary sid-btn--block">
            Simpan kata sandi baru
        </button>
    </form>
</div>
@endsection
