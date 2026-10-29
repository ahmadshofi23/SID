<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notifikasi dalam aplikasi — FR-SRT-14, BR-11.
 *
 * SATU layar untuk petugas maupun warga. Isinya memang berbeda (yang satu
 * antrean pekerjaan, yang satu kabar suratnya sendiri), tetapi perilakunya
 * identik — dan dua layar yang sama akan berbeda pelan-pelan, biasanya pada
 * hal seperti "sudah dibaca" yang justru paling mudah salah.
 *
 * Tanpa konstruktor (KEP-009).
 */
final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);

        $page = $user->notifications()->paginate(20);

        return Inertia::render('Notifikasi/Index', [
            'notifikasi' => [
                'data' => $page->getCollection()
                    ->map(fn (DatabaseNotification $n): array => $this->payload($n))
                    ->all(),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
                'count' => $page->count(),
            ],
            'belum_dibaca' => $user->unreadNotifications()->count(),
            'untuk_warga' => $user->hasRole(Role::WARGA),
        ]);
    }

    /**
     * Tandai terbaca lalu ANTARKAN ke halaman yang dimaksud.
     *
     * Digabung menjadi satu tindakan dengan sengaja: notifikasi yang harus
     * ditandai terbaca secara terpisah dari membukanya akan selalu
     * meninggalkan daftar yang penuh penanda merah walau semuanya sudah
     * dibaca — dan penanda yang selalu menyala berhenti berarti apa pun.
     */
    public function read(string $id, Request $request): RedirectResponse
    {
        $user = $this->user($request);

        /*
         * Dicari LEWAT RELASI milik pengguna, bukan DatabaseNotification::find():
         * notifikasi orang lain karena itu tidak ditemukan dan menjadi 404,
         * bukan 403 (docs/06 §4) — dan id-nya tidak dapat dipakai menebak
         * notifikasi siapa pun selain dirinya.
         */
        $notification = $user->notifications()->whereKey($id)->first();

        abort_if($notification === null, 404);

        $notification->markAsRead();

        $tautan = $notification->data['tautan'] ?? null;

        return is_string($tautan) && str_starts_with($tautan, '/')
            ? redirect($tautan)
            : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->user($request)->unreadNotifications->markAsRead();

        return back()->with('status', 'Semua notifikasi ditandai sudah dibaca.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_if($user === null, 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'kategori' => $data['kategori'] ?? 'umum',
            'judul' => $data['judul'] ?? 'Pemberitahuan',
            'isi' => $data['isi'] ?? '',
            'tiket' => $data['tiket'] ?? null,
            'nomor_surat' => $data['nomor_surat'] ?? null,
            'dibaca' => $notification->read_at !== null,
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }
}
