<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matches (§17): één rij per opdracht × kandidaat, met de uitkomst van prefilter, harde
 * filters, AI-beoordeling en score-opbouw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->boolean('prefilter_passed')->default(false);
            $table->unsignedSmallInteger('prefilter_score')->default(0);
            $table->jsonb('prefilter_reasons')->nullable();
            $table->boolean('hard_filter_passed')->default(false)->index();
            $table->jsonb('filter_results')->nullable();
            $table->unsignedSmallInteger('score')->default(0)->index();
            $table->jsonb('score_breakdown')->nullable();
            $table->jsonb('assessment')->nullable();
            $table->string('assessment_status', 16)->default('niet_nodig');
            $table->string('input_hash', 64)->nullable();
            $table->string('label', 16)->default('zwak')->index();
            $table->string('recommendation', 16)->nullable();
            $table->jsonb('warnings')->nullable();
            $table->boolean('knockout_failed')->default(false);
            $table->foreignId('knockout_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('knockout_override_reason')->nullable();
            $table->string('status', 16)->default('nieuw')->index();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->string('feedback', 8)->nullable();
            $table->string('feedback_reason')->nullable();
            $table->foreignId('feedback_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('feedback_at')->nullable();
            $table->string('candidate_interest', 8)->nullable();
            $table->string('candidate_interest_reason')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->unsignedInteger('profile_version')->nullable();
            $table->uuid('ai_run_id')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('digested_at')->nullable();
            $table->timestamp('deadline_reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
