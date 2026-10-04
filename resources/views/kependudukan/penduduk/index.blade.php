@extends('layouts.admin')
@section('title', 'Data Penduduk')

@section('content')
<div class="sid-page-head">
    <div>
        <h1>Data Penduduk</h1>
        <p class="sid-muted sid-mb-0">
            @php $aktif = $statusCounts['aktif'] ?? 0; $total = array_sum($statusCounts); @endphp
            {{ number_format($aktif, 0, ',', '.') }} penduduk aktif
            @if ($total > $aktif)
                · {{ number_format($total - $aktif, 0, ',', '.') }} tidak aktif
                (meninggal/pindah)
            @endif
        </p>
    </div>
    @can('penduduk.tambah')
        <a href="{{ route('penduduk.create') }}" class="sid-btn sid-btn--primary">+ Tambah Penduduk</a>
    @endcan
</div>

<form method="GET" class="sid-filters">
    <div class="sid-field">
        <label class="sid-label" for="q">Cari</label>
        <input type="search" id="q" name="q" value="{{ $q }}" class="sid-input"
               placeholder="Nama, NIK, atau no. KK">
        <p class="sid-hint">NIK &amp; no. KK dicari persis; nama boleh sebagian.</p>
    </div>

    <x-select name="region_id" label="Wilayah (RT)" :options="$regions"
              :selected="$filters['region_id'] ?? null" placeholder="Semua wilayah" />

    <x-select name="gender" label="Jenis kelamin"
              :options="['L' => 'Laki-laki', 'P' => 'Perempuan']"
              :selected="$filters['gender'] ?? null" placeholder="Semua" />

    <x-select name="status" label="Status"
              :options="['aktif' => 'Aktif', 'meninggal' => 'Meninggal', 'pindah_keluar' => 'Pindah keluar']"
              :selected="$filters['status'] ?? null" placeholder="Semua status" />

    <div class="sid-filters__actions">
        <button type="submit" class="sid-btn sid-btn--primary">Saring</button>
        <a href="{{ route('penduduk.index') }}" class="sid-btn sid-btn--secondary">Reset</a>
    </div>
</form>

@php $items = $residents->items(); @endphp

@if (count($items) === 0)
    <div class="sid-empty">
        <div class="sid-empty__icon" aria-hidden="true">👥</div>
        <h2>Belum ada data yang cocok</h2>
        @if ($q || array_filter($filters))
            <p class="sid-muted">Coba ubah kata kunci atau hapus sebagian saringan.</p>
            <a href="{{ route('penduduk.index') }}" class="sid-btn sid-btn--secondary">Hapus saringan</a>
        @else
            <p class="sid-muted">Mulai dengan menambahkan penduduk pertama, atau impor dari Excel (Sprint 3).</p>
            @can('penduduk.tambah')
                <a href="{{ route('penduduk.create') }}" class="sid-btn sid-btn--primary">+ Tambah Penduduk</a>
            @endcan
        @endif
    </div>
@else
    {{-- Tampilan layar lebar --}}
    <div class="sid-table-wrap">
        <table class="sid-table">
            <caption>Daftar penduduk dalam lingkup akses Anda</caption>
            <thead>
                <tr>
                    <th scope="col">Nama</th>
                    <th scope="col">NIK</th>
                    <th scope="col">L/P</th>
                    <th scope="col" class="sid-table__num">Umur</th>
                    <th scope="col">Wilayah</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="sid-sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $r)
                    <tr>
                        <td><a href="{{ route('penduduk.show', $r->id) }}">{{ $r->full_name }}</a></td>
                        <td><x-sensitive :masked="$r->nik_masked"
                                         :url="route('penduduk.reveal-nik', $r->id)" /></td>
                        <td>{{ $r->gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</td>
                        <td class="sid-table__num">{{ $r->age() }}</td>
                        <td>{{ $r->region?->shortPath() ?? '—' }}</td>
                        <td>
                            <span class="sid-status sid-status--{{ $r->status }}">
                                {{ ucfirst(str_replace('_', ' ', $r->status)) }}
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('penduduk.show', $r->id) }}"
                               class="sid-btn sid-btn--text">Lihat</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Tampilan ponsel: kartu, tidak pernah gulir horizontal --}}
    <div class="sid-cards">
        @foreach ($items as $r)
            <article class="sid-card-row">
                <div class="sid-card-row__title">
                    <a href="{{ route('penduduk.show', $r->id) }}">{{ $r->full_name }}</a>
                </div>
                <div class="sid-card-row__meta">
                    <span>{{ $r->gender === 'L' ? 'Laki-laki' : 'Perempuan' }}, {{ $r->age() }} th</span>
                    <span>{{ $r->region?->shortPath() ?? '—' }}</span>
                    <span class="sid-status sid-status--{{ $r->status }}">
                        {{ ucfirst(str_replace('_', ' ', $r->status)) }}
                    </span>
                </div>
                <div class="sid-card-row__meta">
                    <x-sensitive :masked="$r->nik_masked"
                                 :url="route('penduduk.reveal-nik', $r->id)" />
                </div>
            </article>
        @endforeach
    </div>

    <div class="sid-pagination">
        <span class="sid-muted sid-text-sm">
            Menampilkan {{ count($items) }} data
        </span>
        <span>
            @if ($residents->previousPageUrl())
                <a href="{{ $residents->previousPageUrl() }}" class="sid-btn sid-btn--secondary">
                    ‹ Sebelumnya
                </a>
            @endif
            @if ($residents->nextPageUrl())
                <a href="{{ $residents->nextPageUrl() }}" class="sid-btn sid-btn--secondary">
                    Berikutnya ›
                </a>
            @endif
        </span>
    </div>
@endif
@endsection

@push('scripts')
<script src="{{ asset('js/sid.js') }}" defer></script>
@endpush
