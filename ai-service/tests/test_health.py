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

