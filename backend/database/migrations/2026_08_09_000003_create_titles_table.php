<?php

use App\Enums\Tenure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titles', function (Blueprint $table) {
            $table->id();

            $table->string('title_number', 30)->unique();

            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ccod_import_id')->nullable()->constrained()->nullOnDelete();

            $table->string('tenure', 16)->default(Tenure::Unknown->value);

            $table->text('property_address');
            // sha1 of the normalised address, to spot one building held under
            // several title numbers.
            $table->char('property_address_hash', 40)->nullable();
            $table->string('postcode', 12)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('county', 120)->nullable();
            $table->string('region', 120)->nullable();

            $table->boolean('multiple_address_indicator')->default(false);
            $table->boolean('additional_proprietor_indicator')->default(false);

            // Kept as printed in CCOD; may differ from the matched company name.
            $table->string('proprietor_name')->nullable();
            $table->string('proprietorship_category', 120)->nullable();

            // Stored in pence to avoid float rounding.
            $table->unsignedBigInteger('price_paid')->nullable();
            $table->date('date_proprietor_added')->nullable();

            $table->unsignedSmallInteger('estimated_unit_count')->nullable();

            $table->json('raw')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            // The primary CCOD filter: freehold + multiple address indicator.
            $table->index(['tenure', 'multiple_address_indicator'], 'titles_split_filter_index');
            $table->index('postcode');
            $table->index('region');
            $table->index('district');
            $table->index('property_address_hash');
            $table->index('date_proprietor_added');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titles');
    }
};
