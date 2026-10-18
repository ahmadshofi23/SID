@props([
    'title',
    'description' => null,
    'heading' => null,
    'lead' => null,
])

{{--
  Kerangka portal publik.

  MENGAPA BLADE, BUKAN INERTIA/VUE (KEP-016)
  ------------------------------------------
  Panel administrasi memakai Vue + Inertia (KEP-008), tetapi portal publik
  tidak: docs/07 §7 mewajibkan halaman portal berfungsi TANPA JavaScript, dan
  docs/07 §8 menetapkan LCP ≤ 2,5 s pada 4G dengan transfer awal ≤ 500 KB
  (NFR-PERF-03/04). Inertia yang dirender di peramban memberi halaman kosong
  bila JavaScript gagal dimuat — dan di jaringan desa itu bukan kemungkinan
  teoretis. Inertia SSR akan memperbaikinya, tetapi menuntut proses Node
  berjalan di produksi, yang bertentangan dengan tuntutan serah terima ke
  perangkat desa (docs/01 §6, alasan asli KEP-005 dan KEP-007).

  Gayanya tetap satu sumber: `resources/css/app.css` yang sama dengan panel,
  sehingga portal tidak menyimpang rupa dari sistemnya.

  CATATAN ROBOTS: app.blade.php (akar Inertia) memasang `noindex, nofollow`
  untuk SELURUH panel administrasi. Portal justru harus terindeks (FR-PRT-08),
  karena itu ia punya kerangka <head> sendiri dan tidak mewarisi akar itu.
--}}
@php
    $deskripsi = $description ?? sprintf(
        'Situs resmi Pemerintah Desa %s, Kecamatan %s, Kabupaten %s. Profil desa, standar pelayanan, dan statistik penduduk.',
        $village->name, $village->district, $village->regency
    );

    $judulLengkap = $village->name
        ? $title.' — Desa '.$village->name
        : $title;

    $menu = [
        ['label' => 'Beranda', 'route' => 'portal.beranda'],
        ['label' => 'Profil', 'route' => 'portal.profil'],
        ['label' => 'Pemerintahan', 'route' => 'portal.pemerintahan'],
        ['label' => 'Berita', 'route' => 'portal.berita'],
        ['label' => 'Pengumuman', 'route' => 'portal.pengumuman'],
        ['label' => 'Agenda', 'route' => 'portal.agenda'],
        ['label' => 'Layanan', 'route' => 'portal.layanan'],
        ['label' => 'Statistik', 'route' => 'portal.statistik'],
        ['label' => 'Kontak', 'route' => 'portal.kontak'],
    ];

    /*
     * Data terstruktur schema.org (FR-PRT-08). Dipakai GovernmentOrganization,
     * bukan Organization: mesin pencari menampilkan panel pengetahuan yang
     * berbeda untuk instansi pemerintah, dan desa memang instansi.
     */
    $jsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'GovernmentOrganization',
        'name' => $village->name ? 'Pemerintah Desa '.$village->name : null,
        'url' => route('portal.beranda'),
        'telephone' => $village->phone,
        'email' => $village->email,
        'address' => array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $village->address,
            'addressLocality' => $village->district,
            'addressRegion' => $village->province,
            'postalCode' => $village->postal_code,
            'addressCountry' => 'ID',
        ]),
    ]);
@endphp

