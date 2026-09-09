<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 31: Programs, Program Outcomes, CO<->PO mappings, question<->CO mappings and mapping analysis.
 * Existing learning_outcomes act as Course Outcomes (CO); no duplicate CO table is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('department', 120)->nullable();
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, ARCHIVED
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['created_by', 'code'], 'programs_owner_code_unique');
            $table->index('status', 'programs_status_idx');
        });

        Schema::create('program_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['program_id', 'code'], 'po_program_code_unique');
            $table->index(['program_id', 'sort_order'], 'po_program_order_idx');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::create('co_po_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_outcome_id')->constrained('learning_outcomes', 'id', 'copo_lo_fk')->cascadeOnDelete();
            $table->foreignId('program_outcome_id')->constrained('program_outcomes', 'id', 'copo_po_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('mapping_level')->default(0); // 0 NONE, 1 LOW, 2 MEDIUM, 3 HIGH
            $table->text('justification')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['learning_outcome_id', 'program_outcome_id'], 'copo_lo_po_unique');
            $table->index('course_id', 'copo_course_idx');
        });

        Schema::create('question_co_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_outcome_id')->constrained('learning_outcomes', 'id', 'qco_lo_fk')->cascadeOnDelete();
            $table->string('mapping_source', 20)->default('FACULTY'); // FACULTY, AI_SUGGESTED, IMPORTED
            $table->unsignedTinyInteger('mapping_level')->nullable();
            $table->string('status', 20)->default('PENDING'); // PENDING, CONFIRMED, REJECTED
            $table->decimal('similarity_score', 5, 4)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['question_id', 'learning_outcome_id'], 'qco_question_lo_unique');
            $table->index(['learning_outcome_id', 'status'], 'qco_lo_status_idx');
        });

        Schema::create('co_po_mapping_analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('PENDING'); // PENDING, PROCESSING, COMPLETED, FAILED, STALE
            $table->boolean('is_current')->default(true);
            $table->string('mapping_version', 64)->nullable(); // fingerprint of mappings/questions/outcomes used
            $table->json('summary')->nullable();
            $table->json('matrix')->nullable();
            $table->json('co_coverage')->nullable();
            $table->json('po_evidence')->nullable();
            $table->json('thresholds')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'is_current'], 'copo_run_course_current_idx');
        });

        Schema::create('co_po_mapping_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained('co_po_mapping_analysis_runs', 'id', 'copo_find_run_fk')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('severity', 10); // INFO, LOW, MEDIUM, HIGH
            $table->string('title');
            $table->text('description');
            $table->text('recommendation')->nullable();
            $table->string('category', 40)->nullable(); // STEP 14 vocabulary
            $table->string('priority', 10)->nullable(); // low, medium, high
            $table->foreignId('course_outcome_id')->nullable()->constrained('learning_outcomes', 'id', 'copo_find_lo_fk')->nullOnDelete();
            $table->foreignId('program_outcome_id')->nullable()->constrained('program_outcomes', 'id', 'copo_find_po_fk')->nullOnDelete();
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['analysis_run_id', 'severity'], 'copo_find_run_sev_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('co_po_mapping_findings');
        Schema::dropIfExists('co_po_mapping_analysis_runs');
        Schema::dropIfExists('question_co_mappings');
        Schema::dropIfExists('co_po_mappings');
        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_id');
        });
        Schema::dropIfExists('program_outcomes');
        Schema::dropIfExists('programs');
    }
};
