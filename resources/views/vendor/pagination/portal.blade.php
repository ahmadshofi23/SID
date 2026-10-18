{{--
  Penomoran halaman portal.

  Ditulis sendiri, bukan memakai tampilan bawaan Laravel: yang bawaan memakai
  kelas warna tetap (bg-white, text-gray-700) yang tidak mengikuti token mode
  gelap milik design system ini, sehingga tombolnya menjadi putih menyala di
  halaman gelap. Isinya tetap tautan <a> biasa — portal harus berfungsi tanpa
  JavaScript (KEP-016).
--}}
@if ($paginator->hasPages())
    <nav class="flex items-center justify-between gap-3" aria-label="Penomoran halaman">
        @if ($paginator->onFirstPage())
            <span class="touch-target inline-flex items-center rounded-lg border border-line px-4 text-sm text-content-tertiary">
                Sebelumnya
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
               class="touch-target inline-flex items-center rounded-lg border border-line-strong bg-surface px-4 text-sm font-medium transition-colors hover:bg-surface-hover">
                Sebelumnya
            </a>
        @endif

        <p class="tabular text-sm text-content-secondary">
            Halaman {{ $paginator->currentPage() }} dari {{ $paginator->lastPage() }}
        </p>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next"
               class="touch-target inline-flex items-center rounded-lg border border-line-strong bg-surface px-4 text-sm font-medium transition-colors hover:bg-surface-hover">
                Berikutnya
            </a>
        @else
            <span class="touch-target inline-flex items-center rounded-lg border border-line px-4 text-sm text-content-tertiary">
                Berikutnya
            </span>
        @endif
    </nav>
@endif
