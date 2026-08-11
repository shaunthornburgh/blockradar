import type { Company, Title } from '~/types'

export interface ResearchLink {
  label: string
  description: string
  href: string
  icon: string
  /** Shown as a caveat under the link when the deep link cannot be exact. */
  caveat?: string
}

export interface ResearchLinkGroup {
  title: string
  icon: string
  links: ResearchLink[]
}

/**
 * External research the user would otherwise open by hand for every candidate.
 *
 * Every URL pattern here was checked against the live service. Where a service
 * cannot be deep-linked — Land Registry requires a signed-in session, Street
 * View needs coordinates we do not hold — the link goes as far as it can and
 * says so, rather than pretending.
 */
export function useResearchLinks(
  title: Ref<Title | undefined>,
  company: Ref<Company | undefined>
) {
  /** The fullest address string available, for map and web searches. */
  const searchAddress = computed(() => {
    const t = title.value
    if (!t) return ''

    return [t.property_address, t.postcode].filter(Boolean).join(', ')
  })

  /** Rightmove and Zoopla both slug the full postcode, lowercased. */
  const postcodeSlug = computed(() =>
    (title.value?.postcode ?? '').toLowerCase().replace(/\s+/g, '-')
  )

  const groups = computed<ResearchLinkGroup[]>(() => {
    const t = title.value
    if (!t) return []

    const address = encodeURIComponent(searchAddress.value)
    const postcode = t.postcode ?? ''
    const encodedPostcode = encodeURIComponent(postcode)

    const location: ResearchLink[] = [
      {
        label: 'Google Maps',
        description: 'Locate the building and check its footprint',
        href: `https://www.google.com/maps/search/?api=1&query=${address}`,
        icon: 'i-lucide-map-pin'
      },
      {
        label: 'Street View',
        description: 'Eyeball the frontage, door count and condition',
        href: `https://www.google.com/maps?q=${address}&layer=c`,
        icon: 'i-lucide-eye',
        caveat: 'Opens the map with Street View requested — we hold no coordinates to drop straight into a panorama.'
      }
    ]

    const registers: ScopedLinks = [
      {
        label: 'Land Registry',
        description: `Buy the title register for ${t.title_number}`,
        href: 'https://search-property-information.service.gov.uk/',
        icon: 'i-lucide-scroll-text',
        caveat: 'Requires a signed-in session, so it cannot be deep-linked. Copy the title number from the header.'
      },
      {
        label: 'EPC register',
        description: 'Every certificate lodged at this postcode',
        href: `https://find-energy-certificate.service.gov.uk/find-a-certificate/search-by-postcode?postcode=${encodedPostcode}`,
        icon: 'i-lucide-zap',
        enabled: postcode !== ''
      },
      {
        label: 'Planning applications',
        description: 'Council decisions at this address',
        href: `https://www.google.com/search?q=${encodeURIComponent(
          `${postcode} planning application ${t.district ?? ''}`.trim()
        )}`,
        icon: 'i-lucide-file-text',
        caveat: 'Councils each run their own register, so this is a scoped web search rather than one national source.'
      },
      {
        label: 'Planning Data (England)',
        description: 'National planning data map',
        href: 'https://www.planning.data.gov.uk/map/',
        icon: 'i-lucide-layers',
        caveat: 'Beta service with incomplete coverage. Search by postcode once it loads.'
      }
    ]

    const market: ScopedLinks = [
      {
        label: 'Rightmove sold prices',
        description: 'What has actually sold nearby',
        href: `https://www.rightmove.co.uk/house-prices/${postcodeSlug.value}.html`,
        icon: 'i-lucide-trending-up',
        enabled: postcodeSlug.value !== ''
      },
      {
        label: 'Zoopla sold prices',
        description: 'Second opinion on local values',
        href: `https://www.zoopla.co.uk/house-prices/${postcodeSlug.value}/`,
        icon: 'i-lucide-line-chart',
        enabled: postcodeSlug.value !== ''
      },
      {
        label: 'Rightmove for sale',
        description: 'Current asking prices in the area',
        href: `https://www.rightmove.co.uk/property-for-sale/search.html?searchLocation=${encodedPostcode}`,
        icon: 'i-lucide-tag',
        enabled: postcode !== ''
      }
    ]

    const ownership: ScopedLinks = [
      {
        label: 'Companies House',
        description: company.value
          ? `${company.value.name} (${company.value.company_number})`
          : 'Proprietor company record',
        href: `https://find-and-update.company-information.service.gov.uk/company/${company.value?.company_number ?? ''}`,
        icon: 'i-lucide-building-2',
        enabled: Boolean(company.value?.company_number)
      },
      {
        label: 'Filing history',
        description: 'Accounts, charges and confirmation statements',
        href: `https://find-and-update.company-information.service.gov.uk/company/${company.value?.company_number ?? ''}/filing-history`,
        icon: 'i-lucide-archive',
        enabled: Boolean(company.value?.company_number)
      },
      {
        label: 'Charges',
        description: 'Who has security over the asset',
        href: `https://find-and-update.company-information.service.gov.uk/company/${company.value?.company_number ?? ''}/charges`,
        icon: 'i-lucide-landmark',
        enabled: Boolean(company.value?.company_number) && Boolean(company.value?.has_charges)
      }
    ]

    return [
      { title: 'Location', icon: 'i-lucide-map', links: location },
      { title: 'Registers', icon: 'i-lucide-library', links: keep(registers) },
      { title: 'Market', icon: 'i-lucide-pound-sterling', links: keep(market) },
      { title: 'Ownership', icon: 'i-lucide-building', links: keep(ownership) }
    ].filter(group => group.links.length > 0)
  })

  return { groups, searchAddress }
}

/** A link that only makes sense when the data behind it exists. */
type ScopedLinks = Array<ResearchLink & { enabled?: boolean }>

function keep(links: ScopedLinks): ResearchLink[] {
  return links
    .filter(link => link.enabled !== false)
    .map(({ enabled, ...link }) => {
      void enabled

      return link
    })
}
