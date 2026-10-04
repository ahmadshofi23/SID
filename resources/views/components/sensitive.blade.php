@props(['masked', 'url' => null, 'label' => 'NIK'])

{{--
  Data pribadi ditampilkan TER-MASKING secara bawaan (RULE-07).
  Tombol buka hanya muncul bagi peran berizin `data_pribadi.lihat_penuh`,
  dan setiap penekanannya tercatat di audit log.
--}}
<span class="sid-sensitive"
      @if ($url) data-reveal-url="{{ $url }}" data-reveal-label="{{ $label }}" @endif>
    <span class="sid-sensitive__value">{{ $masked }}</span>

    @if ($url && auth()->user()?->can('data_pribadi.lihat_penuh'))
        <button type="button" class="sid-reveal" data-reveal
                aria-label="Tampilkan {{ $label }} lengkap">
            Lihat
        </button>
    @endif
</span>
