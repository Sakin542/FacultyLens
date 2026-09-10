"""STEP 35: model inventory endpoint used by the Laravel model registry."""

from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def test_inventory_lists_models_engines_prompts_and_thresholds_without_secrets():
    res = client.get("/api/v1/evaluation/models")
    assert res.status_code == 200, res.text
    body = res.json()
    assert body["embedding_model"]["model_type"] == "embedding"
    assert "MiniLM" in body["embedding_model"]["model_name"]
    assert body["embedding_model"]["configuration"]["embedding_dimension"] in (384, None)
    assert body["generation_model"]["model_type"] == "generation"
    tasks = {t for e in body["engines"] for t in e["tasks"]}
    assert {"QUESTION_CLASSIFICATION", "RUBRIC_GENERATION", "GRADING_ASSISTANCE", "DOCUMENT_CHAT", "QUESTION_GENERATION"} <= tasks
    features = {p["feature"] for p in body["prompt_versions"]}
    assert {"document_chat", "question_generation"} <= features
    assert body["thresholds"]["similarity"]["duplicate"] == 0.85
    dumped = res.text.lower()
    assert "hf_token" not in dumped and "api_key" not in dumped
