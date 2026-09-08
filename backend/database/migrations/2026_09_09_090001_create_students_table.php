<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STEP 26: Minimal student identity for assessment submissions.
     * Students are scoped to the faculty member who registered them; no login accounts.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('student_identifier', 64);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('department', 120)->nullable();
            $table->string('program', 120)->nullable();
            $table->string('academic_year', 20)->nullable();
            $table->string('section', 20)->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'student_identifier']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
