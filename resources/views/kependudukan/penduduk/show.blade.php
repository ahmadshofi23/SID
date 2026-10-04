@extends('layouts.admin')
@section('title', $resident->full_name)

@section('content')
<nav class="sid-breadcrumb" aria-label="Remah roti">
    <a href="{{ route('penduduk.index') }}">Data Penduduk</a> › {{ $resident->full_name }}
</nav>

<div class="sid-page-head">
    <div>
        <h1>{{ $resident->full_name }}</h1>
        <p class="sid-muted sid-mb-0">
            <span class="sid-status sid-status--{{ $resident->status }}">
                {{ ucfirst(str_replace('_', ' ', $resident->status)) }}
            </span>
            · {{ $resident->age() }} tahun
            · {{ $resident->region?->displayPath() ?? 'Wilayah belum ditetapkan' }}
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @can('penduduk.ubah')
            <a href="{{ route('penduduk.edit', $resident->id) }}"
               class="sid-btn sid-btn--secondary">Ubah Data</a>
        @endcan
        @can('penduduk.hapus')
            <x-confirm-form
                :action="route('penduduk.destroy', $resident->id)"
                title="Hapus data {{ $resident->full_name }}?"
                body="Data tidak hilang permanen — ia disembunyikan dan masih dapat dipulihkan Admin Desa. Surat yang pernah terbit atas nama orang ini tetap tersimpan."
                confirmLabel="Ya, hapus data"
                triggerLabel="Hapus" />
        @endcan
    </div>
</div>

@if ($resident->status !== 'aktif')
    <x-alert type="warning" title="Penduduk tidak aktif">
        Penduduk berstatus <strong>{{ str_replace('_', ' ', $resident->status) }}</strong>
        tidak dihitung dalam statistik dan tidak dapat mengajukan surat (RULE-03).
    </x-alert>
@endif

<div class="sid-card">
    <h2>Identitas</h2>
    <dl class="sid-dl">
        <dt>NIK</dt>
        <dd><x-sensitive :masked="$resident->nik_masked"
                         :url="route('penduduk.reveal-nik', $resident->id)" /></dd>

        <dt>Nama lengkap</dt><dd>{{ $resident->full_name }}</dd>
        <dt>Tempat, tanggal lahir</dt>
        <dd>{{ $resident->birth_place ?: '—' }},
            {{ $resident->birth_date->translatedFormat('d F Y') }}
            ({{ $resident->age() }} tahun)</dd>
        <dt>Jenis kelamin</dt>
        <dd>{{ $resident->gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</dd>
        <dt>Agama</dt>
        <dd>{{ $references['religions'][$resident->religion_code] ?? '—' }}</dd>
        <dt>Status perkawinan</dt>
        <dd>{{ $references['marital_statuses'][$resident->marital_status_code] ?? '—' }}</dd>
        <dt>Pendidikan</dt>
        <dd>{{ $references['educations'][$resident->education_code] ?? '—' }}</dd>
        <dt>Pekerjaan</dt>
        <dd>{{ $references['occupations'][$resident->occupation_code] ?? '—' }}</dd>
        <dt>Golongan darah</dt>
        <dd>{{ $references['blood_types'][$resident->blood_type_code] ?? '—' }}</dd>
        <dt>Kewarganegaraan</dt><dd>{{ $resident->citizenship }}</dd>
        @if ($resident->disability_code)
            <dt>Disabilitas</dt>
            <dd>{{ $references['disabilities'][$resident->disability_code] ?? '—' }}</dd>
        @endif
    </dl>
</div>

<div class="sid-card sid-mt-4">
    <h2>Keluarga &amp; alamat</h2>
    <dl class="sid-dl">
        <dt>Hubungan dalam keluarga</dt>
        <dd>{{ $references['family_relations'][$resident->family_relation_code] ?? '—' }}</dd>
        <dt>Kartu keluarga</dt>
        <dd>
            @if ($resident->family)
                <a href="{{ route('keluarga.show', $resident->family->id) }}">
                    {{ $resident->family->kk_number_masked }}
                </a>
            @else
                <span class="sid-muted">Belum tergabung dalam kartu keluarga</span>
            @endif
        </dd>
        <dt>Alamat</dt><dd>{{ $resident->address ?: '—' }}</dd>
        <dt>Wilayah</dt><dd>{{ $resident->region?->displayPath() ?? '—' }}</dd>
        <dt>Nama ayah</dt><dd>{{ $resident->father_name ?: '—' }}</dd>
        <dt>Nama ibu</dt><dd>{{ $resident->mother_name ?: '—' }}</dd>
        <dt>Telepon</dt><dd>{{ $resident->phone ?: '—' }}</dd>
    </dl>
</div>

@if ($members->isNotEmpty())
    <div class="sid-card sid-mt-4">
        <h2>Anggota keluarga ({{ $members->count() }})</h2>
        <ul style="list-style:none;padding:0;margin:0">
            @foreach ($members as $member)
                <li style="padding:8px 0;border-bottom:1px solid var(--color-border)">
                    @if ($member->id === $resident->id)
                        <strong>{{ $member->full_name }}</strong> (orang ini)
                    @else
                        <a href="{{ route('penduduk.show', $member->id) }}">{{ $member->full_name }}</a>
                    @endif
                    <span class="sid-muted sid-text-sm">
                        — {{ $references['family_relations'][$member->family_relation_code] ?? '—' }},
                        {{ $member->age() }} th
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endif

<div class="sid-card sid-mt-4">
    <h2>Riwayat perubahan</h2>
    <p class="sid-text-sm sid-muted">
        Setiap perubahan data tercatat permanen dan tidak dapat dihapus (BR-02).
    </p>

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
                    @if (! empty($entry['catatan']['alasan']))
                        <div class="sid-timeline__change">
                            Alasan: {{ $entry['catatan']['alasan'] }}
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/sid.js') }}" defer></script>
@endpush
