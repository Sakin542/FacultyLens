from fastapi.testclient import TestClient
from app.main import app

client = TestClient(app)


def test_health_endpoint():
    """
    Test GET /health returns status 200, service name and model info.
    """
    response = client.get("/health")
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "ok"
    assert "FacultyLens AI Service" in data["service"]
    assert "model" in data
    assert "model_loaded" in data


def test_readiness_reflects_model_state_without_leaking_errors():
    """
    GET /ready is 200 + "ready" only when the model is loaded, otherwise 503 + "not_ready";
    the body never contains a load error message.
    """
    response = client.get("/ready")
    data = response.json()
    assert set(data) == {"status", "service", "model", "model_loaded"}
    if data["model_loaded"]:
        assert response.status_code == 200 and data["status"] == "ready"
    else:
        assert response.status_code == 503 and data["status"] == "not_ready"


def test_protected_endpoints_reject_missing_service_key(monkeypatch):
    """
    With an internal service key configured, Laravel-only endpoints reject unauthenticated callers.
    """
    from app.config import get_settings

    settings = get_settings()
    monkeypatch.setattr(settings, "ai_service_api_key", "internal-test-key")
    try:
        denied = client.post("/api/v1/preprocess", json={"text": "Some academic text to preprocess."})
        assert denied.status_code == 401
        allowed = client.post(
            "/api/v1/preprocess",
            json={"text": "Some academic text to preprocess."},
            headers={"X-AI-Service-Key": "internal-test-key"},
        )
        assert allowed.status_code == 200
    finally:
        monkeypatch.setattr(settings, "ai_service_api_key", None)

