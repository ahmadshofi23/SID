@props([
    'action',
    'method' => 'DELETE',
    'title' => 'Konfirmasi',
    'body' => 'Tindakan ini tidak dapat dibatalkan.',
    'confirmLabel' => 'Hapus',
    'triggerLabel' => 'Hapus',
    'triggerClass' => 'sid-btn sid-btn--danger',
    'reasonRequired' => true,
])

@php $id = 'konfirmasi-'.\Illuminate\Support\Str::random(8); @endphp

{{-- Aksi destruktif selalu memerlukan konfirmasi (NFR-USA-05).
     Judul berupa pertanyaan, tombol memakai kata kerja — bukan "OK". --}}
<button type="button" class="{{ $triggerClass }}"
        onclick="document.getElementById('{{ $id }}').showModal()">{{ $triggerLabel }}</button>

<dialog id="{{ $id }}" class="sid-dialog">
    <form method="POST" action="{{ $action }}">
        @csrf
        @method($method)

        <h2 style="margin-bottom:8px">{{ $title }}</h2>
        <p class="sid-text-sm">{{ $body }}</p>

        @if ($reasonRequired)
            <div class="sid-field">
                <label class="sid-label" for="{{ $id }}-reason">
                    Alasan <span class="sid-label__required" aria-hidden="true">*</span>
                </label>
                <textarea id="{{ $id }}-reason" name="reason" class="sid-textarea"
                          required minlength="5"
                          placeholder="Contoh: data ganda, sudah pindah dan tercatat di desa tujuan"></textarea>
                <p class="sid-hint">Alasan tersimpan permanen di log aktivitas.</p>
            </div>
        @endif

        <div class="sid-dialog__actions">
            <button type="button" class="sid-btn sid-btn--secondary"
                    onclick="document.getElementById('{{ $id }}').close()">Batal</button>
            <button type="submit" class="sid-btn sid-btn--danger">{{ $confirmLabel }}</button>
        </div>
    </form>
</dialog>
