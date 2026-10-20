"""Meaning-level claim check, hosted rubric drafts, transcript key terms and proofreading, online Bangla voice.

No model is loaded: the NLI verifier, the generator and the voices are replaced by fakes with the behaviour that
was measured on the real models (see the calibration notes in claim_check.py).
"""

import io
import json
import wave

import numpy as np
import pytest

from app.config import get_settings
from app.services import claim_check, provider_context, toolkit
from app.services.academic_chat import AcademicChatService
from app.services.claim_check import Judgement
from app.services.text_generation_service import TextGenerationService
from app.services.writing_tools import StudyPackBuilder

DOC = [{"content": "Breadth-first search explores the graph level by level using a FIFO queue. It finds the shortest path "
                   "in an unweighted graph. Linear probing resolves collisions by checking the next slot until an empty slot is found."}]


class FakeVerifier:
    """Judgements keyed by claim text; anything else is neutral and not close in meaning."""

    def __init__(self, table):
        self.table = table
        self.calls = []

    def judge(self, claims, passages):
        self.calls.append((list(claims), list(passages)))
        return [self.table.get(c, Judgement(0.0, 0.0, 0.3, passages[0])) for c in claims]


# ------------------------------------------------------------------------------------------- claim check

def test_decision_rule_vetoes_contradictions_and_accepts_close_rewording():
    assert not Judgement(0.0, 0.95, 0.9, "p").supported(lexical_ok=True)       # one swapped fact
    assert Judgement(0.98, 0.95, 0.83, "p").supported(lexical_ok=False)        # another passage entails it: no veto
    assert Judgement(0.0, 0.1, 0.84, "p").supported(lexical_ok=False)          # reworded, close in meaning
    assert Judgement(0.99, 0.0, 0.5, "p").supported(lexical_ok=False)          # entailed ("one fifth" = 20 percent)
    assert not Judgement(0.1, 0.0, 0.6, "p").supported(lexical_ok=False)       # invented, not close
    assert Judgement(0.0, 0.0, 0.4, "p").supported(lexical_ok=True)            # word check alone still enough


def test_chat_rejects_a_swapped_fact_that_reuses_the_documents_words():
    swapped = "Breadth-first search explores the graph level by level using a stack."
    v = FakeVerifier({swapped: Judgement(0.0, 1.0, 0.9, "BFS uses a FIFO queue.")})
    assert AcademicChatService.unsupported_claims(swapped, DOC) == []  # the word check alone passes it
    assert AcademicChatService.unsupported_claims(swapped, DOC, verifier=v) == [swapped]


def test_chat_keeps_a_correct_answer_written_in_other_words():
    reworded = "When a collision happens, linear probing tries the following slots one by one."
    v = FakeVerifier({reworded: Judgement(0.06, 0.03, 0.84, "Linear probing resolves collisions ...")})
    assert AcademicChatService.unsupported_claims(reworded, DOC) == [reworded]  # the word check alone rejects it
    assert AcademicChatService.unsupported_claims(reworded, DOC, verifier=v) == []
    claims, passages = v.calls[0]
    assert claims == [reworded] and any("FIFO queue" in p for p in passages) and len(passages) <= 400


def test_without_the_nli_model_the_word_check_decides():
    class Unavailable:
        def judge(self, claims, passages):
            return None

    swapped = "Breadth-first search explores the graph level by level using a stack."
    assert AcademicChatService.unsupported_claims(swapped, DOC, verifier=Unavailable()) == []


def test_verifier_is_off_when_no_model_is_configured():
    assert get_settings().claim_check_model == ""  # tests never download the NLI model
    assert claim_check.get_claim_verifier().judge(["a claim here"], ["a passage"]) is None


def test_quiz_answer_support_uses_the_meaning_check_for_full_statements(monkeypatch):
    sentences = ["Breadth-first search explores the graph level by level using a FIFO queue.",
                 "It finds the shortest path in an unweighted graph."]
    builder = StudyPackBuilder(None, None)
    wrong = "Breadth-first search explores the graph level by level using a stack."
    right = "In an unweighted graph, BFS gives the shortest route."
    table = {wrong: Judgement(0.0, 1.0, 0.9, sentences[0]), right: Judgement(0.95, 0.0, 0.82, sentences[1])}
    verifier = FakeVerifier(table)
    monkeypatch.setattr(claim_check, "get_claim_verifier", lambda: verifier)
    assert builder.support("How does BFS explore a graph?", wrong, sentences) is None
    assert builder.support("What does BFS find?", right, sentences) == sentences[1]
    # Short answers are left to the word rule: NLI cannot judge fragments
    calls = len(verifier.calls)
    builder.support("What data structure does BFS use?", "A FIFO queue", sentences)
    assert len(verifier.calls) == calls


