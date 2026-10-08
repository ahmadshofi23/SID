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
