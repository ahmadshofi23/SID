<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Portal\PostService;
use App\Support\VillageTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengelolaan konten portal oleh petugas — FR-PRT-02, FR-PRT-03, UC-11.
 *
 * TIGA IZIN, BUKAN SATU (matriks RBAC docs/03 §4.9, baris "Konten portal"):
 *   - `portal.lihat`     melihat daftar — Kepala Desa & Pengawas berhenti di sini
 *   - `portal.kelola`    membuat & menyunting (Operator, Kasi, Admin Desa)
 *   - `portal.terbitkan` mengubah status menjadi `terbit`
 *
 * Akibatnya nyata dan memang dikehendaki: Operator dapat menulis berita
 * selengkapnya tetapi tidak dapat menerbitkannya sendiri; yang menerbitkan
 * adalah Kepala Desa atau Admin Desa. Situs resmi desa adalah pernyataan
 * pemerintah desa, bukan papan pengumuman yang siapa pun boleh menempel.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class PostController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('portal.lihat');

        $type = $request->string('jenis')->toString();
        $status = $request->string('status')->toString();

        $posts = Post::query()
            ->with('author:id,name')
            ->when(in_array($type, Post::TYPES, true), fn ($q) => $q->where('type', $type))
            ->when(in_array($status, Post::STATUSES, true), fn ($q) => $q->where('status', $status))
            /*
             * Draf dan terjadwal muncul DI ATAS, bukan tenggelam di bawah
             * tulisan lama. Layar ini adalah daftar pekerjaan petugas; yang
             * sudah terbit berbulan-bulan lalu bukan lagi pekerjaan.
             */
            ->orderByRaw("FIELD(status, 'draft', 'terbit', 'arsip')")
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Portal/Konten/Index', [
            // Bentuk muatan yang sama dengan layar berdaftar lain (CursorPage
            // di resources/js/types), supaya DataPagination dapat dipakai ulang.
            'posts' => [
                'data' => $posts->getCollection()->map(fn (Post $p): array => $this->rowPayload($p))->all(),
                'prev_url' => $posts->previousPageUrl(),
                'next_url' => $posts->nextPageUrl(),
                'count' => $posts->count(),
            ],
            'filters' => ['jenis' => $type, 'status' => $status],
            'typeLabels' => Post::TYPE_LABELS,
            'counts' => [
                'draft' => Post::query()->where('status', Post::STATUS_DRAFT)->count(),
                'terjadwal' => Post::query()
                    ->where('status', Post::STATUS_TERBIT)
                    ->where('published_at', '>', now())
                    ->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('portal.kelola');

        $type = $request->string('jenis')->toString();

        return Inertia::render('Portal/Konten/Form', [
            'post' => null,
            'defaultType' => in_array($type, Post::TYPES, true) ? $type : Post::TYPE_BERITA,
            'typeLabels' => Post::TYPE_LABELS,
        ]);
    }

    public function store(Request $request, PostService $posts): RedirectResponse
    {
        $this->authorize('portal.kelola');

        $data = $this->validatePayload($request, null, $posts);

        $post = $posts->create($data, $request->user()?->id, $request->file('cover'));

        return to_route('portal-konten.edit', $post->id)
            ->with('status', Post::TYPE_LABELS[$post->type].' "'.$post->title.'" tersimpan.');
    }

    public function edit(string $id): Response
    {
        $this->authorize('portal.kelola');

        $post = Post::query()->findOrFail($id);

        return Inertia::render('Portal/Konten/Form', [
            'post' => $this->formPayload($post),
            'defaultType' => $post->type,
            'typeLabels' => Post::TYPE_LABELS,
        ]);
    }

    public function update(string $id, Request $request, PostService $posts): RedirectResponse
    {
        $this->authorize('portal.kelola');

        $post = Post::query()->findOrFail($id);
        $data = $this->validatePayload($request, $post, $posts);

        $posts->update(
            $post,
            $data,
            $request->file('cover'),
            $request->boolean('hapus_sampul'),
        );

        return back()->with('status', 'Perubahan tersimpan.');
    }

    public function destroy(string $id, Request $request, PostService $posts): RedirectResponse
    {
        $this->authorize('portal.kelola');

        $post = Post::query()->findOrFail($id);

        /*
         * Alasan WAJIB, dan tersimpan di audit log. Menghapus pengumuman
         * resmi desa adalah tindakan yang kelak ditanyakan orang — "mengapa
         * pengumuman itu hilang?" harus dapat dijawab dari catatan, bukan
         * dari ingatan petugas yang mungkin sudah berganti.
         */
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:500']],
            attributes: ['reason' => 'alasan'],
        );

        $posts->delete($post, $data['reason']);

        return to_route('portal-konten.index')
            ->with('status', '"'.$post->title.'" dihapus dari portal.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?Post $post, PostService $posts): array
    {
        $isAgenda = $request->string('type')->toString() === Post::TYPE_AGENDA;

        $data = $request->validate([
            'type' => ['required', Rule::in(Post::TYPES)],
            'title' => ['required', 'string', 'max:250'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string'],
            'category_code' => ['nullable', 'string', 'max:40'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'status' => ['required', Rule::in(Post::STATUSES)],
            'published_at' => ['nullable', 'date'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'event_start_at' => [Rule::requiredIf($isAgenda), 'nullable', 'date'],
            'event_end_at' => ['nullable', 'date', 'after_or_equal:event_start_at'],
            'event_location' => [Rule::requiredIf($isAgenda), 'nullable', 'string', 'max:255'],
            'cover' => ['nullable', 'file'],
        ], attributes: [
            'event_start_at' => 'waktu mulai kegiatan',
            'event_end_at' => 'waktu selesai kegiatan',
            'event_location' => 'lokasi kegiatan',
            'published_at' => 'tanggal terbit',
        ]);

        /*
         * Izin menerbitkan diperiksa DI SINI, bukan di middleware rute.
         *
         * Rutenya sama untuk menyimpan draf dan untuk menerbitkan; yang
         * membedakan hanya nilai satu kolom. Middleware `izin:` pada rute
         * akan menutup penyuntingan draf bagi Operator sekaligus — yang
         * justru pekerjaan utamanya.
         */
        $akanTerbit = $data['status'] === Post::STATUS_TERBIT;
        $sudahTerbit = $post?->status === Post::STATUS_TERBIT;

        if ($akanTerbit && ! $sudahTerbit && $request->user()?->can('portal.terbitkan') !== true) {
            throw ValidationException::withMessages([
                'status' => 'Anda dapat menyimpan sebagai draf, tetapi penerbitan ke '
                    .'portal dilakukan Kepala Desa atau Admin Desa.',
            ]);
        }

        /*
         * Menarik kembali tulisan yang SUDAH terbit juga menuntut izin
         * terbitkan. Tanpa pagar ini, pemisahan kewenangan hanya berlaku satu
         * arah: siapa pun yang dapat menyunting dapat menghilangkan pengumuman
         * resmi dari situs desa.
         */
        if ($sudahTerbit && ! $akanTerbit && $request->user()?->can('portal.terbitkan') !== true) {
            throw ValidationException::withMessages([
                'status' => 'Tulisan ini sudah terbit. Menariknya kembali dilakukan '
                    .'Kepala Desa atau Admin Desa.',
            ]);
        }

        if ($request->hasFile('cover')) {
            $alasan = $posts->coverRejectionReason($request->file('cover'));

            if ($alasan !== null) {
                throw ValidationException::withMessages(['cover' => $alasan]);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function rowPayload(Post $post): array
    {
        return [
            'id' => $post->id,
            'type' => $post->type,
            'title' => $post->title,
            'slug' => $post->slug,
            'status' => $post->status,
            'is_scheduled' => $post->isScheduled(),
            'published_at' => $post->published_at?->toIso8601String(),
            'event_start_at' => $post->event_start_at?->toIso8601String(),
            'author_name' => $post->author?->name,
            'view_count' => $post->view_count,
            'updated_at' => $post->updated_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formPayload(Post $post): array
    {
        return [
            ...$this->rowPayload($post),
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'category_code' => $post->category_code,
            'tags' => $post->tags ?? [],
            'event_end_at' => $post->event_end_at?->toIso8601String(),
            'event_location' => $post->event_location,
            'meta_title' => $post->meta_title,
            'meta_description' => $post->meta_description,
            'cover_url' => $post->cover_path === null
                ? null
                : Storage::disk('public')->url($post->cover_path),

            /*
             * Waktu dikirim ke formulir dalam KALENDER DESA dan format yang
             * langsung diterima <input type="datetime-local">. Mengirim ISO
             * UTC akan membuat petugas membuka agenda pukul 09.00 dan melihat
             * 02.00 di layarnya (KEP-010).
             */
            'published_at_lokal' => $this->localInput($post->published_at),
            'event_start_at_lokal' => $this->localInput($post->event_start_at),
            'event_end_at_lokal' => $this->localInput($post->event_end_at),
        ];
    }

    private function localInput(?Carbon $moment): ?string
    {
        return $moment === null ? null : VillageTime::local($moment)->format('Y-m-d\TH:i');
    }
}