# ------------------------------------------------------------------------------------------- hosted rubric drafts

class FakeShared:
    def __init__(self, text, model="hosted:anthropic/claude-opus-5.5", hosted=True):
        self.text, self.model, self.hosted_configured = text, model, hosted
        self.is_configured = True
        self.model_name = model
        self.tokens = None

    def generate(self, prompt, max_new_tokens=None):
        self.tokens = max_new_tokens
        provider_context.record_model_used(self.model)
        return self.text


@pytest.fixture
def rubric_gen(monkeypatch):
    s = get_settings()
    monkeypatch.setattr(s, "rubric_generation_enabled", False)
    monkeypatch.setattr(s, "rubric_generation_hosted", True)
    svc = TextGenerationService()
    monkeypatch.setattr(svc, "_model_name", None)
    yield svc
    provider_context.set_requested_provider(None)


def test_rubric_drafting_uses_hosted_models_only(monkeypatch, rubric_gen):
    shared = FakeShared("Algorithm steps\nCorrectness")
    monkeypatch.setattr(TextGenerationService, "_shared", staticmethod(lambda: shared))
    provider_context.set_requested_provider("local")
    assert rubric_gen.is_configured is False  # the small local model never drafts rubrics
    provider_context.set_requested_provider("hosted")
    assert rubric_gen.is_configured is True
    assert rubric_gen.generate_lines("prompt", max_new_tokens=64) == ["Algorithm steps", "Correctness"]
    assert shared.tokens == 256  # room for a thinking model's reasoning

    # Every hosted model failed and the local model answered: templates are used instead
    shared.model = "Qwen/Qwen2.5-0.5B-Instruct"
    assert rubric_gen.generate_lines("prompt") == []


def test_hosted_rubric_draft_has_descriptions_indicators_and_weights(monkeypatch, rubric_gen):
    from app.schemas.rubric import GenerateRubricRequest
    from app.services.rubric_generator import RubricGenerator

    draft = {"criteria": [
        {"criterion": "Traversal order", "description": "Explains that BFS visits nodes level by level.", "indicators": ["Mentions levels", "Uses a queue"], "weight": 3},
        {"criterion": "Shortest paths", "description": "Explains why BFS finds shortest paths in unweighted graphs.", "indicators": ["Unweighted graphs"], "weight": 2},
        {"criterion": "Complexity", "description": "States O(V + E) time.", "indicators": ["O(V + E)"], "weight": 1},
    ]}
    shared = FakeShared("Here is the rubric:\n```json\n" + json.dumps(draft) + "\n```")
    monkeypatch.setattr(TextGenerationService, "_shared", staticmethod(lambda: shared))
    provider_context.set_requested_provider("hosted")
    result = RubricGenerator(hf_service=None, text_generation_service=rubric_gen).generate(
        GenerateRubricRequest(question_text="Explain how breadth-first search works.", total_marks=12, question_type="descriptive"))
    criteria = result["rubric"]["criteria"]
    assert result["generation_method"] == "ai_assisted" and result["metadata"]["model"] == "hosted:anthropic/claude-opus-5.5"
    assert [c["criterion"] for c in criteria] == ["Traversal order", "Shortest paths", "Complexity"]
    assert criteria[0]["description"] == "Explains that BFS visits nodes level by level."
    assert criteria[0]["expected_indicators"] == ["Mentions levels", "Uses a queue"]
    assert sum(c["max_marks"] for c in criteria) == 12 and criteria[0]["max_marks"] > criteria[2]["max_marks"]


# ------------------------------------------------------------------------------------------- transcription

def test_key_terms_are_cleaned_and_bounded():
    clean = toolkit.LectureTranscriber.clean_terms
    assert clean(" queue,  BFS ;Dijkstra\nqueue ") == "queue, BFS, Dijkstra"
    assert clean(None) == "" and len(clean("x" * 1000)) == 400


class FakeGen:
    hosted_configured = True

    def __init__(self, reply, model="hosted:anthropic/claude-opus-5.5"):
        self.reply, self.model, self.prompts = reply, model, []

    def generate(self, prompt, max_new_tokens=None):
        self.prompts.append(prompt)
        provider_context.record_model_used(self.model)
        return self.reply(prompt) if callable(self.reply) else self.reply


SEGS = [{"start": 0.0, "end": 3.1, "text": "Breadth-first search uses a cue."},
        {"start": 3.1, "end": 7.6, "text": "It finds the shortest path."}]


