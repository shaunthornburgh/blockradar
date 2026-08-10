<?php

use App\Enums\ImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ccod_imports', function (Blueprint $table) {
            $table->id();

            $table->string('filename');
            // First of the month the dataset covers, e.g. 2026-07-01.
            $table->date('period');
            $table->string('checksum', 64)->nullable();
            $table->string('status', 20)->default(ImportStatus::Pending->value);

            $table->unsignedBigInteger('rows_total')->default(0);
            $table->unsignedBigInteger('rows_imported')->default(0);
            $table->unsignedBigInteger('rows_skipped')->default(0);
            $table->unsignedBigInteger('rows_failed')->default(0);
            $table->unsignedBigInteger('titles_created')->default(0);
            $table->unsignedBigInteger('titles_updated')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['period', 'status']);
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ccod_imports');
    }
};
