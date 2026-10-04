@extends('layouts.admin')
@section('title', 'Kartu Keluarga')

@section('content')
<div class="sid-page-head">
    <div>
        <h1>Kartu Keluarga</h1>
        <p class="sid-muted sid-mb-0">Daftar kartu keluarga dalam lingkup akses Anda.</p>
    </div>
    @can('keluarga.tambah')
        <a href="{{ route('keluarga.create') }}" class="sid-btn sid-btn--primary">+ Tambah Kartu Keluarga</a>
    @endcan
</div>

<form method="GET" class="sid-filters">
    <div class="sid-field">
        <label class="sid-label" for="q">Cari</label>
        <input type="search" id="q" name="q" value="{{ $q }}" class="sid-input"
               placeholder="Nomor KK atau alamat">
        <p class="sid-hint">Nomor KK dicari persis; alamat boleh sebagian.</p>
    </div>

    <x-select name="region_id" label="Wilayah (RT)" :options="$regions"
              :selected="$filters['region_id'] ?? null" placeholder="Semua wilayah" />

    <x-select name="welfare_status_code" label="Kesejahteraan"
              :options="$references['welfare_statuses']"
              :selected="$filters['welfare_status_code'] ?? null" placeholder="Semua" />

    <div class="sid-filters__actions">
        <button type="submit" class="sid-btn sid-btn--primary">Saring</button>
        <a href="{{ route('keluarga.index') }}" class="sid-btn sid-btn--secondary">Reset</a>
    </div>
</form>

@php $items = $families->items(); @endphp

@if (count($items) === 0)
    <div class="sid-empty">
        <div class="sid-empty__icon" aria-hidden="true">🏠</div>
        <h2>Belum ada kartu keluarga</h2>
        <p class="sid-muted">Kartu keluarga dibuat lebih dulu, lalu anggotanya ditambahkan.</p>
        @can('keluarga.tambah')
            <a href="{{ route('keluarga.create') }}" class="sid-btn sid-btn--primary">+ Tambah Kartu Keluarga</a>
        @endcan
    </div>
@else
    <div class="sid-table-wrap">
        <table class="sid-table">
            <caption>Kartu keluarga</caption>
            <thead>
                <tr>
                    <th scope="col">Nomor KK</th>
                    <th scope="col">Alamat</th>
                    <th scope="col">Wilayah</th>
                    <th scope="col" class="sid-table__num">Anggota</th>
                    <th scope="col"><span class="sid-sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $f)
                    <tr>
                        <td><x-sensitive :masked="$f->kk_number_masked"
                                         :url="route('keluarga.reveal-kk', $f->id)"
                                         label="Nomor KK" /></td>
                        <td><a href="{{ route('keluarga.show', $f->id) }}">{{ $f->address }}</a></td>
                        <td>{{ $f->region?->shortPath() ?? '—' }}</td>
                        <td class="sid-table__num">{{ $f->members_count }}</td>
                        <td><a href="{{ route('keluarga.show', $f->id) }}" class="sid-btn sid-btn--text">Lihat</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="sid-cards">
        @foreach ($items as $f)
            <article class="sid-card-row">
                <div class="sid-card-row__title">
                    <a href="{{ route('keluarga.show', $f->id) }}">{{ $f->address }}</a>
                </div>
                <div class="sid-card-row__meta">
                    <x-sensitive :masked="$f->kk_number_masked"
                                 :url="route('keluarga.reveal-kk', $f->id)" label="Nomor KK" />
                    <span>{{ $f->region?->shortPath() ?? '—' }}</span>
                    <span>{{ $f->members_count }} anggota</span>
                </div>
            </article>
        @endforeach
    </div>

    <div class="sid-pagination">
        <span class="sid-muted sid-text-sm">Menampilkan {{ count($items) }} kartu keluarga</span>
        <span>
            @if ($families->previousPageUrl())
                <a href="{{ $families->previousPageUrl() }}" class="sid-btn sid-btn--secondary">‹ Sebelumnya</a>
            @endif
            @if ($families->nextPageUrl())
                <a href="{{ $families->nextPageUrl() }}" class="sid-btn sid-btn--secondary">Berikutnya ›</a>
            @endif
        </span>
    </div>
@endif
@endsection

@push('scripts')
<script src="{{ asset('js/sid.js') }}" defer></script>
@endpush