def test_proofreading_fixes_misheard_words_but_never_rewrites(monkeypatch):
    provider_context.set_requested_provider("hosted")
    gen = FakeGen(json.dumps({"lines": ["Breadth-first search uses a queue.", "In short, BFS is a great algorithm for many tasks."]}))
    out, changed, model = toolkit.LectureTranscriber.proofread(SEGS, "queue, BFS", gen)
    assert out[0]["text"] == "Breadth-first search uses a queue."   # sound-alike fixed
    assert out[1]["text"] == SEGS[1]["text"]                         # rewrite rejected, original kept
    assert changed == 1 and model == "hosted:anthropic/claude-opus-5.5"
    assert "key terms: queue, BFS" in gen.prompts[0] and "exactly 2" in gen.prompts[0]
    assert SEGS[0]["text"].endswith("cue.")                          # input not modified


def test_proofreading_is_skipped_when_unsafe(monkeypatch):
    provider_context.set_requested_provider("hosted")
    # Wrong number of lines: the whole batch is ignored
    out, changed, _ = toolkit.LectureTranscriber.proofread(SEGS, "", FakeGen(json.dumps({"lines": ["only one"]})))
    assert changed == 0 and out == SEGS
    # Hosted models failed and the small local model answered: ignored
    out, changed, _ = toolkit.LectureTranscriber.proofread(SEGS, "", FakeGen(json.dumps({"lines": ["Breadth-first search uses a queue.", "x"]}), model="Qwen/Qwen2.5-0.5B-Instruct"))
    assert changed == 0
    # Local provider selected: no proofreading at all
    provider_context.set_requested_provider("local")
    gen = FakeGen("unused")
    assert toolkit.LectureTranscriber.proofread(SEGS, "", gen) == (SEGS, 0, None) and gen.prompts == []
    provider_context.set_requested_provider(None)


def test_whisper_gets_the_key_terms_as_its_prompt(monkeypatch):
    monkeypatch.setattr(toolkit.shutil, "which", lambda name: "/usr/bin/ffmpeg")
    monkeypatch.setattr(toolkit.LectureTranscriber, "_decode", staticmethod(lambda data: np.zeros(16000 * 5, dtype=np.float32)))
    monkeypatch.setattr(toolkit.LectureTranscriber, "_load", lambda self: None)
    seen = {}

    class Tok:
        def get_prompt_ids(self, text, return_tensors=None):
            seen["prompt"] = text
            return "ids"

    class Pipe:
        tokenizer = Tok()

        def __call__(self, audio, return_timestamps, generate_kwargs):
            seen["kwargs"] = generate_kwargs
            return {"text": "Breadth-first search uses a queue.", "chunks": [{"timestamp": (0.0, 3.0), "text": "Breadth-first search uses a queue."}]}

    monkeypatch.setattr(toolkit.LectureTranscriber, "_pipe", Pipe())
    result = toolkit.get_transcriber().run(b"audio", "en", terms="queue, BFS")
    assert seen["prompt"] == "Lecture vocabulary: queue, BFS." and seen["kwargs"]["prompt_ids"] == "ids"
    assert seen["kwargs"]["language"] == "english"
    assert result["corrected_lines"] == 0 and result["proofread_model"] is None  # local provider: no proofreading


# ------------------------------------------------------------------------------------------- Bangla voice

def test_bangla_uses_the_online_neural_voice_and_falls_back_to_mms(monkeypatch):
    s = get_settings()
    monkeypatch.setattr(s, "tts_bangla_engine", "edge")
    real = toolkit._module_available
    monkeypatch.setattr(toolkit, "_module_available", lambda name: True if name == "edge_tts" else real(name))
    monkeypatch.setattr(toolkit.shutil, "which", lambda name: "/usr/bin/ffmpeg")
    speaker = toolkit.get_speaker()
    assert speaker.model_name("bn") == "edge-tts (bn-BD-NabanitaNeural)"

    monkeypatch.setattr(toolkit.Speaker, "_edge_mp3", staticmethod(lambda text, voice: b"mp3-bytes"))

    class Done:
        stdout = np.full(2400, 0.2, dtype="<f4").tobytes()

    monkeypatch.setattr(toolkit.subprocess, "run", lambda *a, **k: Done())
    result = speaker.run("শুভ সকাল।", "bn")
    with wave.open(io.BytesIO(result["audio"])) as w:
        assert w.getframerate() == 24000 and w.getnframes() == 2400
    assert result["model"] == "edge-tts (bn-BD-NabanitaNeural)"

    def offline(text, voice):
        raise OSError("no internet")

    monkeypatch.setattr(toolkit.Speaker, "_edge_mp3", staticmethod(offline))
    monkeypatch.setattr(toolkit.Speaker, "_speak_mms", lambda self, text, lang: {"audio": b"", "model": toolkit.TTS_MODELS[lang], "seconds": 1.0, "sampling_rate": 16000})
    assert speaker.run("শুভ সকাল।", "bn")["model"] == "facebook/mms-tts-ben"


