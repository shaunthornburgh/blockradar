import type { LocationQuery } from 'vue-router'
import type { CandidateDefaults, PipelineStage } from '~/types'

/** Indexing a LocationQuery can yield undefined; the value type alone cannot. */
type QueryValue = LocationQuery[string] | undefined

/**
 * Filters whose "off" position is a value rather than an absence: the user
 * can ask for "has an EPC", "has no EPC", or not care.
 */
export type TriState = 'any' | 'true' | 'false'

export interface CandidateFilters {
  search: string
  stage: PipelineStage | 'all'
  minScore: number | null
  maxScore: number | null
  region: string
  postcodeArea: string
  minUnits: number | null
  includeUnknownUnits: boolean
  hasEpc: TriState
  minEpcCertificates: number | null
  minMufb: string
  companyDistressed: boolean
  hasCharges: TriState
  archived: boolean
  sort: string
  direction: 'asc' | 'desc'
  page: number
}

const EMPTY: CandidateFilters = {
  search: '',
  stage: 'all',
  minScore: null,
  maxScore: null,
  region: 'all',
  postcodeArea: 'all',
  minUnits: null,
  includeUnknownUnits: false,
  hasEpc: 'any',
  minEpcCertificates: null,
  minMufb: 'any',
  companyDistressed: false,
  hasCharges: 'any',
  archived: false,
  sort: 'score',
  direction: 'desc',
  page: 1
}

/**
 * Marks a URL as deliberately unfiltered.
 *
 * Without it, clearing every filter would leave an empty query string, which
 * is exactly the state that triggers the default preset — so "Clear" would
 * bounce straight back to the preset. It is a frontend concern and is never
 * sent to the API.
 */
const CLEARED = 'view'

export function emptyCandidateFilters(): CandidateFilters {
  return { ...EMPTY }
}

/** The preset a bare /candidates lands on, as filter state. */
export function presetFilters(defaults: CandidateDefaults | undefined): CandidateFilters {
  return {
    ...EMPTY,
    hasEpc: defaults?.has_epc === undefined ? 'any' : defaults.has_epc ? 'true' : 'false',
    minUnits: defaults?.min_units ?? null,
    includeUnknownUnits: defaults?.include_unknown_units ?? false,
    minScore: defaults?.min_score ?? null
  }
}

function first(value: QueryValue): string | undefined {
  const raw = Array.isArray(value) ? value[0] : value

  return raw === null || raw === undefined ? undefined : String(raw)
}

function toInt(value: QueryValue): number | null {
  const raw = first(value)
  const parsed = raw === undefined ? Number.NaN : Number.parseInt(raw, 10)

  return Number.isFinite(parsed) ? parsed : null
}

function toBool(value: QueryValue): boolean {
  const raw = first(value)

  return raw === 'true' || raw === '1'
}

function toTri(value: QueryValue): TriState {
  const raw = first(value)

  if (raw === undefined) {
    return 'any'
  }

  return raw === 'true' || raw === '1' ? 'true' : 'false'
}

/** Reads filter state out of a URL query. Unknown keys are ignored. */
export function filtersFromQuery(query: LocationQuery): CandidateFilters {
  return {
    search: first(query.search) ?? '',
    stage: (first(query.stage) as PipelineStage | undefined) ?? 'all',
    minScore: toInt(query.min_score),
    maxScore: toInt(query.max_score),
    region: first(query.region) ?? 'all',
    postcodeArea: first(query.postcode_area) ?? 'all',
    minUnits: toInt(query.min_units),
    includeUnknownUnits: toBool(query.include_unknown_units),
    hasEpc: toTri(query.has_epc),
    minEpcCertificates: toInt(query.min_epc_certificates),
    minMufb: first(query.min_mufb) ?? 'any',
    companyDistressed: toBool(query.company_distressed),
    hasCharges: toTri(query.has_charges),
    archived: toBool(query.archived),
    sort: first(query.sort) ?? EMPTY.sort,
    direction: first(query.direction) === 'asc' ? 'asc' : 'desc',
    page: toInt(query.page) ?? 1
  }
}

/**
 * The URL query for a set of filters. Only what differs from the empty state
 * is written, so a shared link carries exactly what the user chose and
 * nothing else.
 */
