<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(false)->index();
            $table->boolean('is_fallback')->default(false);
            $table->string('provider', 32);
            $table->text('api_key')->nullable();
            $table->string('api_key_last4', 4)->nullable();
            $table->string('base_url')->nullable();
            $table->text('extra_options')->nullable();
            $table->jsonb('models');
            $table->jsonb('max_output_tokens')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(120);
            $table->unsignedSmallInteger('max_concurrency')->default(3);
            $table->decimal('daily_budget_eur', 8, 2)->default(10);
            $table->jsonb('prices')->nullable();
            $table->boolean('web_research_enabled')->default(true);
            $table->jsonb('web_allowed_domains')->nullable();
            $table->string('web_test_url')->nullable();
            $table->timestamp('dpa_confirmed_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->jsonb('last_test_result')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('agent_profile_id')->nullable()->constrained('ai_agent_profiles')->nullOnDelete();
            $table->string('agent', 64);
            $table->string('task', 32);
            $table->string('subject_label')->nullable();
            $table->nullableMorphs('subject');
            $table->foreignId('started_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('queued')->index();
            $table->jsonb('steps')->nullable();
            $table->string('current_step', 64)->nullable();
            $table->unsignedTinyInteger('progress')->nullable();
            $table->string('current_detail')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_eur', 10, 5)->default(0);
            $table->jsonb('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_agent_profiles');
    }
};