# ------------------------------------------------------------------------------------------- faster transcription

def test_faster_whisper_is_used_with_key_terms_and_silence_skipping(monkeypatch):
    monkeypatch.setattr(toolkit.shutil, "which", lambda name: "/usr/bin/ffmpeg")
    monkeypatch.setattr(toolkit.LectureTranscriber, "_decode", staticmethod(lambda data: np.zeros(16000 * 5, dtype=np.float32)))
    real = toolkit._module_available
    monkeypatch.setattr(toolkit, "_module_available", lambda name: True if name == "faster_whisper" else real(name))
    monkeypatch.setattr(get_settings(), "asr_engine", "faster-whisper")
    monkeypatch.setattr(toolkit.LectureTranscriber, "_fast_failed", False)
    monkeypatch.setattr(toolkit.LectureTranscriber, "_load", lambda self: (_ for _ in ()).throw(AssertionError("pipeline must not load")))
    seen = {}

    class Seg:
        def __init__(self, start, end, text):
            self.start, self.end, self.text = start, end, text

    class Fast:
        def transcribe(self, audio, **kw):
            seen.update(kw)
            return iter([Seg(0.0, 2.4, " Breadth-first search uses a queue."), Seg(2.4, 4.1, "  ")]), None

    monkeypatch.setattr(toolkit.LectureTranscriber, "_fast", Fast())
    result = toolkit.get_transcriber().run(b"audio", "bn", terms="queue")
    assert result["model"] == "faster-whisper large-v3-turbo (int8)"
    assert result["segments"] == [{"start": 0.0, "end": 2.4, "text": "Breadth-first search uses a queue."}]
    assert seen["vad_filter"] is True and seen["language"] == "bn" and seen["initial_prompt"] == "Lecture vocabulary: queue."


def test_pipeline_is_the_fallback_when_faster_whisper_cannot_load(monkeypatch):
    monkeypatch.setattr(toolkit.shutil, "which", lambda name: "/usr/bin/ffmpeg")
    monkeypatch.setattr(toolkit.LectureTranscriber, "_decode", staticmethod(lambda data: np.zeros(16000 * 5, dtype=np.float32)))
    real = toolkit._module_available
    monkeypatch.setattr(toolkit, "_module_available", lambda name: True if name == "faster_whisper" else real(name))
    monkeypatch.setattr(get_settings(), "asr_engine", "faster-whisper")
    monkeypatch.setattr(toolkit.LectureTranscriber, "_fast", None)
    monkeypatch.setattr(toolkit.LectureTranscriber, "_fast_failed", False)
    monkeypatch.setattr(toolkit.LectureTranscriber, "_load_fast", lambda self: False)
    monkeypatch.setattr(toolkit.LectureTranscriber, "_load", lambda self: None)
    monkeypatch.setattr(toolkit.LectureTranscriber, "_pipe", lambda self_or_audio, *a, **k: {"text": "pipeline text", "chunks": [{"timestamp": (0.0, 2.0), "text": "pipeline text"}]})
    assert toolkit.get_transcriber().run(b"audio", "en")["text"] == "pipeline text"


# ------------------------------------------------------------------------------------------- abbreviations + hosted judge

def test_abbreviations_come_from_the_documents():
    abbr = claim_check.abbreviations(["Breadth-first search (BFS) explores level by level. Use the Dijkstra (DJ) method. A Hash Table (HT) maps keys."])
    assert abbr == {"BFS": "Breadth-first search", "HT": "Hash Table"}  # DJ: initials do not match, not an abbreviation
    assert claim_check.expand("BFS uses a queue.", abbr) == "Breadth-first search (BFS) uses a queue."
    assert claim_check.expand("Breadth-first search (BFS) uses a queue.", abbr) == "Breadth-first search (BFS) uses a queue."


def test_chat_checks_an_abbreviated_claim_against_the_long_form():
    doc = [{"content": "Breadth-first search (BFS) explores the graph level by level using a FIFO queue."}]
    claim = "BFS explores the graph level by level using a FIFO queue."
    v = FakeVerifier({})
    assert AcademicChatService.unsupported_claims(claim, doc, verifier=v) == []
    assert v.calls[0][0] == ["Breadth-first search (BFS) explores the graph level by level using a FIFO queue."]


