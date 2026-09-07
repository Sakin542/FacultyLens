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
        Schema::create('recommendation_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recommendation_id')->constrained('recommendations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('decision', 50); // ACCEPTED, DISMISSED, REVIEWED
            $table->unsignedTinyInteger('usefulness_rating')->nullable(); // 1 to 5
            $table->string('reason', 100)->nullable(); // e.g. USEFUL_INSIGHT, NOT_APPLICABLE
            $table->text('comment')->nullable(); // max 2000 chars validated at controller
            $table->timestamps();

            $table->index(['recommendation_id', 'user_id']);
            $table->index(['user_id', 'decision']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recommendation_feedback');
    }
};

