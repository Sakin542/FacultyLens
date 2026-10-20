<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Academic calendar events with reminders, and weekly contact hours for the workload dashboard. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            // exam | quiz | assignment_deadline | grading_deadline | class | meeting | other
            $table->string('type', 32)->default('other')->index();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('location', 200)->nullable();
            $table->text('notes')->nullable();
            // Minutes before starts_at, e.g. [10080, 1440, 60] = one week, one day, one hour
            $table->json('reminder_offsets')->nullable();
            $table->json('reminders_sent')->nullable();
            $table->timestamps();
        });

        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'contact_hours_per_week')) {
                $table->decimal('contact_hours_per_week', 4, 1)->nullable()->after('credits');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            if (Schema::hasColumn('courses', 'contact_hours_per_week')) {
                $table->dropColumn('contact_hours_per_week');
            }
        });
        Schema::dropIfExists('academic_events');
    }
};
