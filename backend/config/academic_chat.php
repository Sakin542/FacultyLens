<?php

/**
 * STEP 32: Academic Document Chat (RAG). Engineering defaults — tune per deployment.
 * Embeddings: MiniLM via the AI service (same model as STEP 09). Generation: configured on the AI service.
 */
return [
    // Chunking (word-based; ~1 token ≈ 0.75 words so 700 words ≈ 900 tokens)
    'chunk_size_words' => (int) env('CHAT_CHUNK_SIZE', 700),
    'chunk_overlap_words' => (int) env('CHAT_CHUNK_OVERLAP', 120),
    'max_chunks_per_document' => (int) env('CHAT_MAX_CHUNKS_PER_DOCUMENT', 2000),
    'embedding_batch_size' => (int) env('CHAT_EMBEDDING_BATCH_SIZE', 64),
    'max_document_text_length' => (int) env('MAX_DOCUMENT_TEXT_LENGTH', 2000000),

    // Retrieval
    'top_k' => (int) env('CHAT_TOP_K', 5),
    'candidate_k' => (int) env('CHAT_CANDIDATE_K', 20),
    // Cosine similarity on MiniLM embeddings; an engineering threshold, not a correctness measure.
    'min_relevance_score' => (float) env('CHAT_MIN_RELEVANCE_SCORE', 0.35),

    // Conversation
    'max_history_messages' => (int) env('CHAT_MAX_HISTORY_MESSAGES', 10),
    'max_question_length' => (int) env('MAX_CHAT_QUESTION_LENGTH', 5000),
    'max_sessions_per_user' => (int) env('CHAT_MAX_SESSIONS_PER_USER', 200),

    // Protection
    'rate_limit_per_minute' => (int) env('CHAT_RATE_LIMIT', 30),
    'query_embedding_cache_ttl' => (int) env('CHAT_QUERY_EMBEDDING_CACHE_TTL', 3600),

    'embedding_model' => env('HF_EMBEDDING_MODEL', 'sentence-transformers/all-MiniLM-L6-v2'),
    'indexing_version' => env('CHAT_INDEXING_VERSION', '1'),
];
