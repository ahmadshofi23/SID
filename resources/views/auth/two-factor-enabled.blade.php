@extends('layouts.app')
@section('title', 'Verifikasi Dua Langkah')

@section('content')
<div class="sid-container--narrow">
    <div class="sid-card">
        <h2 class="sid-card__title">Verifikasi dua langkah</h2>
        <x-alert type="success">
            Verifikasi dua langkah sudah aktif untuk akun Anda.
        </x-alert>
        <a href="{{ route('dashboard') }}" class="sid-btn sid-btn--secondary">
            Kembali ke dashboard
        </a>
    </div>
</div>
@endsection
