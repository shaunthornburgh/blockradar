<?php

use App\Enums\PipelineStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('title_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('stage', 32)->default(PipelineStage::New->value);
            $table->unsignedSmallInteger('score')->default(0);
            $table->json('score_breakdown')->nullable();
            $table->timestamp('scored_at')->nullable();

            $table->unsignedSmallInteger('estimated_units')->nullable();
            // Money in pence. Uplift can be negative, so it is signed.
            $table->unsignedBigInteger('estimated_gdv')->nullable();
            $table->bigInteger('estimated_uplift')->nullable();
            $table->decimal('gross_yield', 5, 2)->nullable();

            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_action_at')->nullable();

            // Stamped the first time the candidate reaches each stage.
            $table->timestamp('title_bought_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('outreach_at')->nullable();
            $table->timestamp('offered_at')->nullable();

            $table->boolean('is_archived')->default(false);

            $table->timestamps();

            $table->index(['stage', 'score']);
            $table->index('score');
            $table->index('is_archived');
            $table->index('next_action_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
