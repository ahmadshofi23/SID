/**
 * Sistem Informasi Desa — skrip pendukung.
 *
 * Sengaja kecil dan tanpa kerangka kerja (KEP-005). Seluruh halaman tetap
 * berfungsi tanpa berkas ini; yang hilang hanya kenyamanan.
 */
(function () {
    'use strict';

    /**
     * Pembukaan data pribadi (NIK / nomor KK) — RULE-07.
     *
     * Nilai penuh TIDAK pernah ada di dalam HTML halaman. Ia diambil lewat
     * permintaan tersendiri yang memerlukan izin `data_pribadi.lihat_penuh`
     * dan dicatat di audit log. Dengan begitu, membuka "view source" atau
     * menyimpan halaman tidak membocorkan apa pun.
     */
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-reveal]');
        if (!button) return;

        const wrapper = button.closest('.sid-sensitive');
        const valueEl = wrapper ? wrapper.querySelector('.sid-sensitive__value') : null;
        const url = wrapper ? wrapper.dataset.revealUrl : null;
        if (!wrapper || !valueEl || !url) return;

        // Klik kedua: sembunyikan kembali tanpa permintaan baru.
        if (wrapper.dataset.revealed === '1') {
            valueEl.textContent = wrapper.dataset.maskedValue || valueEl.textContent;
            wrapper.dataset.revealed = '0';
            button.textContent = 'Lihat';
            return;
        }

        wrapper.dataset.maskedValue = valueEl.textContent;
        button.disabled = true;
        button.textContent = '…';

        try {
            const meta = document.querySelector('meta[name="csrf-token"]');
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': meta ? meta.content : '',
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) throw new Error('gagal');

            const data = await response.json();
            valueEl.textContent = data.nik || data.nomor_kk || valueEl.textContent;
            wrapper.dataset.revealed = '1';
            button.textContent = 'Sembunyikan';
            wrapper.title = data.catatan || '';
        } catch (error) {
            button.textContent = 'Gagal';
            setTimeout(function () { button.textContent = 'Lihat'; }, 2000);
        } finally {
            button.disabled = false;
        }
    });

    /**
     * Cegah pengiriman formulir ganda — penyebab umum data ganda saat
     * jaringan lambat (docs/07 §4).
     */
    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (form.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = '1';

        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.setAttribute('aria-busy', 'true');
            setTimeout(function () { submitButton.disabled = true; }, 0);
        }
    });
})();
