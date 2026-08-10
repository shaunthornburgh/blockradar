<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_metrics', function (Blueprint $table) {
            $table->id();

            // Outward code only, e.g. "M14".
            $table->string('postcode_district', 8)->unique();
            $table->string('region', 120)->nullable();
            $table->string('county', 120)->nullable();

            // Money in pence.
            $table->unsignedBigInteger('median_price')->nullable();
            $table->unsignedInteger('median_rent_pcm')->nullable();
            $table->decimal('gross_yield', 5, 2)->nullable();
            $table->unsignedInteger('transaction_volume')->nullable();

            $table->string('source', 60)->nullable();
            $table->date('as_of')->nullable();

            $table->timestamps();

            $table->index('gross_yield');
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_metrics');
    }
};
