/*
 * Pembantu bersama skrip k6 — docs/09-testing-qa.md §4.5.
 *
 * Dipisahkan dari skrip ujinya supaya dua hal tidak diduplikasi, karena
 * keduanya pernah salah ditulis dan akibatnya tidak terlihat sebagai galat:
 *   1. pengambilan token CSRF (salah -> seluruh penulisan menjadi 419, yang
 *      terhitung sebagai "galat aplikasi" padahal skripnya yang keliru);
 *   2. pemisahan metrik BACA dan TULIS (tercampur -> p95 tunggal yang tidak
 *      dapat dibandingkan dengan kriteria, karena kriterianya memang dua).
 */

import http from 'k6/http';
import { Trend, Rate } from 'k6/metrics';
import { check, sleep } from 'k6';

export const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8111';

/* Dua Trend terpisah: kriteria §4.5 menetapkan p95 baca ≤ 500 ms DAN
 * p95 tulis ≤ 1 s. Satu metrik gabungan tidak dapat diuji terhadap keduanya. */
export const durasiBaca = new Trend('sid_baca_ms', true);
export const durasiTulis = new Trend('sid_tulis_ms', true);
export const galat = new Rate('sid_galat');

/**
 * Ambil token CSRF dari cookie XSRF-TOKEN milik VU ini.
 *
 * Laravel menerimanya lewat header `X-XSRF-TOKEN` dalam bentuk ter-URL-encode
 * seperti yang ditulis ke cookie; nilainya ter-enkripsi karena
 * SESSION_ENCRYPT=true, dan itu TIDAK perlu diurai — ia diteruskan apa adanya
 * setelah di-decode URL.
 */
export function tokenCsrf() {
  const jar = http.cookieJar();
  const cookies = jar.cookiesForURL(BASE + '/');
  const raw = cookies['XSRF-TOKEN'];

  return raw && raw.length > 0 ? decodeURIComponent(raw[0]) : '';
}

/** Permintaan BACA: dicatat ke metrik baca, dicek 200. */
export function baca(path, nama) {
  const res = http.get(BASE + path, { tags: { sid: nama || path } });

  durasiBaca.add(res.timings.duration);
  const ok = check(res, { [`baca ${nama || path} = 200`]: (r) => r.status === 200 });
  galat.add(!ok);

  return res;
}

/**
 * Permintaan TULIS: dicatat ke metrik tulis.
 *
 * 302 dianggap BERHASIL, bukan galat: seluruh penulisan di aplikasi ini
 * menjawab dengan redirect (pola Inertia + `back()`). Menuntut 200 di sini
 * akan melaporkan 100% galat pada aplikasi yang bekerja sempurna.
 *
 * 429 juga TIDAK dihitung galat bila `terimaBatasLaju` dinyalakan: pada
 * endpoint publik, 429 adalah jawaban yang BENAR dari pembatas laju
 * (FR-PGD-08) dan bukan kegagalan melayani.
 */
export function tulis(path, body, nama, terimaBatasLaju = false) {
  const res = http.post(BASE + path, body, {
    headers: {
      'X-XSRF-TOKEN': tokenCsrf(),
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'text/html, application/xhtml+xml',
    },
    redirects: 0,
    tags: { sid: nama || path },
  });

  durasiTulis.add(res.timings.duration);

  const ok = res.status === 302 || res.status === 200
    || (terimaBatasLaju && res.status === 429);
  check(res, { [`tulis ${nama || path} diterima`]: () => ok });
  galat.add(!ok);

  return res;
}

/**
 * Masuk sebagai akun demo. Dipanggil sekali per VU, bukan per iterasi.
 *
 * Masuk pada SETIAP iterasi akan membuat uji ini mengukur bcrypt: satu
 * verifikasi kata sandi memakan ± 60 ms CPU, dan pada 50 pengguna ia akan
 * mendominasi seluruh angka p95 — padahal petugas desa masuk sekali sehari.
 */
export function masuk(identifier, password) {
  /*
   * Jeda bertingkat per VU SEBELUM mencoba masuk.
   *
   * Tanpa ini, lima belas VU memanggil POST /masuk dalam rentang beberapa
   * milidetik yang sama. Setiap login menulis satu baris audit log, dan
   * AuditLogger merantainya dengan `SELECT ... FOR UPDATE` pada baris
   * terakhir — sehingga belasan login serentak saling mematikan dengan
   * deadlock MySQL 1213 dan VU-nya mati sebelum satu pun pengukuran dimulai.
   *
   * Jeda ini BUKAN penutup masalahnya: deadlock itu temuan nyata uji ini dan
   * dicatat di docs/22-uji-beban-sprint-7.md §7 butir 1. Yang ditiadakan di
   * sini hanyalah kerumunan buatan pada detik nol, yang tidak pernah terjadi
   * di desa — petugas masuk satu per satu sepanjang pagi, dan beban yang
   * hendak diukur adalah pekerjaan sesudah mereka masuk.
   */
  sleep((__VU % 25) * 0.2);

  const coba = () => {
    http.get(BASE + '/masuk');

    return http.post(
      BASE + '/masuk',
      { identifier: identifier, password: password },
      {
        headers: {
          'X-XSRF-TOKEN': tokenCsrf(),
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'text/html, application/xhtml+xml',
        },
        redirects: 0,
      },
    );
  };

  let res = coba();

  // Satu percobaan ulang — sama seperti yang dilakukan orang sungguhan ketika
  // halaman gagal sekali. Kegagalan kedua dilaporkan, tidak disembunyikan.
  if (res.status !== 302) {
    sleep(1 + Math.random());
    res = coba();
  }

  const ok = check(res, {
    'masuk berhasil (302 ke dashboard)': (r) => r.status === 302,
  });

  if (!ok) {
    throw new Error(
      `Gagal masuk sebagai ${identifier} (status ${res.status}). ` +
        'Pastikan basis data volume sudah di-seed dan pembatas laju login ' +
        'tidak aktif — lihat tests/Load/README.md §3.',
    );
  }
}

/** Pilih satu elemen secara acak. */
export function acak(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}
