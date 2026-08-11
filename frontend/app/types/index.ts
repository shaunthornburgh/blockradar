export type PipelineStage
  = | 'new'
    | 'title_bought'
    | 'confirmed'
    | 'outreach'
    | 'offer'

export interface User {
  id: number
  name: string
  email: string
}

export interface Company {
  id: number
  company_number: string
  name: string
  status: string | null
  type: string | null
  jurisdiction?: string | null
  incorporated_on: string | null
  dissolved_on?: string | null
  sic_codes: string[]
  registered_office_address?: Record<string, string> | null
  registered_office_postcode: string | null
  officer_count: number | null

  /** Companies House distress signals, as read by the scorer. */
  accounts_last_made_up_to?: string | null
  accounts_next_due?: string | null
  accounts_overdue?: boolean | null
  confirmation_statement_overdue?: boolean | null
  confirmation_statement_next_due?: string | null
  has_charges: boolean
  charges_count: number | null
  has_insolvency_history?: boolean | null
  is_distressed?: boolean
  is_dissolved?: boolean

  is_enriched?: boolean
  enriched_at: string | null
  enrichment_status?: 'pending' | 'enriched' | 'not_found' | 'failed' | null
  titles_count?: number
}

export type EpcMatchConfidence = 'high' | 'medium' | 'low'

export interface EpcAggregate {
  enriched_at: string | null
  match_confidence: EpcMatchConfidence | null
  match_method: string | null
  match_method_label: string | null
  is_usable: boolean
  certificate_count: number
  current_rating: string | null
  average_energy_efficiency: number | null
  /** Square metres, summed across the building. */
  total_floor_area: number | null
  habitable_rooms: number | null
  property_type: string | null
  built_form: string | null
  construction_age_band: string | null
  main_heating: string | null
  uprn: string | null
  latest_lodgement_date: string | null
}

export interface EpcCertificate {
  id: number
  certificate_number: string
  address: string
  postcode: string | null
  uprn: string | null
  current_energy_rating: string | null
  current_energy_efficiency: number | null
  potential_energy_rating: string | null
  property_type: string | null
  built_form: string | null
  total_floor_area: number | null
  number_habitable_rooms: number | null
  floor_level: string | null
  lodgement_date: string | null
  match?: {
    method: string
    confidence: EpcMatchConfidence
    similarity: number | null
    is_primary: boolean
  }
}

export interface Title {
  id: number
  title_number: string
  tenure: 'freehold' | 'leasehold' | 'unknown'
  tenure_label: string
  property_address: string
  postcode: string | null
  postcode_district: string | null
  district: string | null
  county: string | null
  region: string | null
  multiple_address_indicator: boolean
  additional_proprietor_indicator: boolean
  proprietor_name: string | null
  proprietorship_category: string | null
  /** Pence. */
  price_paid: number | null
  date_proprietor_added: string | null
  estimated_unit_count: number | null
  unit_count_source?: 'address' | 'epc' | null
  uprn?: string | null
  first_seen_at?: string | null
  last_seen_at?: string | null
  epc?: EpcAggregate
  company?: Company
  epc_certificates?: EpcCertificate[]
}

export interface CandidateNote {
  id: number
  type: 'note' | 'call' | 'letter' | 'email'
  body: string
  meta: Record<string, unknown>
  created_at: string
  user?: User
}

/** One component of a score, as stored in `score_breakdown`. */
export interface ScoreComponent {
  value: number | null
  weight: number
  points: number
  available: boolean
  note: string
  signals: string[]
}

export interface ScoreBreakdown {
  score: number
  weight_available: number
  weight_total: number
  components: Record<string, ScoreComponent>
}

export interface Candidate {
  id: number
  stage: PipelineStage
  stage_label: string
  stage_order: number
  score: number
  score_breakdown: ScoreBreakdown | Record<string, never>
  scored_at: string | null
  estimated_units: number | null
  /** Pence. */
  estimated_gdv: number | null
  /** Pence, may be negative. */
  estimated_uplift: number | null
  gross_yield: number | null
  next_action_at: string | null
  is_archived: boolean
  title_bought_at: string | null
  confirmed_at: string | null
  outreach_at: string | null
  offered_at: string | null
  created_at: string
  updated_at: string
  title?: Title
  assigned_to?: User
  notes?: CandidateNote[]
  notes_count?: number
}

export interface CcodImport {
  id: number
  filename: string
  period: string
  status: 'pending' | 'processing' | 'completed' | 'failed'
  rows_total: number
  rows_imported: number
  rows_skipped: number
  rows_failed: number
  titles_created: number
  titles_updated: number
  started_at: string | null
  finished_at: string | null
}

export interface DashboardSummary {
  totals: {
    titles: number
    split_candidates: number
    freehold_titles: number
    companies: number
    companies_awaiting_enrichment: number
    candidates: number
    high_score_candidates: number
  }
  pipeline: Array<{ stage: PipelineStage, label: string, count: number }>
  top_candidates: Candidate[]
  latest_import: CcodImport | null
}

/** Laravel wraps single resources and `response()->json` payloads in `data`. */
export interface ApiResource<T> {
  data: T
}

/** Shape of a paginated `AnonymousResourceCollection`. */
export interface ApiCollection<T> {
  data: T[]
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
  meta: {
    current_page: number
    from: number | null
    last_page: number
    per_page: number
    to: number | null
    total: number
  }
}
