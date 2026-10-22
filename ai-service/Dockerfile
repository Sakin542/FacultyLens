
FROM python:3.11-slim

WORKDIR /app

# Environment variables
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    HF_HOME=/app/cache/huggingface \
    AI_SERVICE_HOST=0.0.0.0 \
    AI_SERVICE_PORT=10000

# Build tools, curl for health checks, and FFmpeg for audio transcription
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    build-essential \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install CPU-only PyTorch
RUN pip install --no-cache-dir torch \
    --index-url https://download.pytorch.org/whl/cpu

# Install Python dependencies
COPY requirements.txt .

RUN pip install --no-cache-dir -r requirements.txt \
    && python -m spacy download en_core_web_sm

# Create Hugging Face cache directory
RUN mkdir -p /app/cache/huggingface

# Copy AI service source code
COPY . .

# Document the default service port
EXPOSE 10000

# Docker container health check
# Requires GET /health to return a successful HTTP status
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -f http://localhost:10000/health || exit 1

# Start FastAPI using Render's assigned port
CMD ["sh", "-c", "uvicorn app.main:app --host 0.0.0.0 --port ${PORT:-10000}"]
