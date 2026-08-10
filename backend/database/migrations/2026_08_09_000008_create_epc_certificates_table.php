<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domestic Energy Performance Certificates.
 *
 * One row per certificate, which means one row per *dwelling* — a block of
 * eight flats produces eight rows. That granularity is the point: the number
 * of certificates sharing a building is the strongest signal available that a
 * title really is multi-unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epc_certificates', function (Blueprint $table) {
            $table->id();

            // LMK_KEY in the bulk CSV, certificateNumber from the API.
            $table->string('certificate_number', 80)->unique();

            $table->string('uprn', 12)->nullable();
            $table->string('building_reference_number', 40)->nullable();

            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('address_line_3')->nullable();
            $table->text('address');

            // sha1 of the fully normalised address.
            $table->char('address_hash', 40)->nullable();
            // sha1 of the address with flat/unit designators and locality
            // words removed — this is what identifies the *building*.
            $table->char('building_key_hash', 40)->nullable();

            $table->string('postcode', 12)->nullable();
            $table->string('post_town', 120)->nullable();
            $table->string('council', 120)->nullable();
            $table->string('county', 120)->nullable();

            $table->char('current_energy_rating', 2)->nullable();
            $table->unsignedSmallInteger('current_energy_efficiency')->nullable();
            $table->char('potential_energy_rating', 2)->nullable();
            $table->unsignedSmallInteger('potential_energy_efficiency')->nullable();

            $table->string('property_type', 60)->nullable();
            $table->string('built_form', 60)->nullable();
            $table->decimal('total_floor_area', 10, 2)->nullable();
            $table->unsignedSmallInteger('number_habitable_rooms')->nullable();
            $table->unsignedSmallInteger('number_heated_rooms')->nullable();
            $table->string('construction_age_band', 60)->nullable();

            $table->string('main_fuel', 120)->nullable();
            $table->text('main_heat_description')->nullable();
            $table->string('mains_gas_flag', 3)->nullable();

            // Present on flats; another hint that a building is subdivided.
            $table->string('floor_level', 30)->nullable();
            $table->unsignedSmallInteger('flat_storey_count')->nullable();

            $table->string('tenure', 40)->nullable();
            $table->string('transaction_type', 60)->nullable();

            $table->date('inspection_date')->nullable();
            $table->date('lodgement_date')->nullable();

            // bulk | api
            $table->string('source', 10)->default('bulk');
            $table->json('raw')->nullable();

            $table->timestamps();

            // The matcher always narrows by postcode first, then compares
            // building keys within it.
            $table->index(['postcode', 'building_key_hash'], 'epc_postcode_building_index');
            $table->index('uprn');
            $table->index('address_hash');
            $table->index('lodgement_date');
            $table->index('current_energy_rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epc_certificates');
    }
};
