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
    [key: string]: unknown
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
