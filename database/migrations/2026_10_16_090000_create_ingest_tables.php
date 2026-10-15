<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2: bronnen, ingest en opdrachten (§8, §9, §17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('type', 16)->default('eindklant');
            $table->string('website')->nullable();
            $table->jsonb('email_domains')->nullable();
            $table->unsignedSmallInteger('default_response_term_days')->nullable();
            $table->decimal('measured_avg_response_days', 5, 1)->nullable();
            $table->unsignedSmallInteger('measured_sample_size')->default(0);
            $table->string('supplier_status')->nullable();
            $table->string('preferred_channel', 16)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('email')->nullable()->index();
            $table->text('phone')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('type', 24);
            $table->string('base_url')->nullable();
            $table->text('config')->nullable();
            $table->boolean('enabled')->default(false)->index();
            $table->unsignedSmallInteger('poll_interval_minutes')->default(60);
            $table->string('access_method')->nullable();
            $table->timestamp('tos_reviewed_at')->nullable();
            $table->text('tos_notes')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('default_response_term_days')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_new_posting_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('health_warning')->nullable();
            $table->timestamps();
        });

        Schema::create('source_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->boolean('dry_run')->default(false);
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('log')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'started_at']);
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('dedupe_group_id')->index();
            $table->string('title');
            $table->string('client_name')->nullable();
            $table->string('intermediary_name')->nullable();
            $table->foreignId('client_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('intermediary_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('reference')->nullable()->index();
            $table->longText('description_text')->nullable();
            $table->text('summary')->nullable();
            $table->string('language', 2)->default('nl');
            $table->string('location_text')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country', 2)->default('NL');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('work_mode', 16)->default('onbekend');
            $table->decimal('onsite_days_per_week', 3, 1)->nullable();
            $table->unsignedSmallInteger('hours_min')->nullable();
            $table->unsignedSmallInteger('hours_max')->nullable();
            $table->date('start_date')->nullable();
            $table->boolean('start_asap')->default(false);
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->string('extension_option')->nullable();
            $table->decimal('max_rate', 8, 2)->nullable();
            $table->boolean('rate_includes_margin')->nullable();
            $table->string('rate_text')->nullable();
            $table->string('contract_type', 16)->default('onbekend');
            $table->boolean('zzp_allowed')->nullable();
            $table->string('sector', 16)->default('onbekend');
            $table->string('seniority', 16)->default('onbekend');
            $table->timestamp('deadline_at')->nullable()->index();
            $table->jsonb('requirements')->nullable();
            $table->jsonb('wishes')->nullable();
            $table->jsonb('technologies')->nullable();
            $table->jsonb('screening')->nullable();
            $table->jsonb('submission')->nullable();
            $table->boolean('is_relevant')->default(true)->index();
            $table->string('relevance_reason')->nullable();
            $table->boolean('is_incomplete')->default(false);
            $table->jsonb('locked_fields')->nullable();
            $table->string('status', 16)->default('nieuw')->index();
            $table->string('ignore_reason')->nullable();
            $table->foreignId('possible_duplicate_of_id')->nullable()->constrained('assignments')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('extraction_model')->nullable();
            $table->string('extraction_prompt_version')->nullable();
            $table->timestamps();
        });

        Schema::create('raw_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('raw_postings')->nullOnDelete();
            $table->string('external_id', 512);
            $table->string('url', 2048)->nullable();
            $table->string('title');
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->jsonb('meta')->nullable();
            $table->string('content_hash', 64)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
        });

        Schema::create('assignment_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raw_posting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 2048)->nullable();
            $table->string('intermediary_name')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('contact_phone')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['assignment_id', 'raw_posting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_sources');
        Schema::dropIfExists('raw_postings');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('source_runs');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('organizations');
    }
};
