<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->string('problem')->nullable()->after('category');
            $table->text('explanation')->nullable()->after('description');
            $table->text('recommendation')->nullable()->after('explanation');
            $table->json('evidence')->nullable()->after('recommendation');
            $table->string('source_metric')->nullable()->after('evidence');
            $table->text('faculty_notes')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropColumn([
                'problem',
                'explanation',
                'recommendation',
                'evidence',
                'source_metric',
                'faculty_notes',
            ]);
        });
    }
};

