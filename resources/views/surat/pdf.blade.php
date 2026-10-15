{{--
  Lembar surat untuk PDF — ADR-003, KEP-013.

  MENGAPA ADA DUA TEMPLATE. Halaman cetak di layar adalah komponen Vue
  (resources/js/Pages/Surat/Arsip/Cetak.vue); berkas ini adalah dokumen HTML
  mandiri untuk Chromium. Keduanya tidak dapat disatukan: Chromium merender
  lewat `file://` atau di kontainer tanpa akses ke aplikasi, sehingga tidak
  ada JavaScript, tidak ada Vite, dan tidak ada `/storage/...`.

  Yang WAJIB tetap sama di antara keduanya adalah ukuran kertas, jenis huruf,
  dan jarak — itulah yang menentukan di mana baris tanda tangan jatuh.

  BADAN SURAT tidak dirakit di sini: `rendered_html` dicetak apa adanya.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Surat {{ $letter->letter_number }}</title>
<style>
    /*
     * SATU-SATUNYA tempat ukuran kertas ditetapkan.
     *
     * Gotenberg diminta menghormati aturan ini (preferCssPageSize) dan
     * Chrome --print-to-pdf memang hanya mengenal aturan ini. Dengan begitu
     * kedua driver menghasilkan halaman yang sama persis — KEP-013 §4.
     * F4 (215 × 330 mm), bukan A4: itu kertas yang dipakai kantor desa.
     */
    @page {
        size: {{ $paper->widthMm }}mm {{ $paper->heightMm }}mm;
        margin: 0;
    }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
        background: #fff;
        color: #000;
    }

    body {
        font-family: 'Times New Roman', Times, serif;
        font-size: 12pt;
        line-height: 1.5;
    }

    .letter-sheet {
        width: {{ $paper->widthMm }}mm;
        min-height: {{ $paper->heightMm }}mm;
        padding: 15mm 20mm;
        position: relative;
    }

    .letter-head { display: flex; align-items: center; gap: 6mm; }
    .letter-head .logo { width: 20mm; height: 20mm; object-fit: contain; }
    .letter-head .identity { flex: 1; text-align: center; line-height: 1.25; }
    .letter-head .identity p { margin: 0; }
    .line-1 { font-size: 11pt; text-transform: uppercase; }
    .line-village { font-size: 16pt; font-weight: bold; text-transform: uppercase; }
    .line-small { font-size: 9pt; }
    .head-rule { margin-top: 2mm; border: 0; border-top: 2px solid #000; }

    /* Tanda air: surat yang dibatalkan tetap boleh dicetak untuk arsip,
       tetapi TIDAK BOLEH tampak sah. */
    .letter-void {
        position: absolute;
        left: 0; right: 0; top: 40%;
        text-align: center;
        font-size: 72pt;
        font-weight: bold;
        color: rgba(220, 38, 38, 0.18);
        transform: rotate(-24deg);
        letter-spacing: 0.1em;
    }

    .letter-body { margin-top: 8mm; }
    .letter-title { text-align: center; margin-bottom: 6mm; }
    .letter-title h1 {
        font-size: 12pt; font-weight: bold; text-transform: uppercase;
        text-decoration: underline; margin: 0;
    }
    .letter-title p { margin: 1mm 0 0; font-size: 11pt; }

    .letter-content { text-align: justify; }
    .letter-content p { margin: 0 0 0.75rem; }
    .letter-content table { margin: 0.5rem 0 0.75rem 1.5rem; border-collapse: collapse; }
    .letter-content td { padding: 0.1rem 0.4rem 0.1rem 0; vertical-align: top; }
    .letter-content pre { font-family: inherit; white-space: pre-wrap; margin: 0.5rem 0 0.75rem 1.5rem; }

    .letter-foot {
        margin-top: 12mm;
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 10mm;
    }
    .qr-block { text-align: center; font-size: 8pt; width: 40mm; }
    .qr-block svg { width: 24mm; height: 24mm; display: block; margin: 0 auto; }
    /*
     * Kode verifikasi harus DAPAT DIKETIK ULANG dari kertas oleh petugas
     * bank atau sekolah yang pemindai QR-nya tidak berfungsi. Karena itu
     * dikelompokkan empat-empat dan tidak dikecilkan lebih jauh; memaksanya
     * muat dalam satu baris akan membuatnya tidak terbaca.
     */
    .qr-block .code {
        font-family: 'Courier New', monospace;
        font-size: 7pt;
        line-height: 1.3;
        margin-top: 1mm;
    }
    .sign-block { text-align: center; }
    .sign-block p { margin: 0; }
    .sign-space { height: 20mm; }
    .sign-name { font-weight: bold; text-decoration: underline; }
</style>
</head>
<body>
<article class="letter-sheet">
    <header>
        <div class="letter-head">
            @if ($logoDataUri !== null)
                <img class="logo" src="{{ $logoDataUri }}" alt="">
            @endif
            <div class="identity">
                <p class="line-1">Pemerintah Kabupaten {{ $village->regency }}</p>
                <p class="line-1">Kecamatan {{ $village->district }}</p>
                <p class="line-village">Desa {{ $village->name }}</p>
                @if ($village->address)
                    <p class="line-small">
                        {{ $village->address }}@if ($village->postal_code) — {{ $village->postal_code }}@endif
                    </p>
                @endif
                @if ($village->phone || $village->email)
                    <p class="line-small">
                        @if ($village->phone)Telp. {{ $village->phone }}@endif
                        @if ($village->phone && $village->email) · @endif
                        @if ($village->email){{ $village->email }}@endif
                    </p>
                @endif
            </div>
        </div>
        <hr class="head-rule">
    </header>

    @if ($letter->status === \App\Models\Letter::STATUS_CANCELLED)
        <p class="letter-void">DIBATALKAN</p>
    @endif

    <div class="letter-body">
        <div class="letter-title">
            <h1>{{ $titleOnLetter }}</h1>
            <p>Nomor: {{ $letter->letter_number }}</p>
        </div>

        {{--
          BADAN SURAT APA ADANYA dari saat penerbitan (ADR-003).

          Aman untuk {!! !!}: isinya sudah dibersihkan App\Support\HtmlSanitizer
          saat surat dirender, dan nilai variabelnya sudah di-escape saat itu.
          Membersihkannya ulang di sini justru berbahaya — aturan sanitizer
          dapat berubah, dan surat lama akan berubah tampilannya.
        --}}
        <div class="letter-content">{!! $letter->rendered_html !!}</div>
    </div>

    <footer class="letter-foot">
        {{-- QR verifikasi: penerima memeriksa keaslian tanpa menelepon
             kantor desa (FR-SRT-14). --}}
        <div class="qr-block">
            {!! $qrSvg !!}
            <p>Periksa keaslian</p>
            <p class="code">{{ implode(' ', str_split($letter->verification_code, 4)) }}</p>
        </div>

        <div class="sign-block">
            <p>{{ $village->name }}, {{ $issuedLong }}</p>
            <p>{{ $letter->signer_position }} {{ $village->name }}</p>
            <div class="sign-space"></div>
            <p class="sign-name">{{ $letter->signer_name }}</p>
        </div>
    </footer>
</article>
</body>
</html>
