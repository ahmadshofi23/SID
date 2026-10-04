@extends('layouts.admin')
@section('title', 'Kartu Keluarga')

@section('content')
<nav class="sid-breadcrumb" aria-label="Remah roti">
    <a href="{{ route('keluarga.index') }}">Kartu Keluarga</a> › {{ $family->kk_number_masked }}
</nav>

<div class="sid-page-head">
    <div>
        <h1>{{ $family->address }}</h1>
        <p class="sid-muted sid-mb-0">
            <x-sensitive :masked="$family->kk_number_masked"
                         :url="route('keluarga.reveal-kk', $family->id)" label="Nomor KK" />
            · {{ $family->region?->displayPath() ?? 'Wilayah belum ditetapkan' }}
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @can('keluarga.ubah')
            <a href="{{ route('keluarga.edit', $family->id) }}" class="sid-btn sid-btn--secondary">Ubah</a>
        @endcan
        @can('penduduk.tambah')
            <a href="{{ route('penduduk.create', ['family_id' => $family->id]) }}"
               class="sid-btn sid-btn--primary">+ Tambah Anggota</a>
        @endcan
    </div>
</div>

@php $hasHead = $members->contains(fn ($m) => $m->isHeadOfFamily()); @endphp

@if ($members->isNotEmpty() && ! $hasHead)
    <x-alert type="warning" title="Belum ada kepala keluarga">
        Kartu keluarga ini belum memiliki kepala keluarga. Tetapkan salah satu anggota
        sebagai kepala keluarga agar data administrasi lengkap (RULE-02).
    </x-alert>
@endif

<div class="sid-card">
    <h2>Anggota keluarga ({{ $members->count() }})</h2>

    @if ($members->isEmpty())
        <p class="sid-muted">
            Belum ada anggota. Tambahkan kepala keluarga terlebih dahulu.
        </p>
        @can('penduduk.tambah')
            <a href="{{ route('penduduk.create', ['family_id' => $family->id]) }}"
               class="sid-btn sid-btn--primary">+ Tambah Anggota</a>
        @endcan
    @else
        <div class="sid-table-wrap" style="border:none">
            <table class="sid-table">
                <caption class="sid-sr-only">Anggota kartu keluarga</caption>
                <thead>
                    <tr>
                        <th scope="col">Nama</th>
                        <th scope="col">Hubungan</th>
                        <th scope="col">L/P</th>
                        <th scope="col" class="sid-table__num">Umur</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $m)
                        <tr>
                            <td><a href="{{ route('penduduk.show', $m->id) }}">{{ $m->full_name }}</a></td>
                            <td>
                                {{ $references['family_relations'][$m->family_relation_code] ?? '—' }}
                                @if ($m->isHeadOfFamily())
                                    <span class="sid-badge">Kepala keluarga</span>
                                @endif
                            </td>
                            <td>{{ $m->gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</td>
                            <td class="sid-table__num">{{ $m->age() }}</td>
                            <td><span class="sid-status sid-status--{{ $m->status }}">
                                {{ ucfirst(str_replace('_', ' ', $m->status)) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="sid-card sid-mt-4">
    <h2>Keterangan</h2>
    <dl class="sid-dl">
        <dt>Alamat</dt><dd>{{ $family->address }}</dd>
        <dt>RT / RW</dt><dd>{{ $family->rt ?: '—' }} / {{ $family->rw ?: '—' }}</dd>
        <dt>Kode pos</dt><dd>{{ $family->postal_code ?: '—' }}</dd>
        <dt>Status kesejahteraan</dt>
        <dd>{{ $references['welfare_statuses'][$family->welfare_status_code] ?? '—' }}</dd>
        <dt>Kepemilikan rumah</dt>
        <dd>{{ $references['house_ownerships'][$family->house_ownership_code] ?? '—' }}</dd>
    </dl>
</div>

<div class="sid-card sid-mt-4">
    <h2>Riwayat perubahan</h2>
    @if ($history->isEmpty())
        <p class="sid-muted sid-mb-0">Belum ada riwayat tercatat.</p>
    @else
        <ul class="sid-timeline">
            @foreach ($history as $entry)
                <li>
                    <div><strong>{{ $entry['aksi'] }}</strong></div>
                    <div class="sid-timeline__meta">
                        {{ $entry['pelaku'] }} ·
                        {{ $entry['waktu']->setTimezone(config('sid.timezone'))
                            ->translatedFormat('d M Y, H:i') }} WIB
                    </div>
                    @foreach ($entry['perubahan'] as $change)
                        <div class="sid-timeline__change">
                            {{ $change['field'] }}:
                            <span class="sid-timeline__old">{{ $change['lama'] }}</span>
                            → <strong>{{ $change['baru'] }}</strong>
                        </div>
                    @endforeach
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/sid.js') }}" defer></script>
@endpush