<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $judulLengkap }}</title>
    <meta name="description" content="{{ $deskripsi }}">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ url()->current() }}">

    {{-- Open Graph: dipakai saat tautan desa dibagikan di WhatsApp, yang di
         sini adalah kanal penyebaran informasi desa yang sebenarnya. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Desa {{ $village->name }}">
    <meta property="og:locale" content="id_ID">
    <meta property="og:title" content="{{ $judulLengkap }}">
    <meta property="og:description" content="{{ $deskripsi }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="twitter:card" content="summary">

    @include('partials.tema')

    @vite(['resources/css/app.css'])

    <script type="application/ld+json" nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
        {!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
</head>
<body class="flex min-h-full flex-col antialiased">

{{-- Lewati navigasi: wajib bagi pengguna papan tuts & pembaca layar (WCAG 2.4.1) --}}
<a href="#isi"
   class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-accent focus:px-4 focus:py-2 focus:text-content-inverse">
    Lewati ke isi halaman
</a>

<header class="border-b border-line bg-surface">
    <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-3">
        <a href="{{ route('portal.beranda') }}" class="flex min-w-0 items-center gap-2.5">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent" aria-hidden="true">
                {{-- Ikon sebaris; portal tidak memuat paket ikon apa pun. --}}
                <svg class="size-5 text-content-inverse" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10 18v-7"/><path d="M11.12 2.198a2 2 0 0 1 1.76.006l7.866 3.847c.476.233.31.949-.22.949H3.474c-.53 0-.695-.716-.22-.949z"/>
                    <path d="M14 18v-7"/><path d="M18 18v-7"/><path d="M3 22h18"/><path d="M6 18v-7"/>
                </svg>
            </span>
            <span class="min-w-0">
                <span class="block truncate font-semibold leading-tight">Desa {{ $village->name ?: 'Contoh' }}</span>
                <span class="block truncate text-xs text-content-secondary">
                    Kec. {{ $village->district }}, Kab. {{ $village->regency }}
                </span>
            </span>
        </a>

        <nav class="ml-auto hidden lg:block" aria-label="Navigasi utama">
            <ul class="flex items-center gap-1">
                @foreach ($menu as $item)
                    <li>
                        <a href="{{ route($item['route']) }}"
                           @class([
                               'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                               'bg-accent-soft text-accent-text' => request()->routeIs($item['route']),
                               'text-content-secondary hover:bg-surface-hover hover:text-content' => ! request()->routeIs($item['route']),
                           ])
                           @if (request()->routeIs($item['route'])) aria-current="page" @endif>
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        {{--
          Menu ponsel memakai <details>, bukan tombol ber-JavaScript.
          Elemen ini membuka-menutup sendiri di peramban, jadi navigasi portal
          tetap hidup walau berkas JS gagal dimuat (docs/07 §7).
        --}}
        <details class="relative ml-auto lg:hidden">
            <summary class="touch-target flex cursor-pointer list-none items-center justify-center rounded-lg px-3 text-content-secondary hover:bg-surface-hover">
                <span class="sr-only">Buka menu</span>
                <svg class="size-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>
                </svg>
            </summary>
            <nav class="absolute right-0 z-40 mt-2 w-56 rounded-lg border border-line bg-surface p-2 shadow-overlay"
                 aria-label="Navigasi utama">
                <ul>
                    @foreach ($menu as $item)
                        <li>
                            <a href="{{ route($item['route']) }}"
                               @class([
                                   'touch-target flex items-center rounded-lg px-3 text-sm font-medium',
                                   'bg-accent-soft text-accent-text' => request()->routeIs($item['route']),
                                   'text-content-secondary hover:bg-surface-hover' => ! request()->routeIs($item['route']),
                               ])>
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                    <li class="my-1 border-t border-line"></li>
                    <li>
                        <a href="{{ route('surat.lacak') }}"
                           class="touch-target flex items-center rounded-lg px-3 text-sm font-medium text-content-secondary hover:bg-surface-hover">
                            Lacak status surat
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('daftar') }}"
                           class="touch-target flex items-center rounded-lg px-3 text-sm font-medium text-content-secondary hover:bg-surface-hover">
                            Daftar akun warga
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('login') }}"
                           class="touch-target flex items-center rounded-lg px-3 text-sm font-medium text-accent hover:bg-surface-hover">
                            Masuk ke sistem
                        </a>
                    </li>
                </ul>
            </nav>
        </details>
    </div>
</header>

<main id="isi" class="flex-1">
    @if ($heading)
        <div class="border-b border-line bg-surface-sunken">
            <div class="mx-auto max-w-5xl px-4 py-8">
                <h1 class="text-2xl font-semibold">{{ $heading }}</h1>
                @if ($lead)
                    <p class="mt-2 max-w-2xl text-content-secondary">{{ $lead }}</p>
                @endif
            </div>
        </div>
    @endif

    {{ $slot }}
</main>

<footer class="mt-12 border-t border-line bg-surface">
    <div class="mx-auto grid max-w-5xl gap-8 px-4 py-10 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <p class="font-semibold">Pemerintah Desa {{ $village->name }}</p>
            @if ($village->address)
                <p class="mt-2 text-sm text-content-secondary">{{ $village->address }}</p>
            @endif
            <p class="text-sm text-content-secondary">
                Kec. {{ $village->district }}, Kab. {{ $village->regency }}{{ $village->province ? ', '.$village->province : '' }}
                {{ $village->postal_code }}
            </p>
        </div>

        <div>
            <p class="font-semibold">Hubungi</p>
            <ul class="mt-2 space-y-1 text-sm text-content-secondary">
                @if ($village->phone)
                    <li>Telepon: <a class="text-accent hover:underline" href="tel:{{ $village->phone }}">{{ $village->phone }}</a></li>
                @endif
                @if ($village->email)
                    <li>Surel: <a class="text-accent hover:underline" href="mailto:{{ $village->email }}">{{ $village->email }}</a></li>
                @endif
                <li><a class="text-accent hover:underline" href="{{ route('portal.kontak') }}">Jam layanan & lokasi kantor</a></li>
            </ul>
        </div>

        <div>
            <p class="font-semibold">Layanan mandiri</p>
            {{--
              Dua tautan ini sengaja ada di SETIAP halaman portal. Sebelum
              portal dibangun, pelacakan status hanya dapat dibuka oleh yang
              mengetahui URL-nya; menurut KEP-014 §5 halaman itu tetap hidup
              sebagai "cek resi" tanpa login bahkan setelah akun warga ada,
              jadi pintunya tidak boleh hilang dari halaman depan.
            --}}
            <ul class="mt-2 space-y-1 text-sm">
                <li><a class="text-accent hover:underline" href="{{ route('portal.layanan') }}">Standar pelayanan surat</a></li>
                <li><a class="text-accent hover:underline" href="{{ route('surat.lacak') }}">Lacak status pengajuan surat</a></li>
                <li><a class="text-accent hover:underline" href="{{ route('daftar') }}">Daftar akun warga</a></li>
                <li><a class="text-accent hover:underline" href="{{ route('login') }}">Masuk ke sistem (petugas)</a></li>
            </ul>
        </div>
    </div>

    <div class="border-t border-line">
        <p class="mx-auto max-w-5xl px-4 py-4 text-xs text-content-tertiary">
            © {{ \App\Support\VillageTime::now()->year }} Pemerintah Desa {{ $village->name }}.
            Dikelola dengan Sistem Informasi Desa.
        </p>
    </div>
</footer>

</body>
</html>