export function queryFromFilters(filters: CandidateFilters): Record<string, string> {
  const query: Record<string, string> = {}

  if (filters.search) query.search = filters.search
  if (filters.stage !== 'all') query.stage = filters.stage
  if (filters.minScore !== null) query.min_score = String(filters.minScore)
  if (filters.maxScore !== null) query.max_score = String(filters.maxScore)
  if (filters.region !== 'all') query.region = filters.region
  if (filters.postcodeArea !== 'all') query.postcode_area = filters.postcodeArea
  if (filters.minUnits !== null) query.min_units = String(filters.minUnits)
  if (filters.includeUnknownUnits) query.include_unknown_units = 'true'
  if (filters.hasEpc !== 'any') query.has_epc = filters.hasEpc
  if (filters.minEpcCertificates !== null) query.min_epc_certificates = String(filters.minEpcCertificates)
  if (filters.minMufb !== 'any') query.min_mufb = filters.minMufb
  if (filters.companyDistressed) query.company_distressed = 'true'
  if (filters.hasCharges !== 'any') query.has_charges = filters.hasCharges
  if (filters.archived) query.archived = 'true'
  if (filters.sort !== EMPTY.sort) query.sort = filters.sort
  if (filters.direction !== EMPTY.direction) query.direction = filters.direction
  if (filters.page > 1) query.page = String(filters.page)

  // Never hand back an empty query: that is the "apply the preset" state.
  if (Object.keys(query).length === 0) {
    query[CLEARED] = 'all'
  }

  return query
}

/** What the API is actually asked for. Mirrors queryFromFilters, minus the sentinel. */
export function apiQueryFromFilters(filters: CandidateFilters, perPage: number): Record<string, string | number> {
  const { [CLEARED]: _sentinel, ...query } = queryFromFilters(filters)

  return { ...query, page: filters.page, per_page: perPage }
}

/**
 * Filters the user has actually set, as chips. Sort, direction, page and the
 * archive toggle are navigation rather than narrowing, so they are left out.
 */
export function activeFilterChips(filters: CandidateFilters): Array<{ key: keyof CandidateFilters, label: string }> {
  const chips: Array<{ key: keyof CandidateFilters, label: string }> = []

  if (filters.search) chips.push({ key: 'search', label: `“${filters.search}”` })
  if (filters.stage !== 'all') chips.push({ key: 'stage', label: `Stage: ${filters.stage.replace('_', ' ')}` })
  if (filters.minScore !== null) chips.push({ key: 'minScore', label: `Score ≥ ${filters.minScore}` })
  if (filters.maxScore !== null) chips.push({ key: 'maxScore', label: `Score ≤ ${filters.maxScore}` })
  if (filters.region !== 'all') chips.push({ key: 'region', label: filters.region })
  if (filters.postcodeArea !== 'all') chips.push({ key: 'postcodeArea', label: `Postcode ${filters.postcodeArea}` })
  if (filters.minUnits !== null) chips.push({ key: 'minUnits', label: `${filters.minUnits}+ units` })
  if (filters.includeUnknownUnits) chips.push({ key: 'includeUnknownUnits', label: 'Incl. unknown units' })
  if (filters.hasEpc !== 'any') chips.push({ key: 'hasEpc', label: filters.hasEpc === 'true' ? 'Has EPC' : 'No EPC' })
  if (filters.minEpcCertificates !== null) chips.push({ key: 'minEpcCertificates', label: `${filters.minEpcCertificates}+ EPCs` })
  if (filters.minMufb !== 'any') chips.push({ key: 'minMufb', label: `MUFB ≥ ${filters.minMufb}` })
  if (filters.companyDistressed) chips.push({ key: 'companyDistressed', label: 'Distressed owner' })
  if (filters.hasCharges !== 'any') chips.push({ key: 'hasCharges', label: filters.hasCharges === 'true' ? 'Has charges' : 'No charges' })

  return chips
}

/** Resets one chip back to its empty value. */
export function clearedFilter(key: keyof CandidateFilters): Partial<CandidateFilters> {
  return { [key]: EMPTY[key] } as Partial<CandidateFilters>
}