def test_hosted_judge_accepts_heavy_rewording_and_rejects_invented_details(monkeypatch):
    doc = [{"content": "The final exam is worth 50 percent and the project 20 percent. Linear probing checks the next slot until an empty slot is found."}]
    reworded = "The project counts for one fifth of the grade."
    invented = "Linear probing checks the next slot and keeps a list of free slots."
    swapped = "Linear probing checks the previous slot until an empty slot is found."
    sent = {}

    def judge(models, parts, max_tokens):
        sent["prompt"], sent["models"] = parts[0]["text"], models
        return "anthropic/claude-haiku-5.5", '{"verdicts": ["SUPPORTED", "NOT_FOUND", "SUPPORTED"]}'

    monkeypatch.setattr(claim_check.llm_chain, "run_media", judge)
    monkeypatch.setattr(claim_check.llm_chain, "connection_configured", lambda: True)
    monkeypatch.setattr(get_settings(), "claim_judge_models", "anthropic/claude-haiku-5.5,google/gemini-3.8-flash")
    provider_context.set_requested_provider("hosted")
    try:
        # The hosted verdicts decide; the local verifier is not consulted
        v = FakeVerifier({swapped: Judgement(0.0, 0.99, 0.9, "p")})
        assert AcademicChatService.unsupported_claims(" ".join([reworded, invented, swapped]), doc, verifier=v) == [invented]
        assert v.calls == []
        assert sent["models"] == ["anthropic/claude-haiku-5.5", "google/gemini-3.8-flash"]
        assert "one fifth" in sent["prompt"] and "[1] The final exam" in sent["prompt"] and "exactly 3" in sent["prompt"]

        # A malformed reply is ignored: the local rule decides
        monkeypatch.setattr(claim_check.llm_chain, "run_media", lambda *a: ("m", "I think they are fine"))
        assert claim_check.judge_with_llm([reworded], ["p"]) is None
    finally:
        provider_context.set_requested_provider(None)
    assert claim_check.judge_with_llm([reworded], ["p"]) is None  # local provider: no hosted judge


def test_online_voice_retries_caches_and_reports_its_health(monkeypatch, tmp_path):
    s = get_settings()
    monkeypatch.setattr(s, "tts_bangla_engine", "edge")
    monkeypatch.setattr(s, "tts_cache_max_files", 2)
    real = toolkit._module_available
    monkeypatch.setattr(toolkit, "_module_available", lambda name: True if name == "edge_tts" else real(name))
    monkeypatch.setattr(toolkit.shutil, "which", lambda name: "/usr/bin/ffmpeg")
    monkeypatch.setattr(toolkit.Speaker, "_cache_path", staticmethod(lambda voice, text: str(tmp_path / f"{abs(hash((voice, text)))}.mp3")))
    monkeypatch.setattr(toolkit.time, "sleep", lambda s: None)
    monkeypatch.setattr(toolkit.Speaker, "edge_last_ok", None)
    monkeypatch.setattr(toolkit.Speaker, "edge_last_error", None)
    monkeypatch.setattr(toolkit.Speaker, "edge_last_error_at", None)

    class Done:
        stdout = np.zeros(240, dtype="<f4").tobytes()

    monkeypatch.setattr(toolkit.subprocess, "run", lambda *a, **k: Done())
    calls = []

    def flaky(text, voice):
        calls.append(text)
        if len(calls) == 1:
            raise OSError("blip")  # first try fails, the retry succeeds
        return b"clip-" + text.encode()

    monkeypatch.setattr(toolkit.Speaker, "_edge_mp3", staticmethod(flaky))
    speaker = toolkit.get_speaker()
    assert speaker.run("শুভ সকাল।", "bn")["model"].startswith("edge-tts")
    assert len(calls) == 2 and speaker.online_voice_status()["state"] == "ok"

    # Service down: text read before still plays from the cache; new text falls back to the local voice
    def down(text, voice):
        raise OSError("offline")

    monkeypatch.setattr(toolkit.Speaker, "_edge_mp3", staticmethod(down))
    monkeypatch.setattr(toolkit.Speaker, "_speak_mms", lambda self, text, lang: {"audio": b"", "model": toolkit.TTS_MODELS[lang], "seconds": 1.0, "sampling_rate": 16000})
    assert speaker.run("শুভ সকাল।", "bn")["model"].startswith("edge-tts")      # from the cache
    assert speaker.run("নতুন বাক্য।", "bn")["model"] == "facebook/mms-tts-ben"   # new text: local voice
    status = speaker.online_voice_status()
    assert status["state"] == "failing" and status["last_error"] == "OSError" and status["voice"] == "bn-BD-NabanitaNeural"
