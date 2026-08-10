<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EPC aggregates on the title.
 *
 * These summarise `title_epc_matches` so filtering, scoring and the dashboard
 * never have to aggregate across the match table at query time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            // Nothing populates this yet: CCOD carries no UPRN. The matcher
            // uses it when present, so an address-to-UPRN source (OS Open
            // UPRN, AddressBase) can be dropped in later for exact matching.
            $table->string('uprn', 12)->nullable()->after('postcode');

            $table->timestamp('epc_enriched_at')->nullable()->after('estimated_unit_count');
            $table->string('epc_match_confidence', 10)->nullable()->after('epc_enriched_at');
            $table->string('epc_match_method', 20)->nullable()->after('epc_match_confidence');
            $table->unsignedSmallInteger('epc_certificate_count')->default(0)->after('epc_match_method');
            $table->foreignId('epc_primary_certificate_id')->nullable()
                ->after('epc_certificate_count')
                ->constrained('epc_certificates')->nullOnDelete();

            // Worst rating across the matched certificates: the block is only
            // as good as its poorest flat, and that is where the upside is.
            $table->char('epc_current_rating', 2)->nullable()->after('epc_primary_certificate_id');
            $table->unsignedSmallInteger('epc_average_energy_efficiency')->nullable()->after('epc_current_rating');

            // Summed across the building.
            $table->decimal('epc_total_floor_area', 10, 2)->nullable()->after('epc_average_energy_efficiency');
            $table->unsignedSmallInteger('epc_habitable_rooms')->nullable()->after('epc_total_floor_area');

            // Taken from the representative certificate.
            $table->string('epc_property_type', 60)->nullable()->after('epc_habitable_rooms');
            $table->string('epc_built_form', 60)->nullable()->after('epc_property_type');
            $table->string('epc_construction_age_band', 60)->nullable()->after('epc_built_form');
            $table->text('epc_main_heating')->nullable()->after('epc_construction_age_band');
            $table->string('epc_uprn', 12)->nullable()->after('epc_main_heating');
            $table->date('epc_latest_lodgement_date')->nullable()->after('epc_uprn');

            // address | epc — which signal produced estimated_unit_count.
            $table->string('unit_count_source', 10)->nullable()->after('epc_latest_lodgement_date');

            $table->index('uprn');
            $table->index('epc_enriched_at');
            $table->index('epc_match_confidence');
            $table->index('epc_certificate_count');
            $table->index('epc_current_rating');
        });
    }

    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table) {
            $table->dropForeign(['epc_primary_certificate_id']);

            $table->dropIndex(['uprn']);
            $table->dropIndex(['epc_enriched_at']);
            $table->dropIndex(['epc_match_confidence']);
            $table->dropIndex(['epc_certificate_count']);
            $table->dropIndex(['epc_current_rating']);

            $table->dropColumn([
                'uprn',
                'epc_enriched_at',
                'epc_match_confidence',
                'epc_match_method',
                'epc_certificate_count',
                'epc_primary_certificate_id',
                'epc_current_rating',
                'epc_average_energy_efficiency',
                'epc_total_floor_area',
                'epc_habitable_rooms',
                'epc_property_type',
                'epc_built_form',
                'epc_construction_age_band',
                'epc_main_heating',
                'epc_uprn',
                'epc_latest_lodgement_date',
                'unit_count_source',
            ]);
        });
    }
};
