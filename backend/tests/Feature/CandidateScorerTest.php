<?php

namespace Tests\Feature;

use App\Enums\EpcMatchConfidence;
use App\Enums\EpcMatchMethod;
use App\Models\AreaMetric;
use App\Models\Company;
use App\Models\Title;
use App\Services\Candidates\CandidateScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CandidateScorerTest extends TestCase
{
    use RefreshDatabase;

    private function scorer(): CandidateScorer
    {
        return app(CandidateScorer::class);
    }

    /**
     * A title with a company attached, eager-loaded the way the importer does.
     */
    private function title(array $companyAttributes = [], array $titleAttributes = []): Title
    {
        $company = Company::factory()->create(array_merge([
            'enriched_at' => null,
            'has_charges' => false,
        ], $companyAttributes));

        $title = Title::factory()->create(array_merge([
            'company_id' => $company->id,
            'estimated_unit_count' => 8,
            'price_paid' => 420_000_00,
            'date_proprietor_added' => now()->subYears(10),
            'postcode' => 'M8 8EL',
        ], $titleAttributes));

        return $title->load('company');
    }

    #[Test]
    public function companies_house_components_are_unavailable_until_the_company_is_enriched(): void
    {
        $result = $this->scorer()->score($this->title());

        $this->assertFalse($result->components['filing_distress']['available']);
        $this->assertFalse($result->components['charges_pressure']['available']);
        $this->assertSame(55, $result->weightAvailable);
        $this->assertSame(110, $result->weightTotal);
    }

    #[Test]
    public function an_enriched_company_brings_the_whole_model_online(): void
    {
        AreaMetric::factory()->create([
            'postcode_district' => 'M8',
            'gross_yield' => 9.0,
            'median_price' => 150_000_00,
        ]);

        $result = $this->scorer()->score(
            $this->title(['enriched_at' => now(), 'status' => 'active']),
            AreaMetric::first()
        );

        $this->assertTrue($result->components['filing_distress']['available']);
        $this->assertTrue($result->components['charges_pressure']['available']);
        $this->assertSame(
            100,
            $result->weightAvailable,
            'Everything but the EPC component now has data.'
        );
    }

    #[Test]
    public function an_active_up_to_date_company_scores_neutral_on_filing_distress(): void
    {
        $result = $this->scorer()->score($this->title([
            'enriched_at' => now(),
            'status' => 'active',
            'accounts_overdue' => false,
            'confirmation_statement_overdue' => false,
            'accounts_last_made_up_to' => now()->subMonths(4),
            'has_insolvency_history' => false,
        ]));

        $distress = $result->components['filing_distress'];

        $this->assertTrue($distress['available']);
        $this->assertSame(0.0, $distress['value'], 'Being in good standing is neutral, not a penalty.');
        $this->assertContains('active and filings up to date', $distress['signals']);
    }

    #[Test]
    public function overdue_accounts_and_confirmation_statement_raise_filing_distress(): void
    {
        $result = $this->scorer()->score($this->title([
            'enriched_at' => now(),
            'status' => 'active',
            'accounts_overdue' => true,
            'confirmation_statement_overdue' => true,
            'accounts_last_made_up_to' => now()->subMonths(4),
        ]));

        $distress = $result->components['filing_distress'];

        // 30 for accounts + 25 for the confirmation statement.
        $this->assertSame(0.55, $distress['value']);
        $this->assertContains('accounts overdue', $distress['signals']);
        $this->assertContains('confirmation statement overdue', $distress['signals']);
    }

    #[Test]
    public function stale_accounts_at_a_long_established_company_add_to_distress(): void
    {
        $result = $this->scorer()->score($this->title([
            'enriched_at' => now(),
            'status' => 'active',
            'accounts_overdue' => false,
            'confirmation_statement_overdue' => false,
            'accounts_last_made_up_to' => now()->subYears(3),
            'incorporated_on' => now()->subYears(25),
        ]));

        // 15 for stale accounts + 5 for being long-established.
        $this->assertSame(0.2, $result->components['filing_distress']['value']);
    }

    #[Test]
    public function a_company_in_liquidation_scores_high_on_distress(): void
    {
        $result = $this->scorer()->score($this->title([
            'enriched_at' => now(),
            'status' => 'liquidation',
            'has_insolvency_history' => true,
            'accounts_overdue' => false,
            'confirmation_statement_overdue' => false,
            'accounts_last_made_up_to' => now()->subMonths(2),
        ]));

        $distress = $result->components['filing_distress'];

        $this->assertSame(0.3, $distress['value']);
        $this->assertContains('in liquidation', $distress['signals']);
    }

    #[Test]
    public function a_dissolved_company_is_flagged_as_high_risk_and_capped(): void
    {
        $result = $this->scorer()->score($this->title([
            'enriched_at' => now(),
            'status' => 'dissolved',
            'accounts_overdue' => false,
            'confirmation_statement_overdue' => false,
            'accounts_last_made_up_to' => now()->subMonths(2),
            'has_insolvency_history' => false,
        ]));

        $distress = $result->components['filing_distress'];

        // Motivated but unable to sell, so scored well below a live insolvency.
        $this->assertSame(0.1, $distress['value']);
        $this->assertStringContainsString('bona vacantia', implode(' ', $distress['signals']));
    }

    #[Test]
    public function registered_charges_read_as_pressure(): void
    {
        $withCharges = $this->scorer()->score($this->title([
            'enriched_at' => now(),
            'has_charges' => true,
            'charges_count' => 3,
        ]));

        $withoutCharges = $this->scorer()->score($this->title([
            'enriched_at' => now(),
            'has_charges' => false,
            'charges_count' => 0,
        ]));

        $this->assertSame(1.0, $withCharges->components['charges_pressure']['value']);
        $this->assertSame('3 registered charges', $withCharges->components['charges_pressure']['note']);

        $this->assertSame(0.0, $withoutCharges->components['charges_pressure']['value']);
    }

    #[Test]
    public function a_distressed_owner_outranks_a_healthy_one_on_identical_property(): void
    {
        $healthy = $this->scorer()->score($this->title([
            'enriched_at' => now(),
            'status' => 'active',
            'accounts_overdue' => false,
            'confirmation_statement_overdue' => false,
            'accounts_last_made_up_to' => now()->subMonths(3),
            'has_charges' => false,
        ]));

        $distressed = $this->scorer()->score($this->title([
            'enriched_at' => now(),
            'status' => 'active',
            'accounts_overdue' => true,
            'confirmation_statement_overdue' => true,
            'accounts_last_made_up_to' => now()->subYears(4),
            'incorporated_on' => now()->subYears(22),
            'has_charges' => true,
            'charges_count' => 2,
        ]));

        $this->assertGreaterThan(
            $healthy->score,
            $distressed->score,
            'Motivation signals must move the headline score, not just the breakdown.'
        );
    }

    // ------------------------------------------------------------------ EPC

    /** A title with a trustworthy EPC match already aggregated onto it. */
    private function epcTitle(array $epc = [], array $titleAttributes = []): Title
    {
        return $this->title([], array_merge([
            'epc_enriched_at' => now(),
            'epc_match_confidence' => EpcMatchConfidence::High,
            'epc_match_method' => EpcMatchMethod::ExactAddress,
            'epc_certificate_count' => 3,
        ], $epc, $titleAttributes));
    }

    #[Test]
    public function epc_refurb_potential_is_unavailable_without_a_trustworthy_match(): void
    {
        $result = $this->scorer()->score($this->title());

        $this->assertFalse($result->components['epc_refurb_potential']['available']);

        // A postcode-only match is not enough to score on.
        $lowConfidence = $this->epcTitle([
            'epc_match_confidence' => EpcMatchConfidence::Low,
            'epc_average_energy_efficiency' => 30,
        ]);

        $this->assertFalse(
            $this->scorer()->score($lowConfidence)->components['epc_refurb_potential']['available']
        );
    }

    #[Test]
    public function a_poor_epc_scores_as_refurbishment_upside(): void
    {
        $poor = $this->scorer()->score($this->epcTitle([
            'epc_average_energy_efficiency' => 30,
            'epc_current_rating' => 'F',
        ]));

        $good = $this->scorer()->score($this->epcTitle([
            'epc_average_energy_efficiency' => 80,
            'epc_current_rating' => 'C',
        ]));

        $this->assertSame(1.0, $poor->components['epc_refurb_potential']['value']);
        $this->assertSame(0.0, $good->components['epc_refurb_potential']['value']);
        $this->assertGreaterThan($good->score, $poor->score);
    }

    #[Test]
    public function an_epc_below_the_mees_minimum_is_flagged(): void
    {
        $result = $this->scorer()->score($this->epcTitle([
            'epc_average_energy_efficiency' => 25,
            'epc_current_rating' => 'G',
        ]));

        $signals = $result->components['epc_refurb_potential']['signals'];

        $this->assertStringContainsString('MEES', implode(' ', $signals));
        $this->assertStringContainsString('3 certificates', implode(' ', $signals));
    }

    #[Test]
    public function a_band_alone_is_enough_to_score_when_the_numeric_efficiency_is_missing(): void
    {
        $result = $this->scorer()->score($this->epcTitle([
            'epc_average_energy_efficiency' => null,
            'epc_current_rating' => 'G',
        ]));

        $component = $result->components['epc_refurb_potential'];

        $this->assertTrue($component['available']);
        $this->assertSame(1.0, $component['value']);
        $this->assertContains('estimated from band G', $component['signals']);
    }

    #[Test]
    public function epc_floor_area_drives_split_upside_when_available(): void
    {
        $result = $this->scorer()->score($this->epcTitle([
            'epc_total_floor_area' => 300.00,
            'epc_average_energy_efficiency' => 50,
        ], [
            // 420,000 pounds over 300 sq m is 1,400 per sq m.
            'price_paid' => 420_000_00,
        ]));

        $upside = $result->components['title_split_upside'];

        $this->assertTrue($upside['available']);
        $this->assertContains('floor area from EPC', $upside['signals']);
        $this->assertStringContainsString('per m²', $upside['note']);
        $this->assertEqualsWithDelta(0.93, $upside['value'], 0.01);
    }

    #[Test]
    public function split_upside_falls_back_to_unit_counts_without_epc_floor_area(): void
    {
        $upside = $this->scorer()->score($this->title())->components['title_split_upside'];

        $this->assertTrue($upside['available']);
        $this->assertStringContainsString('per unit', $upside['note']);
    }

    #[Test]
    public function epc_data_brings_the_model_fully_online(): void
    {
        AreaMetric::factory()->create([
            'postcode_district' => 'M8',
            'gross_yield' => 9.0,
            'median_price' => 150_000_00,
        ]);

        $title = $this->title(
            ['enriched_at' => now(), 'status' => 'active'],
            [
                'epc_enriched_at' => now(),
                'epc_match_confidence' => EpcMatchConfidence::High,
                'epc_match_method' => EpcMatchMethod::ExactAddress,
                'epc_certificate_count' => 3,
                'epc_average_energy_efficiency' => 40,
                'epc_current_rating' => 'F',
                'epc_total_floor_area' => 300.00,
            ]
        );

        $result = $this->scorer()->score($title, AreaMetric::first());

        $this->assertSame(110, $result->weightAvailable, 'Every component now has data.');
        $this->assertSame(110, $result->weightTotal);
    }

    #[Test]
    public function a_title_with_no_company_leaves_the_components_unavailable(): void
    {
        $title = Title::factory()->create(['company_id' => null]);

        $result = $this->scorer()->score($title->load('company'));

        $this->assertFalse($result->components['filing_distress']['available']);
        $this->assertSame('Title has no matched company', $result->components['filing_distress']['note']);
    }
}
