/**
 * Money crosses the API in pence to avoid float drift, so every display
 * conversion happens here.
 */
export function formatMoney(pence: number | null | undefined, options: { compact?: boolean } = {}): string {
  if (pence === null || pence === undefined) {
    return '—'
  }

  return new Intl.NumberFormat('en-GB', {
    style: 'currency',
    currency: 'GBP',
    notation: options.compact ? 'compact' : 'standard',
    maximumFractionDigits: options.compact ? 1 : 0
  }).format(pence / 100)
}

export function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return '—'
  }

  return new Intl.NumberFormat('en-GB').format(value)
}

export function formatPercent(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return '—'
  }

  return `${value.toFixed(1)}%`
}

export function formatDate(value: string | null | undefined): string {
  if (!value) {
    return '—'
  }

  return new Intl.DateTimeFormat('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric'
  }).format(new Date(value))
}

/** Score bands drive the badge colour across the dashboard. */
export function scoreColor(score: number): 'success' | 'warning' | 'neutral' {
  if (score >= 70) {
    return 'success'
  }

  if (score >= 50) {
    return 'warning'
  }

  return 'neutral'
}

export function stageColor(stage: string): 'neutral' | 'info' | 'primary' | 'warning' | 'success' {
  switch (stage) {
    case 'title_bought':
      return 'info'
    case 'confirmed':
      return 'primary'
    case 'outreach':
      return 'warning'
    case 'offer':
      return 'success'
    default:
      return 'neutral'
  }
}
