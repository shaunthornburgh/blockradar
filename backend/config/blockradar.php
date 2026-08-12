<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CCOD dataset
    |--------------------------------------------------------------------------
    |
    | HM Land Registry publishes "UK companies that own property in England
    | and Wales" (CCOD) monthly. Drop the monthly CSV into the storage path
    | below and run `php artisan ccod:import`.
    |
    */

    'ccod' => [
        // Directory inside storage/app where monthly CSVs are dropped.
        'storage_path' => env('CCOD_STORAGE_PATH', 'ccod'),

        // HMLR requires a free API key for automated downloads.
        'api_key' => env('HMLR_API_KEY'),
        'api_base_url' => env('HMLR_API_BASE_URL', 'https://use-land-property-data.service.gov.uk/api/v1'),

        // Rows held in memory per chunk. Each chunk is one transaction.
        'chunk_size' => (int) env('CCOD_CHUNK_SIZE', 1000),

        // CCOD lists up to four proprietors per title. The first is treated as
        // the owner; the rest are kept in the title's `raw` payload.
        'primary_proprietor_index' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Energy Performance Certificates
    |--------------------------------------------------------------------------
    |
    | England & Wales EPC data is free from MHCLG. The old
    | epc.opendatacommunities.org service now redirects to
    | get-energy-performance-data.communities.gov.uk.
    |
    | Two routes exist and they are not equivalent:
    |
    |  - The bulk CSV carries every field, including floor area, property
    |    type, habitable rooms, construction age band and heating. This is the
    |    primary source and what `epc:import` reads.
    |  - The developer API only returns a summary per certificate — number,
    |    address, postcode, UPRN, rating band and registration date. It is
    |    useful for finding certificates, not for property characteristics.
    |
    */

    'epc' => [
        // Directory inside storage/app holding bulk CSV extracts.
        'storage_path' => env('EPC_STORAGE_PATH', 'epc'),

        'chunk_size' => (int) env('EPC_CHUNK_SIZE', 1000),

        // Only keep certificates in postcodes we actually hold titles for.
        // The full domestic extract is tens of millions of rows; this keeps
        // the table proportional to the portfolio.
        'restrict_to_known_postcodes' => (bool) env('EPC_RESTRICT_POSTCODES', true),

        'api' => [
            'base_url' => env('EPC_API_BASE_URL', 'https://api.get-energy-performance-data.communities.gov.uk'),

            // Bearer token from your account page on the MHCLG service.
            // Note this is a bearer token, not the Basic auth the retired
            // opendatacommunities API used.
            'token' => env('EPC_API_TOKEN'),

            'domestic_search_path' => env('EPC_API_SEARCH_PATH', '/api/domestic/search'),

            // 1-5000 per the published documentation.
            'page_size' => (int) env('EPC_API_PAGE_SIZE', 100),

            // Documented as 6000 requests per 5 minutes per IP. Held slightly
            // below, as with Companies House, because our window is fixed.
            'rate_limit_per_five_minutes' => (int) env('EPC_API_RATE_LIMIT', 5500),

            'timeout' => (int) env('EPC_API_TIMEOUT', 15),
            'default_retry_after' => (int) env('EPC_API_RETRY_AFTER', 60),
        ],

        'match' => [
            // Percentage building-key similarity required for a medium
            // confidence match.
            'fuzzy_threshold' => (float) env('EPC_FUZZY_THRESHOLD', 82),

            // Matches below this are recorded but not written to the title.
            // Defaults to medium: a postcode-only match would attach the
            // neighbours' flats and corrupt the multi-unit count, which is the
            // single most valuable thing EPC data gives us.
            'min_confidence' => env('EPC_MIN_CONFIDENCE', 'medium'),

            // A guard against a postcode-only match on a large estate.
            'max_certificates_per_title' => (int) env('EPC_MAX_CERTS_PER_TITLE', 300),

            // EPCs are valid for ten years; re-matching twice a year is ample.
            'stale_after_days' => (int) env('EPC_STALE_DAYS', 180),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Candidate filters
    |--------------------------------------------------------------------------
    |
    | The core filter is not configurable: a candidate must be Freehold and
    | carry the multiple address indicator. Everything below narrows that
    | population further and can be tuned per sourcing strategy.
    |
    */

    'candidate_filters' => [
        // Empty means "no restriction". Values are matched case-insensitively
        // against the CCOD Region column, e.g. 'North West'.
        'regions' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CANDIDATE_REGIONS', ''))
        ))),

        // Postcode area = the letters at the front of the outward code, e.g.
        // 'M' for M14 5TP, 'LS' for LS6 2AA. Empty means "no restriction".
        'postcode_areas' => array_values(array_filter(array_map(
            fn (string $area) => strtoupper(trim($area)),
            explode(',', (string) env('CANDIDATE_POSTCODE_AREAS', ''))
        ))),

        // Addresses containing any of these are almost never splittable
        // residential blocks, so they never become candidates.
        'exclude_address_keywords' => [
            'car park',
            'garage',
            'garages',
            'warehouse',
            'industrial estate',
            'business park',
            'retail park',
            'shopping centre',
            'public house',
            'petrol filling station',
            'land at',
            'land on',
            'land lying to',
            'land adjoining',
            'substation',
            'electricity sub station',
            'telecommunications',
        ],

        // Blocks below this many estimated units are not worth pursuing. Null
        // falls back to scoring.minimum_units.
        'minimum_estimated_units' => null,

        // When true, a title whose unit count could not be estimated is still
        // allowed through. Address formats vary enough that discarding them
        // would lose real blocks.
        'allow_unknown_unit_count' => true,

        // Drop titles where a trustworthy EPC match shows a single whole
        // house. CCOD sets the multiple address indicator for things like a
        // house plus an outbuilding, and this catches those.
        'exclude_epc_single_dwellings' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | MUFB confidence
    |--------------------------------------------------------------------------
    |
    | The candidate population is "freehold + multiple address indicator",
    | which also catches terraces, land parcels and mixed commercial. This
    | block scores how likely a candidate is to be an actual block of flats,
    | out of 100, from evidence that already exists on the title.
    |
    | It is deliberately *not* the deal score in `scoring` below. That answers
    | "is this worth doing?"; this answers "is this even a block?". They are
    | kept apart so tuning one never silently moves the other, and because
    | this one is derived at query time — changing these weights takes effect
    | immediately and needs no rescore.
    |
    | The same weights drive the API filter (`min_mufb`), the `mufb` sort and
    | the badge on the list row, so all three always agree.
    |
    | Keep the weights summing to 100. Nothing enforces it, and going over
    | only means confidences above 100 rather than anything breaking, but the
    | level thresholds below are expressed on that scale.
    |
    */

    'mufb' => [
        'weights' => [
            // Two or more surveyed dwellings in one building is the single
            // strongest piece of evidence we have, so it carries the most.
            // Requires a match at medium confidence or better.
            'multiple_epc_certificates' => 40,

            // The matched EPCs describe flats rather than a house, a shop or
            // a bungalow.
            'epc_flat_property_type' => 25,

            // At or above scoring.minimum_units, from whichever unit source
            // is the most trustworthy for the title.
            'meets_minimum_units' => 20,

            // The CCOD address itself names flats. Weak on its own — plenty
            // of real blocks are addressed "12-18 Some Street" — but it is
            // the only residential signal available before EPC enrichment.
            'flat_address_keyword' => 15,
        ],

        // Matched against a lower-cased `epc_property_type`.
        'flat_property_types' => ['flat', 'maisonette'],

        // Matched as substrings against a lower-cased property address.
        'flat_address_keywords' => ['flat', 'flats', 'apartment', 'apartments', 'maisonette'],

        // Band boundaries for the badge on the list row, and for the
        // `min_mufb` shorthands.
        'levels' => [
            'high' => 65,
            'medium' => 35,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Candidate list defaults
    |--------------------------------------------------------------------------
    |
    | Applied by the candidates page when it is opened with no query string at
    | all. The frontend writes them into the URL rather than applying them
    | invisibly, so the filter bar always reflects what is being asked for and
    | "Clear" genuinely shows the whole population.
    |
    | These are served over /meta so the page and the API agree on one
    | definition. Set to an empty array to land on the unfiltered list.
    |
    */

    'candidate_defaults' => [
        // A matched EPC is what separates "a block of flats" from "a freehold
        // with more than one address on it".
        'has_epc' => true,

        // Below four units a split rarely pays for itself.
        'min_units' => (int) env('CANDIDATE_DEFAULT_MIN_UNITS', 4),

        // Titles whose unit count could not be worked out are excluded by the
        // default view — with min_units set they would otherwise dominate it.
        // Flipping this to true in the UI keeps them.
        'include_unknown_units' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Companies House enrichment
    |--------------------------------------------------------------------------
    */

    'companies_house' => [
        'api_key' => env('COMPANIES_HOUSE_API_KEY'),
        'base_url' => env('COMPANIES_HOUSE_BASE_URL', 'https://api.company-information.service.gov.uk'),

        // Companies House allows 600 requests per 5 minutes. The default here
        // is deliberately lower: the throttle uses a fixed window, so sitting
        // exactly on the published ceiling can still trip their rolling one
        // across a window boundary.
        'rate_limit_per_five_minutes' => (int) env('COMPANIES_HOUSE_RATE_LIMIT', 550),

        // Re-pull a company once its data is this old.
        'stale_after_days' => (int) env('COMPANIES_HOUSE_STALE_DAYS', 30),

        // Give up on a company after this many failed attempts, so one bad
        // record cannot consume rate limit on every run.
        'max_enrichment_attempts' => (int) env('COMPANIES_HOUSE_MAX_ATTEMPTS', 5),

        // The company profile is one request. These are each an extra request
        // per company, so they are opt-in.
        //
        // Charges: only requested when the profile says has_charges is true,
        // which keeps the cost proportional to how many companies are secured.
        // Officers: off by default — the count is a nice-to-have and enabling
        // it roughly halves how many companies fit in a rate-limit window.
        'fetch_charges' => (bool) env('COMPANIES_HOUSE_FETCH_CHARGES', true),
        'fetch_officers' => (bool) env('COMPANIES_HOUSE_FETCH_OFFICERS', false),

        // Keep the full profile payload on the company for auditing.
        'store_raw' => (bool) env('COMPANIES_HOUSE_STORE_RAW', true),

        'timeout' => (int) env('COMPANIES_HOUSE_TIMEOUT', 15),

        // Used when the API throttles us without a Retry-After header.
        'default_retry_after' => (int) env('COMPANIES_HOUSE_RETRY_AFTER', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring
    |--------------------------------------------------------------------------
    |
    | Weights are relative and normalised to a 0-100 score by the scorer.
    | Tune these without touching code.
    |
    */

    'scoring' => [
        'weights' => [
            'area_yield' => 30,
            'estimated_units' => 25,
            'title_split_upside' => 20,
            'ownership_duration' => 10,

            // Renamed from 'company_dormancy'. Overdue accounts, an overdue
            // confirmation statement, insolvency proceedings or long-stale
            // filings all point at an owner with a reason to sell.
            'filing_distress' => 10,

            // Poor EPC ratings mean refurbishment headroom, and an EPC below E
            // also blocks new lettings under MEES — which pressures the owner.
            // Needs EPC enrichment.
            'epc_refurb_potential' => 10,

            // Renamed from 'no_existing_charges', and the sense is inverted:
            // a secured company is now scored as *more* motivated, not less.
            // Worth knowing that charges also mean lender consent is needed
            // before a title can be split, so they add friction as well as
            // pressure — hence the small weight.
            'charges_pressure' => 5,
        ],

        // A candidate at or above this score is surfaced as high priority.
        'high_priority_threshold' => (int) env('SCORING_HIGH_PRIORITY_THRESHOLD', 70),

        // Areas below this gross yield are treated as weak for title splitting.
        'minimum_gross_yield' => (float) env('SCORING_MINIMUM_GROSS_YIELD', 6.0),

        // Blocks smaller than this rarely justify the cost of splitting.
        'minimum_units' => (int) env('SCORING_MINIMUM_UNITS', 4),
    ],

];
