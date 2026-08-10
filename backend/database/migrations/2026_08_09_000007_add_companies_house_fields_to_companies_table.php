<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra Companies House fields the scorer needs for motivation signals, plus
 * the bookkeeping that makes enrichment resumable across runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Nullable because "unknown" and "not overdue" are different things
            // before a company has been enriched.
            $table->boolean('accounts_overdue')->nullable()->after('accounts_next_due');
            $table->boolean('confirmation_statement_overdue')->nullable()->after('accounts_overdue');
            $table->date('confirmation_statement_last_made_up_to')->nullable()->after('confirmation_statement_overdue');
            $table->date('confirmation_statement_next_due')->nullable()->after('confirmation_statement_last_made_up_to');
            $table->boolean('has_insolvency_history')->nullable()->after('charges_count');

            // enriched_at stays the "last success" marker. These record what
            // happened on the last attempt, successful or not, so a run can be
            // resumed without re-requesting companies that will never resolve.
            $table->string('enrichment_status', 20)->nullable()->after('enriched_at');
            $table->timestamp('enrichment_attempted_at')->nullable()->after('enrichment_status');
            $table->unsignedSmallInteger('enrichment_attempts')->default(0)->after('enrichment_attempted_at');
            $table->text('enrichment_error')->nullable()->after('enrichment_attempts');

            $table->index('enrichment_status');
            $table->index('enrichment_attempted_at');
            $table->index('accounts_overdue');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['enrichment_status']);
            $table->dropIndex(['enrichment_attempted_at']);
            $table->dropIndex(['accounts_overdue']);

            $table->dropColumn([
                'accounts_overdue',
                'confirmation_statement_overdue',
                'confirmation_statement_last_made_up_to',
                'confirmation_statement_next_due',
                'has_insolvency_history',
                'enrichment_status',
                'enrichment_attempted_at',
                'enrichment_attempts',
                'enrichment_error',
            ]);
        });
    }
};
