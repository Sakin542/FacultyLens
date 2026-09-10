<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 32: Academic Document Chat — document chunks + embeddings, chat sessions/messages/sources.
 * Embeddings are stored as packed little-endian float32 BLOBs (MySQL 8.0 has no vector type);
 * similarity is computed application-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_processings', function (Blueprint $table) {
            $table->string('indexing_status', 20)->default('NOT_INDEXED')->after('processing_error');
            $table->string('index_content_hash', 64)->nullable()->after('indexing_status');
            $table->unsignedInteger('chunk_count')->default(0)->after('index_content_hash');
            $table->string('embedding_model')->nullable()->after('chunk_count');
            $table->text('indexing_error')->nullable()->after('embedding_model');
            $table->timestamp('indexed_at')->nullable()->after('indexing_error');
            $table->index(['user_id', 'indexing_status'], 'doc_proc_user_index_status_idx');
        });

        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_processing_id')->constrained('document_processings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->string('content_hash', 64);
            $table->unsignedInteger('page_number')->nullable();
            $table->string('section_title', 255)->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->binary('embedding')->nullable();
            $table->unsignedSmallInteger('embedding_dimension')->nullable();
            $table->string('embedding_model')->nullable();
            $table->string('embedding_version', 32)->nullable();
            $table->timestamps();

            $table->unique(['document_processing_id', 'chunk_index'], 'doc_chunks_doc_index_unique');
            $table->index(['user_id', 'course_id'], 'doc_chunks_user_course_idx');
            $table->index('content_hash', 'doc_chunks_hash_idx');
        });

        Schema::create('academic_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 20); // COURSE | DOCUMENT | ASSESSMENT
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('document_processing_id')->nullable()->constrained('document_processings')->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at'], 'chat_sessions_user_updated_idx');
        });

        Schema::create('academic_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_chat_session_id')->constrained('academic_chat_sessions')->cascadeOnDelete();
            $table->string('role', 20); // USER | ASSISTANT
            $table->longText('content');
            $table->boolean('grounded')->default(false);
            $table->string('generation_method', 40)->nullable();
            $table->string('generation_model')->nullable();
            $table->string('embedding_model')->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->json('retrieval_metadata')->nullable();
            $table->string('status', 20)->default('COMPLETED');
            $table->timestamps();

            $table->index(['academic_chat_session_id', 'created_at'], 'chat_messages_session_created_idx');
        });

        Schema::create('academic_chat_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_chat_message_id')->constrained('academic_chat_messages')->cascadeOnDelete();
            $table->foreignId('document_processing_id')->nullable()->constrained('document_processings')->nullOnDelete();
            $table->foreignId('document_chunk_id')->nullable()->constrained('document_chunks')->nullOnDelete();
            $table->string('document_name');
            $table->string('document_type', 50)->nullable();
            $table->decimal('similarity_score', 6, 4)->nullable();
            $table->unsignedInteger('page_number')->nullable();
            $table->string('section_title', 255)->nullable();
            $table->text('excerpt')->nullable();
            $table->unsignedSmallInteger('source_order')->default(1);
            $table->timestamps();

            $table->index('academic_chat_message_id', 'chat_sources_message_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_chat_sources');
        Schema::dropIfExists('academic_chat_messages');
        Schema::dropIfExists('academic_chat_sessions');
        Schema::dropIfExists('document_chunks');

        Schema::table('document_processings', function (Blueprint $table) {
            $table->dropIndex('doc_proc_user_index_status_idx');
            $table->dropColumn([
                'indexing_status', 'index_content_hash', 'chunk_count',
                'embedding_model', 'indexing_error', 'indexed_at',
            ]);
        });
    }
};
