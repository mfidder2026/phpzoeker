<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('job_title');
            $table->jsonb('cv_titles')->nullable();
            $table->string('seniority', 16);
            $table->string('email')->nullable();
            $table->text('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('postcode', 16)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->text('birth_date')->nullable();
            $table->boolean('driving_license')->default(false);
            $table->boolean('own_transport')->nullable();
            $table->string('nationality')->nullable();
            $table->date('available_from')->nullable();
            $table->unsignedSmallInteger('hours_per_week')->default(40);
            $table->decimal('hourly_rate', 8, 2);
            $table->decimal('min_hourly_rate', 8, 2);
            $table->string('rate_note')->default('excl. btw');
            $table->jsonb('languages')->nullable();
            $table->string('status', 24)->default('beschikbaar')->index();
            $table->boolean('cc_self_on_offers')->default(false);
            $table->string('photo_path')->nullable();
            $table->unsignedBigInteger('active_profile_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('candidate_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('vakantie');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_search_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('roles')->nullable();
            $table->jsonb('include_keywords')->nullable();
            $table->jsonb('exclude_keywords')->nullable();
            $table->jsonb('preferred_tech')->nullable();
            $table->jsonb('excluded_tech')->nullable();
            $table->unsignedSmallInteger('min_hours')->default(24);
            $table->unsignedSmallInteger('max_travel_minutes')->nullable();
            $table->jsonb('accepted_contract_types')->nullable();
            $table->jsonb('regions')->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('data');
            $table->string('source_file_path')->nullable();
            $table->jsonb('skills_vocabulary')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->timestamps();

            $table->unique(['candidate_id', 'version']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('candidate_id')->nullable()->after('email')->constrained()->nullOnDelete();
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('candidate_id');
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
        Schema::dropIfExists('candidate_profiles');
        Schema::dropIfExists('candidate_search_profiles');
        Schema::dropIfExists('candidate_absences');
        Schema::dropIfExists('candidates');
    }
};
