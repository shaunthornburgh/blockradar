<?php

namespace Tests\Feature;

use App\Enums\EpcMatchConfidence;
use App\Enums\EpcMatchMethod;
use App\Enums\PipelineStage;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The candidates list endpoint, which is where a user separates real blocks
 * of flats from the rest of the freehold-plus-multiple-addresses population.
 */
class CandidateListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    /**
     * A candidate built from explicit parts, so each test states only the
     * fields it is actually about.
     */
    private function candidate(array $candidate = [], array $title = [], array|false $company = []): Candidate
    {
        $companyModel = $company === false
            ? null
            : Company::factory()->create(array_merge([
                'name' => 'Neutral Holdings Ltd',
                'status' => 'active',
                'enriched_at' => now(),
                'accounts_overdue' => false,
                'confirmation_statement_overdue' => false,
                'has_insolvency_history' => false,
                'has_charges' => false,
                'charges_count' => 0,
            ], $company));

        $titleModel = Title::factory()->create(array_merge([
            'company_id' => $companyModel?->id,
            'property_address' => '12-18 Cardigan Road, Leeds',
            'postcode' => 'LS6 1LJ',
            'region' => 'Yorkshire and The Humber',
            'estimated_unit_count' => 4,
            'unit_count_source' => 'address',
            'epc_enriched_at' => null,
            'epc_match_confidence' => null,
            'epc_certificate_count' => 0,
            'epc_property_type' => null,
        ], $title));

        return Candidate::factory()->create(array_merge([
            'title_id' => $titleModel->id,
            'stage' => PipelineStage::New,
            'score' => 50,
            'estimated_units' => $titleModel->estimated_unit_count,
            'is_archived' => false,
        ], $candidate));
    }

    /** Title state for a building with trustworthy EPC evidence. */
    private function withEpc(int $certificates, string $propertyType = 'Flat'): array
    {
        return [
            'epc_enriched_at' => now(),
            'epc_match_confidence' => EpcMatchConfidence::High,
            'epc_match_method' => EpcMatchMethod::ExactAddress,
            'epc_certificate_count' => $certificates,
            'epc_property_type' => $propertyType,
            'estimated_unit_count' => $certificates,
            'unit_count_source' => 'epc',
        ];
    }

    /** @return array<int, int> The candidate ids the endpoint returned, in order. */
    private function ids(array $query = []): array
    {
        $response = $this->getJson(route('candidates.index', $query));

        $response->assertOk();

        return array_column($response->json('data'), 'id');
    }

    // ----------------------------------------------------------------- score

    #[Test]
    public function it_filters_on_a_score_range(): void
    {
        $low = $this->candidate(['score' => 20]);
        $middle = $this->candidate(['score' => 55]);
        $high = $this->candidate(['score' => 90]);

        $this->assertSame([$high->id, $middle->id], $this->ids(['min_score' => 55]));
        $this->assertSame([$middle->id, $low->id], $this->ids(['max_score' => 55]));
        $this->assertSame([$middle->id], $this->ids(['min_score' => 40, 'max_score' => 60]));
    }

    #[Test]
    public function a_max_score_below_the_min_is_rejected(): void
    {
        $this->getJson(route('candidates.index', ['min_score' => 80, 'max_score' => 20]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_score');
    }

    // ----------------------------------------------------------------- stage

    #[Test]
    public function it_filters_on_the_pipeline_stage(): void
    {
        $new = $this->candidate(['stage' => PipelineStage::New]);
        $this->candidate(['stage' => PipelineStage::Outreach]);

        $this->assertSame([$new->id], $this->ids(['stage' => 'new']));
    }

    #[Test]
    public function an_unknown_stage_is_rejected_rather_than_ignored(): void
    {
        $this->candidate();

        $this->getJson(route('candidates.index', ['stage' => 'nonsense']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('stage');
    }

    // -------------------------------------------------------------- location

    #[Test]
    public function it_filters_on_region_and_postcode_area(): void
    {
        $leeds = $this->candidate([], ['postcode' => 'LS6 1LJ', 'region' => 'Yorkshire and The Humber']);
        $manchester = $this->candidate([], ['postcode' => 'M14 5TP', 'region' => 'North West']);

        $this->assertSame([$manchester->id], $this->ids(['region' => 'North West']));
        $this->assertSame([$leeds->id], $this->ids(['postcode_area' => 'LS']));
        $this->assertSame([$manchester->id], $this->ids(['postcode_area' => 'm']));
    }

    #[Test]
    public function the_postcode_area_must_be_the_letters_only(): void
    {
        $this->getJson(route('candidates.index', ['postcode_area' => 'LS6 1LJ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('postcode_area');
    }

    // ---------------------------------------------------------------- search

    #[Test]
    public function search_covers_the_address_title_number_postcode_and_company(): void
    {
        $candidate = $this->candidate(
            [],
            ['property_address' => 'Acacia House, 4 Elm Grove', 'title_number' => 'WYK123456', 'postcode' => 'LS6 1LJ'],
            ['name' => 'Peregrine Estates Limited', 'company_number' => '09876543'],
        );

        $this->candidate([], [
            'property_address' => 'Somewhere else entirely',
            'title_number' => 'MAN999999',
            'postcode' => 'M14 5TP',
        ]);

        $this->assertSame([$candidate->id], $this->ids(['search' => 'Acacia']));
        $this->assertSame([$candidate->id], $this->ids(['search' => 'WYK1234']));
        $this->assertSame([$candidate->id], $this->ids(['search' => 'LS6']));
        $this->assertSame([$candidate->id], $this->ids(['search' => 'Peregrine']));
        $this->assertSame([$candidate->id], $this->ids(['search' => '0987654']));
    }

    #[Test]
    public function search_does_not_leak_past_the_other_filters(): void
    {
        $this->candidate(['score' => 10], ['property_address' => 'Acacia House, 4 Elm Grove']);
        $wanted = $this->candidate(['score' => 90], ['property_address' => 'Acacia Court, 9 Oak Rise']);

        $this->assertSame([$wanted->id], $this->ids(['search' => 'Acacia', 'min_score' => 50]));
    }

    // ------------------------------------------------------------------- EPC

    #[Test]
    public function has_epc_requires_a_match_worth_trusting(): void
    {
        $matched = $this->candidate([], $this->withEpc(6));
        $unmatched = $this->candidate();
        // A postcode-only match attaches the neighbours' certificates, so it
        // is not evidence about this building.
        $weak = $this->candidate([], [
            'epc_enriched_at' => now(),
            'epc_match_confidence' => EpcMatchConfidence::Low,
            'epc_certificate_count' => 9,
        ]);

        $this->assertSame([$matched->id], $this->ids(['has_epc' => 'true']));

        $without = $this->ids(['has_epc' => 'false']);
        sort($without);
        $expected = [$unmatched->id, $weak->id];
        sort($expected);

        $this->assertSame($expected, $without);
    }

    #[Test]
    public function it_filters_on_the_number_of_matched_certificates(): void
    {
        $many = $this->candidate([], $this->withEpc(8));
        $this->candidate([], $this->withEpc(2));

        $this->assertSame([$many->id], $this->ids(['min_epc_certificates' => 4]));
    }

    // ----------------------------------------------------------------- units

    #[Test]
    public function min_units_prefers_the_epc_count_over_the_address_estimate(): void
    {
        // The address reads as a pair of houses; the EPCs found nine flats.
        $block = $this->candidate(
            ['estimated_units' => 2],
            array_merge($this->withEpc(9), ['property_address' => '23-25 Joshua Drive']),
        );

        $this->candidate(['estimated_units' => 2], ['estimated_unit_count' => 2, 'unit_count_source' => 'address']);

        $this->assertSame([$block->id], $this->ids(['min_units' => 4]));
    }

    #[Test]
    public function min_units_respects_a_manual_override_on_the_candidate(): void
    {
        // A user counted the flats by hand. The address could not be parsed.
        $overridden = $this->candidate(
            ['estimated_units' => 12],
            ['estimated_unit_count' => null, 'unit_count_source' => null],
        );

        $this->assertSame([$overridden->id], $this->ids(['min_units' => 4]));
    }

    #[Test]
    public function unknown_unit_counts_are_excluded_unless_asked_for(): void
    {
        $known = $this->candidate(['estimated_units' => 6], ['estimated_unit_count' => 6]);
        $unknown = $this->candidate(['estimated_units' => null], ['estimated_unit_count' => null]);

        $this->assertSame([$known->id], $this->ids(['min_units' => 4]));

        $both = $this->ids(['min_units' => 4, 'include_unknown_units' => 'true']);
        sort($both);
        $expected = [$known->id, $unknown->id];
        sort($expected);

        $this->assertSame($expected, $both);
    }

    // --------------------------------------------------------------- company

    #[Test]
    public function company_distressed_matches_any_single_signal(): void
    {
        $clean = $this->candidate();
        $overdueAccounts = $this->candidate([], [], ['accounts_overdue' => true]);
        $overdueStatement = $this->candidate([], [], ['confirmation_statement_overdue' => true]);
        $inAdministration = $this->candidate([], [], ['status' => 'administration']);
        $dissolved = $this->candidate([], [], ['status' => 'dissolved']);
        $priorInsolvency = $this->candidate([], [], ['has_insolvency_history' => true]);
        $noCompany = $this->candidate([], [], false);

        $distressed = $this->ids(['company_distressed' => 'true']);
        sort($distressed);

        $expected = [
            $overdueAccounts->id,
            $overdueStatement->id,
            $inAdministration->id,
            $dissolved->id,
            $priorInsolvency->id,
        ];
        sort($expected);

        $this->assertSame($expected, $distressed);
        $this->assertNotContains($clean->id, $distressed);
        $this->assertNotContains($noCompany->id, $distressed);
    }

    #[Test]
    public function the_distress_filter_and_the_displayed_signals_agree(): void
    {
        $this->candidate([], [], ['accounts_overdue' => true, 'status' => 'liquidation']);

        $company = $this->getJson(route('candidates.index', ['company_distressed' => 'true']))
            ->assertOk()
            ->json('data.0.title.company');

        $this->assertTrue($company['has_distress_signals']);
        $this->assertSame(['accounts overdue', 'liquidation'], $company['distress_signals']);
    }

    #[Test]
    public function it_filters_on_registered_charges(): void
    {
        $secured = $this->candidate([], [], ['has_charges' => true, 'charges_count' => 3]);
        $unsecured = $this->candidate();
        $noCompany = $this->candidate([], [], false);

        $this->assertSame([$secured->id], $this->ids(['has_charges' => 'true']));

        // Yes and no between them must account for every candidate, including
        // the ones whose proprietor never resolved.
        $without = $this->ids(['has_charges' => 'false']);
        sort($without);
        $expected = [$unsecured->id, $noCompany->id];
        sort($expected);

        $this->assertSame($expected, $without);
    }

    #[Test]
    public function a_candidate_whose_proprietor_never_resolved_is_still_listed(): void
    {
        $orphan = $this->candidate([], [], false);

        $this->assertSame([$orphan->id], $this->ids());
    }

    // ------------------------------------------------------- MUFB confidence

    #[Test]
    public function mufb_confidence_rewards_epc_evidence_of_flats(): void
    {
        $block = $this->candidate([], array_merge($this->withEpc(9, 'Flat'), [
            'property_address' => 'Flats 1-9 Acacia House, Elm Grove',
        ]));

        $terrace = $this->candidate(['estimated_units' => 2], [
            'property_address' => '23-25 Joshua Drive',
            'estimated_unit_count' => 2,
            'unit_count_source' => 'address',
        ]);

        $rows = collect($this->getJson(route('candidates.index'))->json('data'))->keyBy('id');

        // 40 multiple certificates + 25 flat type + 20 units + 15 address.
        $this->assertSame(100, $rows[$block->id]['mufb']['confidence']);
        $this->assertSame('high', $rows[$block->id]['mufb']['level']);
        $this->assertContains('9 matched EPCs', $rows[$block->id]['mufb']['signals']);

        $this->assertSame(0, $rows[$terrace->id]['mufb']['confidence']);
        $this->assertSame('low', $rows[$terrace->id]['mufb']['level']);
        $this->assertSame([], $rows[$terrace->id]['mufb']['signals']);
    }

    #[Test]
    public function min_mufb_accepts_a_number_or_a_band_name(): void
    {
        $block = $this->candidate([], array_merge($this->withEpc(9, 'Flat'), [
            'property_address' => 'Flats 1-9 Acacia House',
        ]));

        // Address keyword only: 15 points.
        $weak = $this->candidate(['estimated_units' => 2], [
            'property_address' => 'Flat over the shop, 3 High Street',
            'estimated_unit_count' => 2,
            'unit_count_source' => 'address',
        ]);

        $this->assertSame([$block->id], $this->ids(['min_mufb' => 'high']));
        $this->assertSame([$block->id], $this->ids(['min_mufb' => '50']));

        $both = $this->ids(['min_mufb' => '10']);
        sort($both);
        $expected = [$block->id, $weak->id];
        sort($expected);

        $this->assertSame($expected, $both);
    }

    #[Test]
    public function the_filter_and_the_displayed_confidence_never_disagree(): void
    {
        // A spread that exercises every component in both implementations.
        $this->candidate([], array_merge($this->withEpc(9, 'Flat'), ['property_address' => 'Flats 1-9 Acacia House']));
        $this->candidate([], array_merge($this->withEpc(6, 'House'), ['property_address' => '1-6 Elm Terrace']));
        $this->candidate([], $this->withEpc(2, 'Maisonette'));
        $this->candidate(['estimated_units' => 2], ['estimated_unit_count' => 2, 'property_address' => 'Land at Oak Rise']);
        $this->candidate(['estimated_units' => null], ['estimated_unit_count' => null, 'property_address' => 'Apartment block, Quay Street']);

        $rows = $this->getJson(route('candidates.index'))->assertOk()->json('data');

        foreach ($rows as $row) {
            $confidence = $row['mufb']['confidence'];

            $this->assertContains(
                $row['id'],
                $this->ids(['min_mufb' => (string) $confidence]),
                "Candidate {$row['id']} shows {$confidence} but is filtered out at that threshold."
            );

            if ($confidence < 100) {
                $this->assertNotContains(
                    $row['id'],
                    $this->ids(['min_mufb' => (string) ($confidence + 1)]),
                    "Candidate {$row['id']} shows {$confidence} but survives a higher threshold."
                );
            }
        }
    }

    // ----------------------------------------------------------------- sorts

    #[Test]
    public function it_sorts_by_units_using_the_best_available_count(): void
    {
        $nine = $this->candidate(['estimated_units' => 2], $this->withEpc(9));
        $five = $this->candidate(['estimated_units' => 5], ['estimated_unit_count' => 5]);

        $this->assertSame([$nine->id, $five->id], $this->ids(['sort' => 'units']));
        $this->assertSame([$five->id, $nine->id], $this->ids(['sort' => 'units', 'direction' => 'asc']));
    }

    #[Test]
    public function it_sorts_by_certificate_count_and_by_mufb_confidence(): void
    {
        $strong = $this->candidate([], array_merge($this->withEpc(9, 'Flat'), [
            'property_address' => 'Flats 1-9 Acacia House',
        ]));
        $weak = $this->candidate(['estimated_units' => 2], [
            'property_address' => '23-25 Joshua Drive',
            'estimated_unit_count' => 2,
        ]);

        $this->assertSame([$strong->id, $weak->id], $this->ids(['sort' => 'epc_certificate_count']));
        $this->assertSame([$strong->id, $weak->id], $this->ids(['sort' => 'mufb']));
        $this->assertSame([$weak->id, $strong->id], $this->ids(['sort' => 'mufb', 'direction' => 'asc']));
    }

    #[Test]
    public function an_unknown_sort_is_rejected(): void
    {
        $this->getJson(route('candidates.index', ['sort' => 'company_name']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    // -------------------------------------------------------------- the rest

    #[Test]
    public function filters_combine_rather_than_replace_one_another(): void
    {
        $wanted = $this->candidate(
            ['score' => 80, 'stage' => PipelineStage::New],
            array_merge($this->withEpc(9, 'Flat'), ['region' => 'North West', 'postcode' => 'M14 5TP']),
            ['accounts_overdue' => true, 'has_charges' => true],
        );

        // Each of these misses on exactly one condition.
        $this->candidate(['score' => 20], array_merge($this->withEpc(9), ['region' => 'North West', 'postcode' => 'M14 5TP']), ['accounts_overdue' => true, 'has_charges' => true]);
        $this->candidate(['score' => 80], array_merge($this->withEpc(9), ['region' => 'Wales', 'postcode' => 'CF10 1AA']), ['accounts_overdue' => true, 'has_charges' => true]);
        $this->candidate(['score' => 80], ['region' => 'North West', 'postcode' => 'M14 5TP'], ['accounts_overdue' => true, 'has_charges' => true]);
        $this->candidate(['score' => 80], array_merge($this->withEpc(9), ['region' => 'North West', 'postcode' => 'M14 5TP']), ['has_charges' => true]);
        $this->candidate(['score' => 80], array_merge($this->withEpc(9), ['region' => 'North West', 'postcode' => 'M14 5TP']), ['accounts_overdue' => true]);

        $this->assertSame([$wanted->id], $this->ids([
            'min_score' => 50,
            'stage' => 'new',
            'region' => 'North West',
            'postcode_area' => 'M',
            'has_epc' => 'true',
            'min_units' => 4,
            'company_distressed' => 'true',
            'has_charges' => 'true',
        ]));
    }

    #[Test]
    public function archived_candidates_stay_out_unless_asked_for(): void
    {
        $live = $this->candidate();
        $archived = $this->candidate(['is_archived' => true]);

        $this->assertSame([$live->id], $this->ids());
        $this->assertSame([$archived->id], $this->ids(['archived' => 'true']));
    }

    #[Test]
    public function the_join_to_titles_cannot_duplicate_a_candidate(): void
    {
        $this->candidate([], $this->withEpc(9));

        $response = $this->getJson(route('candidates.index'))->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function each_row_carries_the_signals_the_list_displays(): void
    {
        $this->candidate(
            ['score' => 72, 'stage' => PipelineStage::Outreach],
            array_merge($this->withEpc(9, 'Flat'), ['epc_current_rating' => 'F']),
            ['accounts_overdue' => true],
        );

        $row = $this->getJson(route('candidates.index'))->assertOk()->json('data.0');

        $this->assertSame(72, $row['score']);
        $this->assertSame('outreach', $row['stage']);
        $this->assertSame(9, $row['units']);
        $this->assertSame('epc', $row['units_source']);
        $this->assertSame(9, $row['title']['epc']['certificate_count']);
        $this->assertSame('F', $row['title']['epc']['current_rating']);
        $this->assertSame(['accounts overdue'], $row['title']['company']['distress_signals']);
        $this->assertSame('high', $row['mufb']['level']);
    }

    #[Test]
    public function an_address_derived_count_is_labelled_as_an_estimate(): void
    {
        $this->candidate(['estimated_units' => 4], ['estimated_unit_count' => 4, 'unit_count_source' => 'address']);

        $row = $this->getJson(route('candidates.index'))->assertOk()->json('data.0');

        $this->assertSame(4, $row['units']);
        $this->assertSame('estimate', $row['units_source']);
    }

    #[Test]
    public function meta_publishes_the_default_preset_the_page_lands_on(): void
    {
        $defaults = $this->getJson(route('meta'))->assertOk()->json('data.candidate_defaults');

        $this->assertTrue($defaults['has_epc']);
        $this->assertSame(4, $defaults['min_units']);
        $this->assertFalse($defaults['include_unknown_units']);
    }

    #[Test]
    public function the_default_preset_isolates_likely_blocks(): void
    {
        $block = $this->candidate([], $this->withEpc(9, 'Flat'));
        $this->candidate([], ['estimated_unit_count' => 2], []);
        $this->candidate(['estimated_units' => null], ['estimated_unit_count' => null]);

        $defaults = $this->getJson(route('meta'))->json('data.candidate_defaults');

        $this->assertSame([$block->id], $this->ids([
            'has_epc' => $defaults['has_epc'] ? 'true' : 'false',
            'min_units' => $defaults['min_units'],
            'include_unknown_units' => $defaults['include_unknown_units'] ? 'true' : 'false',
        ]));
    }

    #[Test]
    public function filter_options_come_from_the_candidate_population_only(): void
    {
        $this->candidate([], ['region' => 'NORTH WEST', 'postcode' => 'M14 5TP']);
        $this->candidate([], ['region' => 'GREATER LONDON', 'postcode' => 'SE10 9FA']);

        // A title with no candidate must not contribute an option.
        Title::factory()->create(['region' => 'WALES', 'postcode' => 'CF10 1AA']);

        $options = $this->getJson(route('candidates.filter-options'))->assertOk()->json('data');

        $this->assertSame(['GREATER LONDON', 'NORTH WEST'], $options['regions']);
        $this->assertSame(['M', 'SE'], $options['postcode_areas']);
    }

    #[Test]
    public function the_list_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson(route('candidates.index'))->assertUnauthorized();
    }
}
