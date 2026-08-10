<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a title to every EPC certificate believed to sit inside that building,
 * recording how the link was made and how much to trust it.
 *
 * Kept as its own table rather than columns on `titles` because the
 * relationship is genuinely one-to-many: the aggregates on `titles` are a
 * summary of these rows, not a replacement for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_epc_matches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('title_id')->constrained()->cascadeOnDelete();
            $table->foreignId('epc_certificate_id')->constrained()->cascadeOnDelete();

            $table->string('method', 20);
            $table->string('confidence', 10);
            // Percentage similarity of the building keys, for fuzzy matches.
            $table->decimal('similarity', 5, 2)->nullable();

            // The representative certificate, currently the most recently
            // lodged one for the building.
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->unique(['title_id', 'epc_certificate_id']);
            $table->index(['title_id', 'confidence']);
            $table->index('epc_certificate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_epc_matches');
    }
};
