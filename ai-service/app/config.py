from functools import lru_cache
from typing import Optional, Any
from pydantic import field_validator
try:
    from pydantic_settings import BaseSettings, SettingsConfigDict
except ImportError:  # pragma: no cover
    from pydantic import BaseModel as BaseSettings
    SettingsConfigDict = None


class Settings(BaseSettings):
    app_name: str = "FacultyLens AI Service"
    app_env: str = "local"
    debug: bool = True

    @field_validator("debug", mode="before")
    @classmethod
    def parse_debug_value(cls, v: Any) -> bool:
        if isinstance(v, bool):
            return v
        if isinstance(v, str):
            return v.strip().lower() in ("true", "1", "yes", "on", "debug", "t")
        return bool(v)

    ai_service_host: str = "0.0.0.0"
    ai_service_port: int = 8001

    # Hugging Face Model
    hf_model_name: str = "sentence-transformers/all-MiniLM-L6-v2"
    hf_token: Optional[str] = None
    hf_home: Optional[str] = None

    # Internal API Key for service-to-service validation
    ai_service_api_key: Optional[str] = None

    # Model settings
    max_text_length: int = 50000
    # STEP 43: in-process LRU of sentence embeddings (entries; ~1.5 KB each for MiniLM). 0 disables it.
    embedding_cache_size: int = 20000

    # Semantic Similarity & Duplicate Detection Settings (Step 12)
    similarity_duplicate_threshold: float = 0.85
    similarity_high_threshold: float = 0.70
    similarity_moderate_threshold: float = 0.50
    similarity_top_k: int = 5

    # Assessment Quality Engine Settings (Step 13)
    quality_weight_topic: float = 20.0
    quality_weight_lo: float = 20.0
    quality_weight_difficulty: float = 15.0
    quality_weight_cognitive: float = 15.0
    quality_weight_question_diversity: float = 15.0
    quality_weight_marks: float = 15.0

    target_easy_percent: float = 30.0
    target_medium_percent: float = 50.0
    target_hard_percent: float = 20.0

    # AI Rubric Generator Settings (Step 25)
    # MiniLM (hf_model_name) stays responsible for embeddings only. Rubric text
    # drafting may optionally use a small seq2seq model such as google/flan-t5-small.
    # Leave rubric_generation_model empty to use the structured template engine only.
    rubric_generation_enabled: bool = False
    rubric_generation_model: Optional[str] = None
    rubric_generation_max_new_tokens: int = 192
    # With the hosted provider selected, rubric drafts and grading comments use the hosted models even when
    # rubric_generation_enabled is off (that switch is for the small local models, whose drafts are weak). Their
    # output is only used when a hosted model wrote it; otherwise the template engine runs.
    rubric_generation_hosted: bool = True
    # Meaning check for generated answers (document chat, quiz answers): NLI cross-encoder, ~0.9 GB in memory.
    # Empty = word-overlap check only.
    claim_check_model: str = "cross-encoder/nli-deberta-v3-base"
    # With the hosted provider selected, a hosted model also judges each claim against the passages (one call per
    # answer). It understands rewording and catches invented details that contradict nothing. Empty = local check only.
    claim_judge_models: str = "anthropic/claude-haiku-5.5,google/gemini-3.8-flash"
    rubric_max_criteria: int = 8

    @field_validator("rubric_generation_enabled", mode="before")
    @classmethod
    def parse_rubric_generation_enabled(cls, v: Any) -> bool:
        if isinstance(v, bool):
            return v
        if isinstance(v, str):
            return v.strip().lower() in ("true", "1", "yes", "on", "t")
        return bool(v)

    # Answer <-> Rubric Alignment Settings (Step 28)
    # Initial engineering thresholds on the combined semantic/lexical criterion signal.
    # They are deliberately separate from the STEP 11 learning-outcome thresholds and
    # MUST be validated against real faculty-reviewed examples before being trusted.
    rubric_alignment_strong_threshold: float = 0.75
    rubric_alignment_partial_threshold: float = 0.55
    rubric_alignment_weak_threshold: float = 0.35

    # Academic Document Chat (Step 32) — RAG.
    # MiniLM (hf_model_name) embeds chunks and queries; answers are produced by a SEPARATE,
    # configurable generative model. When none is configured the service falls back to an
    # extractive, evidence-only answer built from the retrieved chunks (reported honestly).
    hf_generation_model: Optional[str] = None       # e.g. google/flan-t5-base
    hf_generation_max_new_tokens: int = 256
    chat_top_k: int = 5
    chat_min_relevance_score: float = 0.15          # cosine on MiniLM; engineering threshold
    chat_max_context_chars: int = 6000
    chat_max_history_messages: int = 10
    chat_max_question_length: int = 5000
    chat_prompt_version: str = "1.0.0"

    # Constrained Question Generator (Step 33) — reuses hf_generation_model for drafting;
    # falls back to a constraint-driven template engine. MiniLM is embeddings-only.
    question_generation_max_new_tokens: int = 512
    question_generation_max_questions: int = 20
    question_generation_alignment_strong: float = 0.70   # STEP 11 thresholds
    question_generation_alignment_weak: float = 0.50

    # Generation provider. "local" runs hf_generation_model in this container; "hosted" calls an OpenAI-compatible
    # chat-completions API (Hugging Face Inference Providers, Groq, Gemini, OpenRouter, ...). Laravel chooses the
    # provider per request (X-AI-Provider header, set by the admin); the API key never leaves this service.
    llm_hosted_base_url: Optional[str] = None       # e.g. https://router.huggingface.co/v1
    llm_hosted_api_key: Optional[str] = None
    llm_hosted_model: Optional[str] = None          # e.g. meta-llama/Llama-3.1-8B-Instruct
    llm_hosted_fallback_models: str = ""            # comma-separated, tried in order when the model above fails
    llm_hosted_timeout_seconds: int = 60
    llm_hosted_max_retries: int = 1                 # LangChain/OpenAI SDK retries on 429/5xx before falling back
    # OpenRouter-style reasoning control: "low" / "medium" / "high" set the thinking effort (works for every model),
    # "off" asks for a direct answer (models that must reason reject it), "on" enables it, empty sends nothing (for
    # providers that reject the field). "effort:<level>" sends the OpenAI-standard reasoning_effort instead
    # (Gemini's OpenAI endpoint: "effort:none" stops thinking from using up the answer's token budget).
    llm_hosted_reasoning: str = ""
    # Hosted models for the toolkit's image and audio tools (same base URL and key), tried in order; when all fail,
    # or the admin selected "local", TrOCR / Whisper run locally instead. Images/recordings are sent to the provider.
    llm_vision_models: str = "google/gemini-3.8-flash,anthropic/claude-haiku-5.5"
    llm_audio_models: str = ""                      # e.g. google/gemini-3.8-flash (OpenRouter needs a $0.50+ balance for audio)
    llm_media_timeout_seconds: int = 300
    # Optional speech-to-text API (OpenAI-compatible /audio/transcriptions, e.g. Groq's free Whisper at
    # https://api.groq.com/openai/v1). Tried first for lecture transcription when a key is set.
    asr_api_base_url: Optional[str] = None
    asr_api_key: Optional[str] = None
    asr_api_model: str = "whisper-large-v3-turbo"
    asr_hosted_chunk_seconds: int = 60              # pieces of about this length, cut at quiet moments (timestamps stay accurate)

    # AI toolkit (local Hugging Face models, loaded lazily on first use)
    ocr_model: str = "microsoft/trocr-base-handwritten"
    ocr_max_pages: int = 10
    asr_model: str = "openai/whisper-large-v3-turbo"   # ~1.6 GB; far better than whisper-base, especially for Bangla
    asr_max_seconds: int = 5400
    # Local transcription engine: "faster-whisper" (CTranslate2, 8-bit weights, all CPU cores, skips silence; several
    # times faster than transformers at the same accuracy) or "transformers" (asr_model through the HF pipeline, also
    # the automatic fallback when faster-whisper is missing or fails to load).
    asr_engine: str = "faster-whisper"
    asr_fast_model: str = "large-v3-turbo"         # faster-whisper name or CTranslate2 repo id
    asr_fast_compute_type: str = "int8"
    asr_fast_beam_size: int = 5
    # 0 = half the CPU cores: measured 3x faster than using every core, which oversubscribes hyper-threads
    asr_fast_threads: int = 0
    translation_model: str = "facebook/nllb-200-distilled-600M"
    translation_max_chars: int = 5000
    tts_max_chars: int = 1500
    # English speech: "kokoro" (hexgrad/Kokoro-82M, natural voice), "edge" (Microsoft online voice, no local model:
    # for small hosts) or "mms" (facebook/mms-tts-eng). English falls back to MMS when the chosen engine fails.
    tts_english_engine: str = "kokoro"
    tts_kokoro_voice: str = "af_heart"
    tts_english_voice: str = "en-US-AriaNeural"   # edge-tts voice used when tts_english_engine is "edge"
    # Bangla speech: "edge" (Microsoft neural voice through the edge-tts package: natural, but the text is sent to
    # Microsoft's online read-aloud service and needs internet) or "mms" (facebook/mms-tts-ben, local). Falls back to
    # MMS-TTS when the online voice fails.
    tts_bangla_engine: str = "edge"
    tts_bangla_voice: str = "bn-BD-NabanitaNeural"   # or bn-BD-PradeepNeural (male)
    # Online-voice clips are kept on disk (by voice + text), so text read before still plays during an outage and
    # replays instantly. 0 = no cache.
    tts_cache_max_files: int = 2000
    # Local model memory (see model_memory): models unused for this long are unloaded (0 = never), and before a
    # model loads, idle ones are unloaded until the process fits the budget (0 = no budget). The default leaves room
    # for the rest of the stack inside Docker Desktop's default ~7.5 GB.
    model_idle_unload_seconds: int = 900
    model_memory_budget_mb: int = 5000
    # Strict: refuse to load a model that cannot fit the budget (the request fails cleanly) instead of loading it
    # anyway. For small hosts (e.g. 512 MB) where an oversized load would get the whole service OOM-killed.
    model_memory_strict: bool = False
    # Answer similarity / plagiarism check: multilingual embeddings (falls back to hf_model_name if unavailable)
    answer_similarity_model: str = "BAAI/bge-m3"

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )


@lru_cache()
def get_settings() -> Settings:
    """Return cached application settings instance."""
    return Settings()

