"""Security and authentication tests for FacultyLens AI microservice."""

import pytest
from fastapi.testclient import TestClient
from app.main import app
from app.config import get_settings

client = TestClient(app)


def test_health_check_open_without_auth():
    """Health endpoint must always remain publicly accessible for container orchestration."""
    response = client.get("/health")
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "ok"
    assert data["model_loaded"] is True


def test_api_key_auth_when_configured(monkeypatch):
    """When AI_SERVICE_API_KEY is configured, requests without key or with invalid key must be rejected."""
    settings = get_settings()
    monkeypatch.setattr(settings, "ai_service_api_key", "secret-test-key-2026")

    # 1. Missing key
    response = client.post(
        "/api/v1/preprocess",
        json={"text": "Explain database normalization."},
    )
    assert response.status_code == 401
    assert "Invalid or missing" in response.json()["detail"]

    # 2. Invalid key
    response = client.post(
        "/api/v1/preprocess",
        headers={"X-AI-Service-Key": "wrong-key"},
        json={"text": "Explain database normalization."},
    )
    assert response.status_code == 401

    # 3. Valid key via X-AI-Service-Key header
    response = client.post(
        "/api/v1/preprocess",
        headers={"X-AI-Service-Key": "secret-test-key-2026"},
        json={"text": "Explain database normalization."},
    )
    assert response.status_code == 200

    # 4. Valid key via Authorization: Bearer header
    response = client.post(
        "/api/v1/preprocess",
        headers={"Authorization": "Bearer secret-test-key-2026"},
        json={"text": "Explain database normalization."},
    )
    assert response.status_code == 200
