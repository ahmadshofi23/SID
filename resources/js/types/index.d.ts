/** Tipe bersama yang dikirim Laravel ke setiap halaman Inertia. */

export interface VillageProfile {
    name: string
    district: string
    regency: string
    province: string
}

export interface AuthUser {
    id: string
    name: string
    roles: string[]
    role_labels: string[]
    permissions: string[]
    region_limited: boolean
    region_assigned: boolean
    two_factor_enabled: boolean
}

export interface FlashMessages {
    status: string | null
    warning: string | null
    error: string | null
}

export interface PageProps {
    village: VillageProfile
    auth: { user: AuthUser | null }
    flash: FlashMessages
    errors: Record<string, string>
    /** Jumlah notifikasi belum dibaca untuk lonceng (FR-SRT-14). */
    notifikasi_belum_dibaca: number
    [key: string]: unknown
}

export interface NotifikasiRow {
    id: string
    kategori: string
    judul: string
    isi: string
    tiket: string | null
    nomor_surat: string | null
    dibaca: boolean
    created_at: string | null
}

export interface Region {
    id: string
    name: string
    level: 'dusun' | 'rw' | 'rt'
    code: string
    head_name: string | null
    short_path?: string
    display_path?: string
    children?: Region[]
}

export type ResidentStatus =
    | 'aktif' | 'meninggal' | 'pindah_keluar' | 'hilang' | 'tidak_diketahui'

export interface Resident {
    id: string
    full_name: string
    nik_masked: string
    gender: 'L' | 'P'
    gender_label: string
    age: number
    birth_place: string | null
    birth_date: string
    birth_date_label: string
    status: ResidentStatus
    status_label: string
    region: string | null
    region_full: string | null
    family_relation: string | null
    [key: string]: unknown
}

export interface Family {
    id: string
    kk_number_masked: string
    address: string
    rt: string | null
    rw: string | null
    region: string | null
    region_full: string | null
    members_count?: number
    welfare_status: string | null
    house_ownership: string | null
}

export interface CursorPage<T> {
    data: T[]
    prev_url: string | null
    next_url: string | null
    count: number
}

export interface HistoryChange {
    field: string
    lama: string
    baru: string
}

export interface HistoryEntry {
    waktu: string
    aksi: string
    pelaku: string
    perubahan: HistoryChange[]
    alasan: string | null
}

/** Opsi pilihan untuk <SelectField>. */
export interface Option {
    value: string
    label: string
}

/* ============================ Persuratan ============================ */

export type LetterRequestStatus =
    | 'draft' | 'diajukan' | 'perlu_revisi' | 'diverifikasi'
    | 'diterbitkan' | 'ditolak' | 'dibatalkan'

/**
 * Nilai satu isian dinamis.
 *
 * Tipe ini SENGAJA konkret, bukan `unknown`: useForm milik Inertia menolak
 * `Record<string, unknown>` karena tidak dapat memastikan nilainya dapat
 * diserialisasi. Daftar di bawah adalah semua bentuk yang benar-benar
 * dikirim DynamicFormFields.
 */
export type LetterFormValue = string | number | boolean | string[] | null

export type LetterFormValues = Record<string, LetterFormValue>

/** Satu isian dinamis dari `letter_types.form_schema` (ADR-002). */
export interface LetterFormField {
    name: string
    label: string
    type:
        | 'text' | 'textarea' | 'number' | 'currency' | 'date' | 'time'
        | 'select' | 'multiselect' | 'boolean' | 'nik' | 'resident'
    required?: boolean
    options?: string[]
    allow_other?: boolean
    hint?: string
    min?: number
    max?: number
}

export interface LetterRequirement {
    label: string
    type: string
    required: boolean
}

/** Lampiran syarat pengajuan — Sprint 5, KEP-013 §1. */
export interface LetterAttachment {
    id: string
    /** Posisi syarat saat berkas diunggah; null = berkas tambahan. */
    requirement_index: number | null
    /** Salinan label syarat saat itu — dapat berbeda dari daftar terkini. */
    requirement_label: string | null
    original_name: string
    download_name: string
    mime: string
    extension: string
    is_image: boolean
    size_bytes: number
    size_label: string
    uploaded_by_name: string | null
    uploaded_at: string
    url: string
    /** false bila barisnya ada tetapi berkasnya tidak lagi di penyimpanan. */
    tersedia: boolean
}

/** Batasan unggahan, dikirim server agar layar tidak menjanjikan batas lain. */
export interface UploadConstraints {
    accept: string
    max_bytes: number
    max_label: string
    types_label: string
}

export interface IssuedLetter {
    id: string
    letter_number: string
    sequence_number: number
    issued_at: string
    signer_name: string
    signer_position: string
    valid_until: string | null
    is_expired: boolean
    verification_code: string
    status: 'sah' | 'dibatalkan'
    cancel_reason: string | null
    print_count: number
    pdf_status: 'menunggu' | 'diproses' | 'selesai' | 'gagal'
    pdf_ready: boolean
    pdf_failed_reason: string | null
}

export interface LetterRequestLog {
    id: string
    from_status: string | null
    to_status: string
    from_label: string | null
    to_label: string
    step: number | null
    actor_name: string | null
    note: string | null
    created_at: string
}

/**
 * Satu tahap pada riwayat waktu halaman pelacakan publik.
 *
 * Tanpa `actor_name`: nama petugas pemeriksa sengaja tidak dikirim ke
 * halaman yang dapat dibuka siapa pun yang punya tiket + tanggal lahir.
 */
