<?php

namespace Tests\Feature;

use App\Enums\EpcMatchConfidence;
use App\Enums\EpcMatchMethod;
use App\Enums\PipelineStage;
use App\Enums\Tenure;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\EpcCertificate;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The title detail endpoint: a read-only research view of one CCOD row.
 *
 * Its job is to explain what a title is, who owns it, what the EPC evidence
 * says, and whether it reached the MUFB pipeline — including, when it did not,
 * why. It must stay useful for a title with no company and no EPC match, which
 * is the common case across 3.7M rows.
 */
class TitleDetailApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    private function show(Title $title): array
    {
        return $this->getJson(route('titles.show', $title))
            ->assertOk()
            ->json('data');
    }

    /** A title that passes the pipeline filter on its own merits. */
    private function qualifyingTitle(array $attributes = []): Title
    {
        return Title::factory()->create(array_merge([
            'tenure' => Tenure::Freehold,
            'multiple_address_indicator' => true,
            'property_address' => 'Flats 1-9 Acacia House, Elm Grove, Leeds',
            'estimated_unit_count' => 9,
            'unit_count_source' => 'address',
        ], $attributes));
    }

    // ----------------------------------------------------------- the payload

    #[Test]
    public function it_returns_the_core_ccod_row(): void
    {
        $title = $this->qualifyingTitle([
            'title_number' => 'WYK123456',
            'postcode' => 'LS6 1LJ',
            'district' => 'Leeds',
            'county' => 'West Yorkshire',
            'region' => 'YORKS AND HUMBER',
            'price_paid' => 420_000_00,
            'date_proprietor_added' => '2011-06-15',
            'proprietor_name' => 'PEREGRINE ESTATES LIMITED',
            'proprietorship_category' => 'Limited Company or Public Limited Company',
        ]);

        $data = $this->show($title);

        $this->assertSame('WYK123456', $data['title_number']);
        $this->assertSame('Flats 1-9 Acacia House, Elm Grove, Leeds', $data['property_address']);
        $this->assertSame('freehold', $data['tenure']);
        $this->assertTrue($data['multiple_address_indicator']);
        $this->assertSame('LS6 1LJ', $data['postcode']);
        $this->assertSame('LS6', $data['postcode_district']);
        $this->assertSame('Leeds', $data['district']);
        $this->assertSame('YORKS AND HUMBER', $data['region']);
        $this->assertSame(420_000_00, $data['price_paid']);
        $this->assertSame('2011-06-15', $data['date_proprietor_added']);
        $this->assertSame(9, $data['estimated_unit_count']);
        $this->assertSame('address', $data['unit_count_source']);
        $this->assertSame('PEREGRINE ESTATES LIMITED', $data['proprietor_name']);
    }

    #[Test]
    public function it_returns_the_proprietor_company_with_its_distress_signals(): void
    {
        $company = Company::factory()->create([
            'name' => 'Peregrine Estates Limited',
            'company_number' => '09876543',
            'status' => 'administration',
            'enriched_at' => now(),
            'accounts_overdue' => true,
            'has_charges' => true,
            'charges_count' => 2,
        ]);

        $data = $this->show($this->qualifyingTitle(['company_id' => $company->id]));

        $this->assertSame('Peregrine Estates Limited', $data['company']['name']);
        $this->assertSame('09876543', $data['company']['company_number']);
        $this->assertTrue($data['company']['is_enriched']);
        $this->assertTrue($data['company']['is_distressed']);
        $this->assertSame(['accounts overdue', 'administration'], $data['company']['distress_signals']);
        $this->assertSame(2, $data['company']['charges_count']);
    }

    #[Test]
    public function it_returns_the_epc_aggregates_and_the_matched_certificates(): void
    {
        $title = $this->qualifyingTitle([
            'epc_enriched_at' => now(),
            'epc_match_confidence' => EpcMatchConfidence::High,
            'epc_match_method' => EpcMatchMethod::ExactAddress,
            'epc_certificate_count' => 3,
            'epc_current_rating' => 'F',
            'epc_average_energy_efficiency' => 38,
            'epc_total_floor_area' => 210.5,
            'epc_habitable_rooms' => 9,
            'epc_property_type' => 'Flat',
        ]);

        foreach ([1, 2, 3] as $index) {
            $certificate = EpcCertificate::factory()->create([
                'lodgement_date' => now()->subYears($index),
            ]);

            $title->epcCertificates()->attach($certificate->id, [
                'method' => EpcMatchMethod::ExactAddress->value,
                'confidence' => EpcMatchConfidence::High->value,
                'is_primary' => $index === 1,
            ]);
        }

        $data = $this->show($title);

        $this->assertTrue($data['epc']['is_usable']);
        $this->assertSame('high', $data['epc']['match_confidence']);
        $this->assertSame(3, $data['epc']['certificate_count']);
        $this->assertSame('F', $data['epc']['current_rating']);
        $this->assertSame(210.5, $data['epc']['total_floor_area']);

        $this->assertCount(3, $data['epc_certificates']);
        $this->assertTrue($data['epc_certificates'][0]['match']['is_primary'], 'Freshest lodgement first.');
        $this->assertSame('high', $data['epc_certificates'][0]['match']['confidence']);
    }

    // --------------------------------------------------------- pipeline link

    #[Test]
    public function a_title_in_the_pipeline_links_to_its_candidate(): void
    {
        $title = $this->qualifyingTitle([
            'epc_match_confidence' => EpcMatchConfidence::High,
            'epc_certificate_count' => 9,
            'epc_property_type' => 'Flat',
            'unit_count_source' => 'epc',
        ]);

        $candidate = Candidate::factory()->create([
            'title_id' => $title->id,
            'stage' => PipelineStage::Outreach,
            'score' => 78,
            'is_archived' => false,
        ]);

        $data = $this->show($title);

        $this->assertTrue($data['pipeline']['is_candidate']);
        $this->assertTrue($data['pipeline']['qualifies_now']);
        $this->assertNull($data['pipeline']['reason']);

        $this->assertSame($candidate->id, $data['candidate']['id']);
        $this->assertSame('outreach', $data['candidate']['stage']);
        $this->assertSame('Outreach', $data['candidate']['stage_label']);
        $this->assertSame(78, $data['candidate']['score']);
        $this->assertFalse($data['candidate']['is_archived']);

        // The candidate summary reports MUFB confidence against this title.
        $this->assertSame('high', $data['candidate']['mufb']['level']);
        $this->assertContains('9 matched EPCs', $data['candidate']['mufb']['signals']);
    }

    #[Test]
    public function the_candidate_summary_does_not_nest_the_title_back_into_itself(): void
    {
        $title = $this->qualifyingTitle();
        Candidate::factory()->create(['title_id' => $title->id]);

        $this->assertArrayNotHasKey('title', $this->show($title)['candidate']);
    }

    /**
     * Every reason the filter can give, checked against the endpoint rather
     * than the filter, so the wiring is covered too.
     */
    #[Test]
    public function a_title_outside_the_pipeline_explains_itself(): void
    {
        $cases = [
            'not_freehold' => $this->qualifyingTitle(['tenure' => Tenure::Leasehold]),
            'single_address' => $this->qualifyingTitle(['multiple_address_indicator' => false]),
            'excluded_address' => $this->qualifyingTitle(['property_address' => 'Land at Elm Grove, Leeds']),
            'epc_single_dwelling' => $this->qualifyingTitle([
                'epc_match_confidence' => EpcMatchConfidence::High,
                'epc_certificate_count' => 1,
                'epc_property_type' => 'House',
            ]),
        ];

        foreach ($cases as $expected => $title) {
            $pipeline = $this->show($title)['pipeline'];

            $this->assertFalse($pipeline['is_candidate'], "{$expected}: should not be a candidate.");
            $this->assertFalse($pipeline['qualifies_now'], "{$expected}: should not qualify.");
            $this->assertSame($expected, $pipeline['reason']);
            $this->assertNotEmpty($pipeline['reason_label'], "{$expected}: needs a human explanation.");
        }

        $this->assertNull($this->show($cases['not_freehold'])['candidate']);
    }

    #[Test]
    public function the_minimum_unit_count_is_reported_as_a_reason(): void
    {
        config(['blockradar.candidate_filters.minimum_estimated_units' => 4]);

        $pipeline = $this->show($this->qualifyingTitle(['estimated_unit_count' => 2]))['pipeline'];

        $this->assertSame('below_minimum_units', $pipeline['reason']);
    }

    #[Test]
    public function a_qualifying_title_with_no_candidate_says_so_without_inventing_a_reason(): void
    {
        // Real case: the title was imported under a narrower filter config, or
        // its candidate was deleted. We know it qualifies now and that no
        // candidate exists — and nothing beyond that.
        $pipeline = $this->show($this->qualifyingTitle())['pipeline'];

        $this->assertFalse($pipeline['is_candidate']);
        $this->assertTrue($pipeline['qualifies_now']);
        $this->assertNull($pipeline['reason']);
        $this->assertNull($pipeline['reason_label']);
    }

    #[Test]
    public function the_reason_reflects_the_filter_config_in_force_now(): void
    {
        $title = $this->qualifyingTitle(['region' => 'WALES']);

        $this->assertTrue($this->show($title)['pipeline']['qualifies_now']);

        config(['blockradar.candidate_filters.regions' => ['NORTH WEST']]);

        $this->assertSame('outside_target_region', $this->show($title)['pipeline']['reason']);
    }

    // ------------------------------------------------------- sparse payloads

    #[Test]
    public function a_title_with_no_company_and_no_epc_still_returns_a_whole_payload(): void
    {
        $title = Title::factory()->create([
            'company_id' => null,
            'proprietor_name' => 'SOME UNMATCHED PROPRIETOR',
            'postcode' => null,
            'price_paid' => null,
            'date_proprietor_added' => null,
            'estimated_unit_count' => null,
            'unit_count_source' => null,
            'epc_enriched_at' => null,
            'epc_match_confidence' => null,
            'epc_certificate_count' => 0,
        ]);

        $data = $this->show($title);

        $this->assertNull($data['company']);
        $this->assertNull($data['postcode']);
        $this->assertNull($data['postcode_district']);
        $this->assertNull($data['estimated_unit_count']);
        $this->assertSame([], $data['epc_certificates']);
        $this->assertFalse($data['epc']['is_usable']);
        $this->assertSame(0, $data['epc']['certificate_count']);
        $this->assertNull($data['epc']['enriched_at']);
        $this->assertNull($data['candidate']);
        $this->assertSame('SOME UNMATCHED PROPRIETOR', $data['proprietor_name']);
    }

    #[Test]
    public function an_unenriched_company_is_returned_rather_than_omitted(): void
    {
        $company = Company::factory()->create([
            'enriched_at' => null,
            'status' => null,
            'accounts_overdue' => null,
        ]);

        $data = $this->show($this->qualifyingTitle(['company_id' => $company->id]));

        $this->assertFalse($data['company']['is_enriched']);
        $this->assertSame([], $data['company']['distress_signals']);
        $this->assertFalse($data['company']['has_distress_signals']);
    }

    // ------------------------------------------------------------- the index

    #[Test]
    public function the_list_flags_which_titles_are_already_candidates(): void
    {
        $promoted = $this->qualifyingTitle();
        Candidate::factory()->create(['title_id' => $promoted->id]);

        $ignored = $this->qualifyingTitle();

        $rows = collect($this->getJson(route('titles.index'))->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$promoted->id]['is_candidate']);
        $this->assertFalse($rows[$ignored->id]['is_candidate']);

        // The list does not carry the pipeline assessment — that is one filter
        // run per row, and the detail page is where it is asked for.
        $this->assertArrayNotHasKey('pipeline', $rows[$promoted->id]);
    }

    #[Test]
    public function the_detail_endpoint_requires_authentication(): void
    {
        $title = $this->qualifyingTitle();

        app('auth')->forgetGuards();

        $this->getJson(route('titles.show', $title))->assertUnauthorized();
    }

    #[Test]
    public function an_unknown_title_is_a_404(): void
    {
        $this->getJson(route('titles.show', 987654))->assertNotFound();
    }
}
