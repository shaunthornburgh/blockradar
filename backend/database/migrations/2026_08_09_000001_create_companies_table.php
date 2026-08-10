<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            $table->string('company_number', 20)->unique();
            $table->string('name');
            $table->string('status', 40)->nullable();
            $table->string('type', 40)->nullable();
            $table->string('jurisdiction', 60)->nullable();

            $table->date('incorporated_on')->nullable();
            $table->date('dissolved_on')->nullable();

            $table->json('sic_codes')->nullable();
            $table->json('registered_office_address')->nullable();
            $table->string('registered_office_postcode', 12)->nullable();

            $table->unsignedSmallInteger('officer_count')->nullable();
            $table->date('accounts_last_made_up_to')->nullable();
            $table->date('accounts_next_due')->nullable();

            // Existing charges usually mean a lender consent is needed before
            // a title can be split, so it feeds the score.
            $table->boolean('has_charges')->default(false);
            $table->unsignedSmallInteger('charges_count')->nullable();

            $table->timestamp('enriched_at')->nullable();
            $table->json('ch_raw')->nullable();

            $table->timestamps();

            $table->index('name');
            $table->index('status');
            $table->index('registered_office_postcode');
            $table->index('enriched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
