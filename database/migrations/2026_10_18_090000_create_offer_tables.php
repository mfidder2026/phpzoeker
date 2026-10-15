<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aanbiedingen en register (§11, §17): offers, getailorde CV's, uitgaande mails, tijdlijn en
 * onveranderbare snapshots (R8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->jsonb('research')->nullable();
            $table->timestamp('researched_at')->nullable();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->uuid('dedupe_group_id');
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignment_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16)->default('email');
            $table->boolean('is_manual')->default(false);
            $table->string('status', 24)->default('concept')->index();
            $table->string('status_reason')->nullable();
            $table->jsonb('candidate_snapshot')->nullable();
            $table->jsonb('cv_options')->nullable();
            $table->decimal('offered_rate', 8, 2)->nullable();
            $table->string('consent_status', 16)->default('niet_nodig');
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_note')->nullable();
            $table->string('external_reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable()->index();
            $table->unsignedSmallInteger('response_term_days')->nullable();
            $table->string('response_term_source', 16)->nullable();
            $table->string('response_term_override_reason')->nullable();
            $table->timestamp('expected_response_at')->nullable()->index();
            $table->timestamp('response_received_at')->nullable();
            $table->decimal('response_time_workdays', 5, 1)->nullable();
            $table->unsignedSmallInteger('follow_up_count')->default(0);
            $table->timestamp('last_follow_up_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamp('intake_at')->nullable();
            $table->jsonb('intake')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->integer('match_score')->nullable();
            $table->timestamps();

            $table->index('candidate_id');
            $table->index('organization_id');
        });

        // R7: één actieve aanbieding per kandidaat en dedupe-groep (actief = alles behalve ingetrokken).
        DB::statement("CREATE UNIQUE INDEX offers_active_candidate_group_unique ON offers (candidate_id, dedupe_group_id) WHERE status <> 'ingetrokken'");

        Schema::create('tailored_cvs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('language', 2)->default('nl');
            $table->unsignedInteger('profile_version')->nullable();
            $table->jsonb('content');
            $table->jsonb('changes')->nullable();
            $table->string('docx_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->jsonb('diff_summary')->nullable();
            $table->jsonb('grounding_report')->nullable();
            $table->boolean('grounding_passed')->default(false);
            $table->boolean('edited')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->uuid('ai_run_id')->nullable();
            $table->timestamps();

            $table->unique(['offer_id', 'version']);
        });

        Schema::create('outbound_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24);
            $table->string('from');
            $table->string('from_name')->nullable();
            $table->jsonb('to');
            $table->jsonb('cc')->nullable();
            $table->jsonb('bcc')->nullable();
            $table->string('subject');
            $table->longText('body_html');
            $table->longText('body_text');
            $table->jsonb('attachments')->nullable();
            $table->jsonb('recipient_origins')->nullable();
            $table->string('status', 16)->default('concept')->index();
            $table->string('send_key', 64)->unique();
            $table->string('provider', 16)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('internet_message_id')->nullable()->index();
            $table->string('conversation_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->longText('raw_mime')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->timestamps();
        });

        Schema::create('offer_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24)->index();
            $table->jsonb('payload')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('offer_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('captured_at');
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 24);
            $table->jsonb('email_headers')->nullable();
            $table->longText('email_html')->nullable();
            $table->longText('email_text')->nullable();
            $table->string('eml_path')->nullable();
            $table->jsonb('attachments')->nullable();
            $table->jsonb('offer_data');
            $table->jsonb('assessment')->nullable();
            $table->jsonb('assignment_data');
            $table->jsonb('provenance')->nullable();
            $table->string('sha256', 64);
            $table->timestamps();
        });

        // R8: de database weigert elke wijziging van een snapshot.
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION offer_snapshots_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'offer_snapshots zijn onveranderbaar (R8)';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER offer_snapshots_no_update BEFORE UPDATE ON offer_snapshots
                    FOR EACH ROW EXECUTE FUNCTION offer_snapshots_immutable();
                SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER offer_snapshots_no_update BEFORE UPDATE ON offer_snapshots BEGIN SELECT RAISE(ABORT, 'offer_snapshots zijn onveranderbaar (R8)'); END;");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS offer_snapshots_no_update ON offer_snapshots; DROP FUNCTION IF EXISTS offer_snapshots_immutable();');
        }

        Schema::dropIfExists('offer_snapshots');
        Schema::dropIfExists('offer_activities');
        Schema::dropIfExists('outbound_emails');
        Schema::dropIfExists('tailored_cvs');
        Schema::dropIfExists('offers');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['research', 'researched_at']);
        });
    }
};
