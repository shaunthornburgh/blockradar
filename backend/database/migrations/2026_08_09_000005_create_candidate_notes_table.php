<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // note | stage_change | call | letter | email
            $table->string('type', 24)->default('note');
            $table->text('body');
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['candidate_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_notes');
    }
};
