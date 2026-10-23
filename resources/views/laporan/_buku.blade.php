{{--
  Kerangka buku register — Permendagri 47/2016, FR-LAP-06.

  MENGAPA DOKUMEN HTML MANDIRI, bukan komponen Vue: alasannya sama persis
  dengan lembar surat (surat/pdf.blade.php). Chromium merender ini lewat
  `file://` atau di kontainer Gotenberg yang terpisah — tidak ada JavaScript,
  tidak ada Vite, tidak ada `/storage/...`.

  MENDATAR, bukan menegak: buku register punya sepuluh kolom. Dicetak
  menegak, kolom terakhir menyempit sampai tidak terbaca dan pemeriksa
  kecamatan akan mengembalikannya.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>{{ $judul }}</title>
<style>
    /*
     * Ukuran kertas ditetapkan di SINI dan hanya di sini, sehingga kedua
     * driver PDF (Gotenberg preferCssPageSize dan Chrome --print-to-pdf)
     * menghasilkan halaman yang sama — KEP-013 §4. Lebar dan tinggi sudah
     * ditukar oleh AdministrationBookService.
     */
    @page {
        size: {{ $paper->widthMm }}mm {{ $paper->heightMm }}mm;
        margin: 12mm 10mm;
    }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
        background: #fff;
        color: #000;
        font-family: "Times New Roman", Times, serif;
        font-size: 9pt;
        line-height: 1.3;
    }

    header { text-align: center; margin-bottom: 8mm; }
    header h1 { margin: 0; font-size: 13pt; text-transform: uppercase; letter-spacing: 0.5pt; }
    header h2 { margin: 1mm 0 0; font-size: 11pt; font-weight: normal; }
    header .desa { margin-top: 2mm; font-size: 10pt; }

    /*
     * Keterangan lingkup dicetak di kepala halaman, bukan di catatan kaki.
     *
     * Dua orang dapat mencetak buku yang sama pada hari yang sama dan
     * memperoleh jumlah baris berbeda — yang satu Admin Desa, yang satu
     * Kepala Dusun — dan itu BENAR. Tanpa keterangan ini keduanya akan
     * dibandingkan sebagai dua versi dari angka yang sama.
     */
    .lingkup {
        margin-top: 3mm;
        font-size: 9pt;
        font-style: italic;
    }

    table { width: 100%; border-collapse: collapse; }

    th, td {
        border: 0.4mm solid #000;
        padding: 1.2mm 1.5mm;
        vertical-align: top;
        text-align: left;
    }

    th {
        background: #eee;
        font-size: 8.5pt;
        text-align: center;
    }

    /*
     * Baris tidak boleh terbelah antar halaman, dan kepala tabel DIULANG di
     * setiap halaman: buku register setebal puluhan halaman yang kehilangan
     * nama kolomnya di halaman dua tidak dapat dibaca pemeriksa.
     */
    tr { page-break-inside: avoid; }
    thead { display: table-header-group; }

    td.no, td.tengah { text-align: center; }
    td.mono { font-family: "Courier New", monospace; font-size: 8.5pt; white-space: nowrap; }

    .kosong { padding: 8mm; text-align: center; font-style: italic; }

    footer {
        margin-top: 8mm;
        page-break-inside: avoid;
        display: flex;
        justify-content: space-between;
        font-size: 9pt;
    }

    .ttd { width: 70mm; text-align: center; }
    .ttd .ruang { height: 20mm; }
    .ttd .nama { border-top: 0.3mm solid #000; padding-top: 1mm; }

    .catatan { font-size: 8pt; max-width: 110mm; }
</style>
</head>
<body>
    <header>
        <h1>{{ $judul }}</h1>
        <h2>{{ $subjudul }}</h2>
        <p class="desa">
            Pemerintah Desa {{ $village->name }}, Kecamatan {{ $village->district }},
            Kabupaten {{ $village->regency }}@if ($village->province), {{ $village->province }}@endif
        </p>
        <p class="lingkup">Lingkup data: {{ $lingkup }} · Dicetak {{ $dicetakPada }}</p>
    </header>

    @if (count($baris) === 0)
        <p class="kosong">Tidak ada data pada periode ini.</p>
    @else
        @yield('tabel')
    @endif

    <footer>
        <p class="catatan">
            {{ $catatan }}
        </p>
        <div class="ttd">
            <p>{{ $village->regency }}, {{ $dicetakPada }}</p>
            <p>Kepala Desa {{ $village->name }}</p>
            <div class="ruang"></div>
            <p class="nama">&nbsp;</p>
        </div>
    </footer>
</body>
</html>
