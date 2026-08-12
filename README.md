# Block Radar

Deal sourcing for unsplit multi-unit freehold blocks (MUFBs) in England & Wales.

Block Radar ingests the free HM Land Registry **CCOD** dataset (UK companies that
own property in England and Wales), filters it down to freehold titles carrying a
multiple-address indicator, enriches the proprietor companies from Companies
House, scores the result for title-split upside, and tracks the survivors through
a sourcing pipeline.

> **Status.** The Docker stack, database schema, API, admin dashboard, the
> **CCOD import pipeline**, **Companies House enrichment**, **EPC enrichment**
> and **candidate rescoring** are all in place and running. Every scoring
> component reports whether its data is actually available, so a score is never
> silently based on gaps.

---

## Stack

| Layer | Choice | Notes |
| --- | --- | --- |
| Backend | Laravel 13 on PHP 8.3 (FPM) | See [a note on versions](#a-note-on-versions) |
| Web server | Nginx 1.27 | Separate container, proxies to `php:9000` |
| Frontend | Nuxt 4 + Nuxt UI 4, TypeScript | Composition API, `<script setup>` |
| Database | MySQL 8.4 LTS | Tuned in `docker/mysql/my.cnf` for bulk loads |
| Cache / queue / sessions | Redis 7 | |
| Auth | Laravel Sanctum, bearer tokens | No CSRF cookie dance across origins |

Every service runs in its own container.

### A note on versions

The original brief specified Laravel 11 and Nuxt 3. Both were changed
deliberately:

- **Laravel 11 → 13.** Laravel 11 has reached end of life. Composer refuses to
  install any 11.x release because every version in the line is covered by
  unpatched security advisories; building on it would have required disabling
  Composer's advisory blocking. Laravel 13 is the current stable release and
  needed no architectural changes.
- **Nuxt 3 → 4.** The official Nuxt UI starter now scaffolds Nuxt 4, which uses
  the `app/` source directory. Still Vue 3, TypeScript and Composition API.

---

## Quick start

Requires Docker Desktop (or Docker Engine + Compose v2).

```bash
git clone <your-repo> block-radar
cd block-radar

# 1. Compose-level configuration
cp .env.example .env

# 2. Laravel configuration
cp backend/.env.example backend/.env

# 3. On Linux only — match container file ownership to your user
#    (skip on macOS; Docker Desktop handles the mapping)
echo "UID=$(id -u)" >> .env && echo "GID=$(id -g)" >> .env

# 4. Bring everything up
docker compose up -d --build
```

The first boot takes a few minutes: it builds the images, installs Composer
dependencies into a named volume, waits for MySQL and runs the migrations.
Follow along with:

```bash
docker compose logs -f php
```

Once `docker compose ps` shows `php` and `nginx` as healthy:

| URL | What |
| --- | --- |
| http://localhost:3000 | Nuxt admin dashboard |
| http://localhost:8000/api | Laravel API |
| http://localhost:8000/up | Laravel health check |
| `localhost:3306` | MySQL (user `block_radar`, password `secret`) |
| `localhost:6379` | Redis |

### Seed demo data

The dashboard is empty until the CCOD importer exists, so there is a seeder that
generates a realistic set of titles, companies and candidates:

```bash
docker compose exec php php artisan migrate:fresh --seed
```

Then sign in at http://localhost:3000 with:

```
admin@blockradar.test
password
```

---

## Repository layout

```
block-radar/
├── docker-compose.yml          # 7 services: mysql, redis, php, nginx, queue, scheduler, frontend
├── .env.example                # Compose-level config (ports, credentials, build targets)
├── .dockerignore               # Keeps host vendor/ and node_modules/ out of the images
│
├── docker/
│   ├── php/
│   │   ├── Dockerfile          # Multi-stage: base → development → production
│   │   ├── php.ini             # Memory and opcache settings sized for CCOD work
│   │   ├── www.conf            # Replaces the default FPM pool (runs non-root)
│   │   └── entrypoint.sh       # Bootstraps .env, vendor/, app key, migrations
│   ├── nginx/default.conf
│   └── mysql/my.cnf            # Buffer pool, redo log, local_infile for bulk loads
│
├── backend/                    # Laravel 13
│   ├── app/
│   │   ├── Console/Commands/   # ImportCcodCommand, CcodStatusCommand,
│   │   │                       #   EnrichCompaniesCommand, EnrichmentStatusCommand
│   │   ├── Enums/              # PipelineStage, Tenure, ImportStatus
│   │   ├── Jobs/               # ImportCcodFile, EnrichCompanyJob,
│   │   │                       #   EnrichCompaniesJob, EnrichTitlesEpcJob,
│   │   │                       #   RescoreCandidatesJob (batchable)
│   │   ├── Models/             # Company, Title, Candidate, CandidateNote,
│   │   │                       #   CcodImport, AreaMetric, User
│   │   ├── Services/
│   │   │   ├── Ccod/           # CsvReader, CcodRow, CcodProprietor,
│   │   │   │                   #   Importer, FileLocator, ImportTally
│   │   │   ├── Candidates/     # Filter, Scorer, ScoreResult, Rescorer,
│   │   │   │                   #   RescoreTally, UnitCountEstimator
│   │   │   ├── CompaniesHouse/ # Service, Throttle, CompanyEnricher,
│   │   │   │                   #   Exceptions/
│   │   │   └── Epc/            # AddressNormaliser, EpcMatcher, CsvReader,
│   │   │                       #   BulkImporter, ApiClient, TitleEpcEnricher
│   │   └── Http/
│   │       ├── Controllers/Api/
│   │       ├── Requests/
│   │       └── Resources/
│   ├── config/blockradar.php   # CCOD, candidate filters, scoring weights
│   ├── phpunit.xml             # Uses <server> not <env> — see the note inside
│   ├── database/migrations/
│   ├── database/factories/
│   ├── tests/Fixtures/         # ccod_sample.csv
│   └── routes/api.php
│
└── frontend/                   # Nuxt 4 + Nuxt UI
    ├── Dockerfile              # Multi-stage: development → build → production
    └── app/
        ├── components/candidate/  # Detail page cards: MetricsRow, ScoreBreakdown,
        │                       #   PropertyCard, OwnershipCard, ResearchLinks,
        │                       #   DealEstimates, WorkflowCard, NotesPanel, EpcRating
        ├── layouts/            # default (dashboard shell), auth
        ├── pages/              # index, login, candidates, candidates/[id],
        │                       #   titles, companies
        ├── composables/        # useApi, useAuth, useDebouncedRef, useResearchLinks
        ├── middleware/         # auth.global.ts
        ├── utils/format.ts     # Money, dates, score and stage colours
        └── types/index.ts      # Shared API types
```

---

## Data model

| Model | Purpose |
| --- | --- |
| `Title` | One row per registered title from CCOD. Re-importing a later monthly file updates rather than duplicates. |
| `Company` | Proprietor company, keyed on Companies House number, enriched from the CH API. |
| `Candidate` | A title promoted into the pipeline, with its score, breakdown and stage. |
| `CandidateNote` | Notes and recorded outreach activity. |
| `CcodImport` | One run of a monthly file through the importer — row counts, timings, errors. |
| `AreaMetric` | Rent and price data by postcode district, feeding the high-yield part of the score. |
| `EpcCertificate` | One domestic EPC — one *dwelling*, so a block yields many. |
| `TitleEpcMatch` | Links a title to the certificates in that building, with method and confidence. |

Two conventions worth knowing:

- **All money is stored in pence** as integers. `formatMoney()` on the frontend
  is the only place it converts for display.
- **The core CCOD filter** — freehold plus multiple-address indicator — is the
  `Title::splitCandidates()` scope, backed by the composite index
  `titles_split_filter_index`.

### Pipeline

`New → Title Bought → Confirmed → Outreach → Offer`

Defined once in `App\Enums\PipelineStage`, exposed to the frontend via
`GET /api/meta`. Moving a candidate through `Candidate::moveTo()` stamps the
first time it reached each stage (`title_bought_at`, `confirmed_at`, …).

---

## API

Auth is Sanctum personal access tokens sent as `Authorization: Bearer <token>`.
`config/cors.php` allows only the origins in `FRONTEND_URL` (comma separated) —
it is deliberately not `*`.

| Method | Endpoint | Notes |
| --- | --- | --- |
| `POST` | `/api/login` | Returns a token. Throttled to 10/min. |
| `GET` | `/api/meta` | Pipeline stages, the candidates-list default preset, sort keys, MUFB bands. Public. |
| `GET` | `/api/user` | Signed-in user |
| `POST` | `/api/logout` | Revokes the current token |
| `GET` | `/api/dashboard` | Totals, pipeline counts, top candidates, latest import |
| `GET` | `/api/candidates` | [Filters below](#finding-real-blocks-of-flats) |
| `GET` | `/api/candidates/filter-options` | Regions and postcode areas present in the candidate population |
| `GET` | `/api/candidates/{id}` | Detail payload: title + EPC aggregates + matched certificates, company with distress signals, notes, assignee |
| `GET` | `/api/users` | Assignee picker |
| `PATCH` | `/api/candidates/{id}` | Stage, assignee, next action, estimates |
| `GET`/`POST` | `/api/candidates/{id}/notes` | |
| `GET` | `/api/titles` | `split_only=1` applies the freehold + MAI filter |
| `GET` | `/api/companies` | |

Quick check from the host:

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@blockradar.test","password":"password"}' | jq -r .token)

curl -s http://localhost:8000/api/dashboard -H "Authorization: Bearer $TOKEN" | jq .data.totals
```

---

## Finding real blocks of flats

The candidate population is "freehold + multiple address indicator", which is
the widest net HM Land Registry lets us cast. It also catches terraces held
under one title, land parcels, parades of shops and houses with an outbuilding.
Separating the actual MUFBs out of that is what the candidates list is for.

### MUFB confidence

Every candidate carries a `mufb` block, scored 0-100 from evidence already on
the title:

| Signal | Points | Why |
| --- | --- | --- |
| Two or more matched EPC certificates | 40 | Each certificate is one surveyed dwelling. The strongest evidence available, and it needs a match at medium confidence or better. |
| EPC property type is a flat or maisonette | 25 | The building is residential and subdivided. |
| Meets `scoring.minimum_units` | 20 | Enough units for a split to pay for itself. |
| The address names flats | 15 | Weak on its own — plenty of real blocks read "12-18 Some Street" — but it is the only residential signal before EPC enrichment. |

65 and above shows as **high**, 35 and above as **medium**. The weights live in
`blockradar.mufb` and are **derived at query time**, so retuning them takes
effect on the next request — no rescore, and nothing is written to the database.

This is deliberately separate from the deal `score`. The score answers "is this
worth doing?"; MUFB confidence answers "is this even a block?".

### Candidate filters

`GET /api/candidates`:

| Parameter | Notes |
| --- | --- |
| `search` | Address, title number, postcode, CCOD proprietor name, Companies House name or number |
| `stage` | A pipeline stage. An unknown one is a 422, not a silent no-op. |
| `min_score`, `max_score` | 0-100 on the deal score |
| `min_mufb` | `high`, `medium`, `low`, or a number |
| `region` | Matched against the CCOD region, e.g. `GREATER LONDON` |
| `postcode_area` | The letters at the front of the outward code: `M`, `LS`, `SW` |
| `min_units` | See below |
| `include_unknown_units` | `true` keeps titles whose unit count is unknown |
| `has_epc` | `true` requires a match at medium confidence or better — a postcode-only match attached the neighbours' certificates and evidences nothing |
| `min_epc_certificates` | Raw count on the title |
| `company_distressed` | Overdue accounts, overdue confirmation statement, insolvency history, in an insolvency process, or dissolved |
| `has_charges` | Registered charges at Companies House |
| `archived` | `true` shows the archive instead of the live pipeline |
| `sort` | `mufb`, `score`, `units`, `epc_certificate_count`, `created_at`, `updated_at`, `scored_at`, `next_action_at` |
| `direction` | `asc` / `desc` |
| `per_page` | 1-100, default 25 |

Filters combine — every one narrows what the others left.

**Units.** `min_units` and `sort=units` use the best count available for the
title: a count of matched EPC certificates where one exists, otherwise the
candidate's own figure (which the API lets a user override), otherwise the
estimate parsed out of the CCOD address. Each row reports which it used in
`units_source` — `epc` or `estimate`.

**Unknown unit counts.** Plenty of addresses cannot be parsed and have no EPC
match, so their count is `null`. With `min_units` set they are excluded by
default, because they would otherwise dominate the list. `include_unknown_units=true`
keeps them, which matters in regions EPC enrichment has not reached yet.

### The default view

Opening `/candidates` with no query string applies the **Likely MUFBs** preset
from `blockradar.candidate_defaults` — currently `has_epc=true` and
`min_units=4`. The page writes those into the URL rather than applying them
invisibly, so the filter bar shows exactly what is being asked for and
**Clear all** genuinely reveals the whole population.

Filter state lives entirely in the query string, so any view is a shareable
link and Back works. `?view=all` is the frontend's marker for "deliberately
unfiltered" — without it, an empty query string would re-trigger the preset.
The API ignores it.

```bash
# The default view, ranked by how likely each one is to be a block
curl -s "http://localhost:8000/api/candidates?has_epc=true&min_units=4&sort=mufb" \
  -H "Authorization: Bearer $TOKEN" | jq '.data[] | {units, units_source, mufb}'

# London blocks whose owner is under filing pressure
curl -s "http://localhost:8000/api/candidates?min_mufb=high&region=GREATER%20LONDON&company_distressed=true" \
  -H "Authorization: Bearer $TOKEN" | jq '.meta.total'
```

---

## Importing CCOD data

Download the monthly **CCOD FULL** extract (free, registration required) from
<https://use-land-property-data.service.gov.uk> and drop the CSV into
`backend/storage/app/ccod/`. Then:

```bash
# Newest file in storage/app/ccod, processed on the queue
docker compose exec php php artisan ccod:import

# A specific file
docker compose exec php php artisan ccod:import /path/to/CCOD_FULL_2026_07.csv

# Watch a queued import from another terminal
docker compose exec php php artisan ccod:status --watch
```

| Flag | Effect |
| --- | --- |
| *(none)* | Dispatches to the queue, then follows progress. Ctrl-C stops watching, not the import. |
| `--sync` | Runs in the current process. Best for small files and debugging. |
| `--no-wait` | Dispatches and returns immediately. |
| `--fresh` | **Destructive.** Deletes all titles, companies and candidates first — including pipeline progress. Prompts unless `--force`. |
| `--force` | Skips confirmation prompts. |

### What it does

1. **Streams** the CSV — never loaded into memory. Columns are addressed by
   header name, so HMLR reordering them or the CHANGE_ONLY variant's extra
   columns will not break it. The file's own `Row Count:` trailer is used for
   the progress total, avoiding a pre-pass.
2. **Upserts** companies by registration number and titles by title number, in
   chunks of `ccod.chunk_size` (default 1000), one transaction per chunk.
3. **Promotes** qualifying titles to candidates at stage `New`, with a score.

Measured on this machine: **100,000 rows in ~18 seconds, peaking at 72 MB** —
flat regardless of file size. A 3.5M-row full extract projects to roughly
ten minutes.

### Re-running is safe

Titles and companies are upserted rather than duplicated, `first_seen_at` is
never rewritten, and **a candidate that already exists is left completely
alone** — its stage, score, assignee and notes are yours, not the importer's.
A second pass over the same file reports `0 titles created, N updated,
0 candidates created`.

### Which titles become candidates

The core filter is fixed, because it is the definition of the population:

- Tenure is **Freehold**
- **Multiple address indicator** is set

Everything after that is `candidate_filters` in `config/blockradar.php`:

| Setting | Default | Notes |
| --- | --- | --- |
| `regions` | *(empty — all)* | `CANDIDATE_REGIONS="North West,Wales"` |
| `postcode_areas` | *(empty — all)* | `CANDIDATE_POSTCODE_AREAS="M,LS,B"` |
| `exclude_address_keywords` | car park, garage, warehouse, land at, … | Keeps obvious non-residential stock out |
| `minimum_estimated_units` | falls back to `scoring.minimum_units` (4) | |
| `allow_unknown_unit_count` | `true` | Address formats vary too much to discard unknowns |

Every rejection is counted by reason and stored on the import, so it is always
clear why a run produced few candidates:

```
Titles not promoted to candidates:
  single address                 25,000
  not freehold                   25,000
  excluded address                  515
```

### Scoring

`CandidateScorer` uses the weights in `config/blockradar.php`. Only components
whose inputs exist are counted, and the score is the weighted average over
**available** weight — so a title is never penalised for data that has not been
fetched yet. `score_breakdown` records the value, weight, points and a
human-readable note for each component, plus `weight_available` out of
`weight_total`.

| Component | Weight | Needs |
| --- | --- | --- |
| `area_yield` | 30 | An `AreaMetric` for the postcode district |
| `estimated_units` | 25 | A unit count readable from the address |
| `title_split_upside` | 20 | Price paid and a unit count |
| `ownership_duration` | 10 | `date_proprietor_added` |
| `epc_refurb_potential` | 10 | A matched EPC — see [EPC enrichment](#epc-enrichment) |
| `filing_distress` | 10 | An enriched company — see [Companies House enrichment](#companies-house-enrichment) |
| `charges_pressure` | 5 | An enriched company |

Weights are relative, not percentages: they total 110 and the score is the
weighted average over whichever components have data.

Unit counts come from `UnitCountEstimator`, which prefers hard evidence:
a count of matched EPC certificates where one exists, falling back to parsing
the address ("Flats 1-8 …" → 8; "12-18 Osborne Road" → 4, counting even numbers
only). It returns `null` rather than guess, and `null` means unknown, not one.

### After changing config or job code

The queue worker is a long-running process that loads config once at boot.
Restart it or it will keep using the old values:

```bash
docker compose restart queue scheduler
```

### Try it without real data

`backend/tests/Fixtures/ccod_sample.csv` is a seven-row extract in the real
CCOD column layout, covering each filter branch:

```bash
docker compose exec php php artisan ccod:import tests/Fixtures/ccod_sample.csv --sync --force
```

Expect 6 titles, 4 companies and 3 candidates.

---

## Companies House enrichment

Get a free API key at
<https://developer.company-information.service.gov.uk>, put it in
`backend/.env` as `COMPANIES_HOUSE_API_KEY`, then restart so the workers pick
it up:

```bash
docker compose restart php queue
```

```bash
# Queue the 100 highest-priority companies (default)
docker compose exec php php artisan companies:enrich

# Only companies that actually have a candidate, run in this process
docker compose exec php php artisan companies:enrich --only-candidates --sync --limit=50

# One specific company, ignoring freshness
docker compose exec php php artisan companies:enrich 04512378 --sync --force

# Coverage and remaining rate-limit budget
docker compose exec php php artisan companies:enrich-status --watch
```

| Flag | Effect |
| --- | --- |
| `--limit=100` | How many companies to process |
| `--only-candidates` | Restrict to companies with at least one candidate |
| `--force` | Re-enrich even if the data is fresh, and retry not-found / exhausted companies |
| `--sync` | Run in this process rather than queueing |
| `--batch=25` | Companies per queued job |

### Prioritisation

Companies linked to candidates come first, ordered by their best candidate
score, so a `--limit`ed run spends its budget where it changes decisions.

### Rate limiting

Companies House allows **600 requests per 5 minutes**. The throttle lives in
Redis, so the budget is shared across every queue worker and any CLI run
happening at the same time — not per process. `COMPANIES_HOUSE_RATE_LIMIT`
defaults to 550 to leave headroom, because the local window is fixed and theirs
is rolling.

When the budget runs out nothing sleeps and nothing is lost:

- A queued job **releases itself** with the exact delay required, freeing the
  worker. `EnrichCompaniesJob` requeues only the companies it has not reached,
  so a paused batch resumes rather than restarts.
- `--sync` waits out the window once, then stops cleanly and tells you to
  re-run.
- A `429` from Companies House is honoured via its `Retry-After` header, and
  the rest of the local window is burnt on the assumption their count is right
  and ours is not.

Request cost per company is **one** for the profile, plus one for charges
(only when the profile reports charges), plus one for officers
(`COMPANIES_HOUSE_FETCH_OFFICERS`, off by default because it roughly halves
throughput).

### Error handling

| Outcome | Behaviour |
| --- | --- |
| `404` | Recorded as `not_found` and **never retried** — CCOD carries overseas and historic registrations that will never resolve |
| `401` / `403` | Aborts the run immediately rather than collecting the same error on every company |
| `429` | Honours `Retry-After`; the job releases, the command waits |
| `5xx` / timeout | Recorded against the company with the message in `enrichment_error`; retried on later runs until `COMPANIES_HOUSE_MAX_ATTEMPTS` |

Connection failures are retried at the HTTP layer; HTTP error statuses
deliberately are not, so a single `429` costs one request rather than three.

### Motivation signals

Enrichment fills `filing_distress` (weight 10) and `charges_pressure`
(weight 5). An active, up-to-date company scores **zero** on distress — that is
the neutral baseline, not a penalty. Points accumulate out of 100:

| Signal | Points |
| --- | --- |
| Accounts overdue | 30 |
| In liquidation / administration / receivership | 30 |
| Confirmation statement overdue | 25 |
| Last accounts more than 18 months old | 15 (+5 if incorporated over 15 years ago) |
| Past insolvency history (not currently distressed) | 10 |
| Dissolved | 10 — capped deliberately: motivation is high but the company cannot sell and the asset may be bona vacantia |

`charges_pressure` scores 1.0 when the company has registered charges. Note the
tension: charges signal financial pressure, but they also mean a lender must
consent before a title can be split. That friction is why the weight is small.

Each component records a `signals` array in `score_breakdown`, so the reason a
candidate ranks where it does is always legible.

> Enriching a company does not retroactively change scores already stored
> against its candidates. Run
> [`candidates:rescore`](#rescoring-candidates) afterwards to pick the new data
> up.

---

## EPC enrichment

England & Wales EPC data is free from MHCLG. The old
`epc.opendatacommunities.org` service now **301-redirects** to
[get-energy-performance-data.communities.gov.uk](https://get-energy-performance-data.communities.gov.uk).

There are two routes and they are **not** equivalent:

| Route | Gives you | Used for |
| --- | --- | --- |
| **Bulk CSV** | Everything — floor area, property type, built form, habitable rooms, construction age band, heating, rating | The primary path. `epc:import` reads it. |
| **Developer API** | A 13-field summary only: certificate number, address lines, postcode, town, council, constituency, rating band, registration date, UPRN | Discovery. It cannot supply floor area or property characteristics. |

That is why the pipeline is bulk-first. `EpcApiClient` is implemented for
lookups (bearer token, 6000 requests per 5 minutes, throttled through Redis
exactly like Companies House), but it is not a substitute for the download.

### Loading and matching

```bash
# 1. Download the domestic bulk extract, unzip into backend/storage/app/epc/
docker compose exec php php artisan epc:import

# 2. Match titles to certificates
docker compose exec php php artisan epc:enrich --only-candidates --sync
```

`epc:import` keeps only postcodes you hold titles in by default, which stops a
national extract of tens of millions of rows landing in the database. Pass
`--all-postcodes` to keep everything.

| Command | Options |
| --- | --- |
| `epc:import {path?}` | `--all-postcodes`, `--fresh`, `--force` |
| `epc:enrich` | `--limit`, `--force`, `--only-candidates`, `--sync`, `--min-confidence`, `--batch` |

### How matching works

EPC records describe **dwellings**; CCOD titles describe **buildings**. A block
of eight flats is one title and eight certificates. Lining them up is the whole
problem, and the number of certificates found is the prize — it is a measured
unit count rather than a guess.

`AddressNormaliser` reduces both sides to a *building key*: strip the unit
designator, fold street abbreviations, drop the postcode, and remove trailing
locality words that only one side carries.

```
CCOD  "Flats 1-8 Hawthorn House, 23 Bury New Road, Manchester"
EPC   "Flat 3, Hawthorn House, 23 Bury New Road"
                    ↓ both become
      "hawthorn house 23 bury new rd"
```

Four strategies, first hit wins:

| Method | Confidence | Notes |
| --- | --- | --- |
| UPRN | high | Wired up and tested, but **dormant** — CCOD supplies no UPRNs |
| Exact address | high | Same postcode, identical building key. Resolved in SQL on the `(postcode, building_key_hash)` index |
| Fuzzy address | medium | Similarity ≥ `EPC_FUZZY_THRESHOLD` (82%) |
| Postcode only | low | Everything in the postcode — almost certainly too much |

Fuzzy matching uses similarity to **locate** the building, then takes its
members by exact key. Accepting every certificate above the threshold does not
work: a postcode holding "Block A Mill Lane", "Block B Mill Lane" and "Block C
Mill Lane" scores all three highly, and the matched set becomes three
buildings' worth of flats. One fuzzy decision, not one per certificate.

`--min-confidence` defaults to **medium**. A postcode-only match would attach
the neighbours' flats and corrupt the unit count, so it is recorded but not
written unless you ask for it.

### What gets stored

Individual links live in `title_epc_matches` with their method, confidence and
similarity. Aggregates are summarised onto `titles`:

- `epc_certificate_count` — the unit count, when ≥ 2 at medium confidence or better
- `epc_current_rating` — the **worst** band in the building, since that is where the upside is
- `epc_average_energy_efficiency`, `epc_total_floor_area`, `epc_habitable_rooms` — summed or averaged across the block
- `epc_property_type`, `epc_built_form`, `epc_construction_age_band`, `epc_main_heating`, `epc_uprn` — from the most recently lodged certificate
- `unit_count_source` — `address` or `epc`

Matches are rebuilt from scratch on every run, so a newer extract cannot leave
a stale flat attached and silently inflate the count. A CCOD re-import will
**not** overwrite an EPC-derived unit count with the weaker address guess.

### What it changes

- **Unit estimation** — certificate counts replace address parsing. A single
  certificate does not: one surveyed dwelling is not a unit count.
- **`title_split_upside`** — switches from price per estimated unit to price
  per m² of real floor area.
- **`epc_refurb_potential`** (weight 10) — a poor EPC is upside. Below band E a
  property cannot be re-let under MEES, which is itself a reason to sell.
- **Filtering** — a trustworthy single certificate for a `House` or `Bungalow`
  rejects the title as `epc_single_dwelling`.

### Try it

`backend/tests/Fixtures/epc_sample.csv` holds ten certificates in real bulk-CSV
column layout, covering exact, fuzzy, UPRN and postcode-only matching:

```bash
docker compose exec php php artisan epc:import tests/Fixtures/epc_sample.csv --all-postcodes --force
```

---

## Rescoring candidates

Scores are written once, when a candidate is created. Enriching a company or
matching an EPC afterwards does not retroactively change them —
`candidates:rescore` is what closes that loop.

```bash
# See what would change, without writing anything
docker compose exec php php artisan candidates:rescore --dry-run --limit=50000

# Apply it (queued and batched by default)
docker compose exec php php artisan candidates:rescore --limit=50000
```

| Flag | Effect |
| --- | --- |
| `--limit=500` | How many candidates to examine |
| `--force` | Examine every candidate, not just those enriched since scoring |
| `--only-enriched` | Only candidates with Companies House **or** EPC data |
| `--company-enriched` / `--epc-enriched` | Narrow to one source |
| `--min-score-change=0` | Only write when the score moves at least this far |
| `--sync` | Run in this process rather than queueing |
| `--dry-run` | Report only. Always runs in-process so you get the answer immediately |
| `--batch=250` | Candidates per queued job |
| `--no-wait` | Dispatch and exit |
| `--include-archived` | Archived candidates are skipped by default |

### What it will and will not touch

Exactly three columns are written: **`score`, `score_breakdown`, `scored_at`**.

Left alone: `stage` and its timestamps, `assigned_to_id`, `next_action_at`,
notes, `is_archived`, and the four estimate fields the API lets users edit —
`estimated_units`, `estimated_gdv`, `estimated_uplift`, `gross_yield`. Any of
those may be a deliberate override, so a batch job has no business rewriting
them.

### Which candidates it picks

By default, only those whose **own data is newer than their own score** — never
scored, or a company/EPC enrichment timestamp later than `scored_at`. This is a
per-candidate comparison, not a global cut-off, so a limited run always spends
its budget where something has actually changed. Selection is oldest score
first, so repeated `--limit`ed runs sweep the whole table instead of circling
the same rows.

### Statistics

Movements are accumulated as a histogram rather than a list of values. Scores
are integers 0-100, so 201 buckets describe a run exactly — which gives a true
median, and a tally that merges across queued chunks without shipping every
value around. A run over 3,218 candidates reported identical figures whether
run as one in-process pass or seven queued jobs:

```
| Candidates examined              | 3,218        |
| Scores changed                   | 3,212        |
| Mean movement                    | -7.58 points |
| Median movement                  | -7 points    |
| Largest rise / fall              | +8 / -24     |
  Threshold crossings:
    60   0 up, 95 down
    70   0 up, 1,578 down
```

Those 1,578 candidates dropping below 70 are the point of the exercise: EPC
floor area replaced the cruder price-per-unit proxy, and the ranking changed
accordingly.

### On `--min-score-change`

It defaults to **0**, meaning every recomputation is written. A non-zero
default would leave `score_breakdown` describing data that has since changed —
still claiming "Company not yet enriched" when it is. With a threshold set,
sub-threshold movements stamp `scored_at` only, so limited runs still make
progress; the trade-off is that the stored breakdown can lag by up to one
enrichment cycle.

---

## Everyday commands

```bash
docker compose up -d                  # start
docker compose down                   # stop
docker compose down -v                # stop and wipe MySQL + Redis data
docker compose ps                     # health status
docker compose logs -f php nginx      # tail the backend

docker compose exec php php artisan migrate
docker compose exec php php artisan tinker
docker compose exec php php artisan test
docker compose exec php ./vendor/bin/pint
docker compose exec php composer install

docker compose exec php php artisan ccod:import        # see "Importing CCOD data"
docker compose exec php php artisan ccod:status --watch
docker compose exec php php artisan companies:enrich   # see "Companies House enrichment"
docker compose exec php php artisan companies:enrich-status --watch
docker compose exec php php artisan epc:import         # see "EPC enrichment"
docker compose exec php php artisan epc:enrich --only-candidates
docker compose exec php php artisan candidates:rescore --dry-run

docker compose exec frontend npm run lint
docker compose exec frontend npm run typecheck

docker compose exec mysql mysql -u block_radar -psecret block_radar
docker compose exec redis redis-cli
```

The `queue` container runs `queue:work redis` and the `scheduler` container runs
`schedule:work`. Both share the backend image and code volume. After changing
job or command code, restart them:

```bash
docker compose restart queue scheduler
```

---

## Configuration

`backend/config/blockradar.php` holds everything the next phase needs, all
env-overridable:

- **CCOD** — storage path, HMLR API key, chunk size for streaming the CSV.
- **Companies House** — API key, base URL, the 600-per-5-minutes rate limit, and
  how long enrichment stays fresh.
- **Scoring** — relative weights for area yield, estimated units, split upside,
  ownership duration, company dormancy and absence of charges, plus the
  high-priority threshold. Changing these needs a `candidates:rescore` to take
  effect on existing rows.
- **MUFB confidence** — the weights and band boundaries behind
  [MUFB confidence](#mufb-confidence). Derived per request, so a change is live
  immediately and no rescore is involved.
- **Candidate defaults** — the "Likely MUFBs" preset the candidates page lands
  on. Served over `/api/meta` so the page and the config cannot drift apart;
  set it to an empty array to land on the unfiltered list.

Both API keys are free:

- HM Land Registry: https://use-land-property-data.service.gov.uk
- Companies House: https://developer.company-information.service.gov.uk

---

## Production builds

The Dockerfiles are multi-stage. Switch targets in `.env`:

```env
BACKEND_TARGET=production
FRONTEND_TARGET=production
```

The production backend stage bakes source and `--no-dev` dependencies into the
image with an authoritative classmap; the production frontend stage ships only
Nitro's `.output` on a non-root user. Before deploying, also set `APP_DEBUG=false`,
a strong `APP_KEY`, real database credentials, and `FRONTEND_URL` to your actual
domain.

---

## Not built yet

1. Real GDV / rental-yield modelling. `title_split_upside` uses price per m² of
   EPC floor area where available, and price per estimated unit otherwise.
2. Automated download of the monthly extracts from the HMLR and MHCLG APIs.
5. An address-to-UPRN source (OS Open UPRN / AddressBase). The EPC matcher
   already has a UPRN path; CCOD supplies no UPRNs, so it is dormant.