export interface LetterTrackingStep {
    id: string
    to_status: LetterRequestStatus
    to_label: string
    step: number | null
    note: string | null
    /** Sudah diformat server pada kalender desa — jangan diformat ulang. */
    at: string
}

/**
 * Pelacakan status pengajuan untuk warga — FR-SRT-13, BR-11.
 *
 * Sengaja TIDAK memperluas LetterRequestRow: baris itu memuat NIK tersamar
 * dan isian formulir, dan halaman pelacakan tidak boleh ikut berubah setiap
 * kali layar petugas ditambahi data.
 */
export interface LetterRequestTracking {
    ticket_number: string
    status: LetterRequestStatus
    status_label: string
    letter_type: string
    /** Tersamar, mis. "Siti A***** R*****". */
    resident_name: string
    current_step: number
    step_total: number
    is_open: boolean
    is_overdue: boolean
    submitted_at: string | null
    due_at: string | null
    completed_at: string | null
    rejection_reason: string | null
    revision_note: string | null
    timeline: LetterTrackingStep[]
    letter_number: string | null
    issued_at: string | null
}

/**
 * Pengajuan milik akun warga sendiri — dibangun di atas LetterRequestTracking
 * (lihat LetterRequestPresenter::forCitizen). Nama pemohon di sini TIDAK
 * tersamar: pembukanya sudah masuk sebagai anggota keluarga itu.
 */
export interface LetterRequestForCitizen extends Omit<LetterRequestTracking, 'resident_name'> {
    id: string
    resident_name: string
    purpose: string | null
    /** Null bila surat belum terbit atau sudah dibatalkan (RULE-05). */
    letter_id: string | null
}

export interface LetterRequestRow {
    id: string
    ticket_number: string
    status: LetterRequestStatus
    status_label: string
    channel: 'online' | 'walk_in'
    current_step: number
    step_total: number
    letter_type: { id: string; code: string; name: string }
    resident: { id: string; full_name: string; nik_masked: string }
    purpose: string | null
    submitted_at: string | null
    due_at: string | null
    is_overdue: boolean
    /** Disusun server pada kalender desa — jangan dihitung ulang di peramban. */
    due_label: string
    created_at: string | null
}

/**
 * Tawaran pencatatan mutasi dari SKL/SKM — docs/13 §5.4 & §5.5, KEP-015.
 *
 * `target` adalah ALMARHUM pada tawaran kematian, bukan pemohon surat —
 * pemohon SKM adalah pelapor. Ia null bila warga itu di luar lingkup
 * pengguna, dan layar harus menanganinya tanpa menampilkan tombol.
 */
export interface LetterMutationOffer {
    id: string
    mutation_type: 'lahir' | 'mati'
    mutation_label: string
    status: 'menunggu' | 'diterima' | 'ditolak'
    is_pending: boolean
    /** Sudah diformat pada kalender desa — jangan diformat ulang. */
    decided_at: string | null
    decided_by_name: string | null
    decline_reason: string | null
    /** Penduduk hasil mutasi; pada SKL inilah bayi yang baru dibuat. */
    resident_id: string | null
    letter_cancelled: boolean
    target: {
        id: string
        full_name: string
        nik_masked: string
        status: string
        is_head_of_family: boolean
        family_id: string | null
    } | null
    applicant_has_family: boolean
}

export interface LetterRequestDetail extends LetterRequestRow {
    form_data: LetterFormValues
    rejection_reason: string | null
    revision_note: string | null
    completed_at: string | null
    requirements: LetterRequirement[]
    warning_note: string | null
    requires_field_check: boolean
    allowed_transitions: LetterRequestStatus[]
    /** Peran yang berwenang pada tahap pemeriksaan saat ini. */
    step_roles: string[]
    step_role_labels: string[]
    logs: LetterRequestLog[]
    letter: IssuedLetter | null
}

/* ======================== Pengaduan (Sprint 7) ========================= */

export type ComplaintStatus = 'baru' | 'diterima' | 'diproses' | 'selesai' | 'ditolak'

export interface ComplaintStep {
    id: string
    to_label: string | null
    note: string
    /** Sudah diformat server pada kalender desa — jangan diformat ulang. */
    at: string
}

/**
 * Pelacakan pengaduan dengan kode — FR-PGD-02.
 *
 * Tidak memuat identitas pelapor dan tidak memuat nama petugas: halaman ini
 * terbuka bagi siapa pun yang memegang kodenya, dan menyebut petugas yang
 * menangani pengaduan tentang perangkat desa justru dapat membahayakan orang.
 */
export interface ComplaintTracking {
    ticket_number: string
    status: ComplaintStatus
    status_label: string
    category: string
    title: string
    is_open: boolean
    is_overdue: boolean
    created_at: string | null
    due_at: string | null
    resolved_at: string | null
    resolution: string | null
    timeline: ComplaintStep[]
}

/** Pengaduan milik akun warga sendiri — portal warga. */
export interface ComplaintForCitizen extends ComplaintTracking {
    id: string
    location_text: string | null
}

/**
 * Tanda terima pengaduan — muncul SATU KALI, tepat setelah dikirim.
 *
 * `tracking_code` tidak dapat dibuka kembali: ia tidak pernah ikut ke payload
 * mana pun selain ini (lihat ComplaintPresenter).
 */
export interface ComplaintReceipt {
    ticket_number: string
    tracking_code: string
    is_anonymous: boolean
    title: string
    triage_due_at: string | null
}
