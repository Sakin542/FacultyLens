FROM python:3.11-slim

WORKDIR /app

# Set environment variables
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    HF_HOME=/app/cache/huggingface \
    AI_SERVICE_HOST=0.0.0.0 \
    AI_SERVICE_PORT=8001

# Build tools, curl for healthchecks, ffmpeg for lecture transcription (Whisper audio decoding)
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    build-essential \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install python dependencies: CPU-only PyTorch in its own layer, so editing requirements.txt does not re-download it
RUN pip install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt \
    # Kokoro's English phonemizer (misaki) needs this spaCy model; installing it here avoids a download at first use
    && python -m spacy download en_core_web_sm

# Create cache directory
RUN mkdir -p /app/cache/huggingface

# Copy application source code
COPY . .

# Expose service port
EXPOSE 8001

# Healthcheck
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -f http://localhost:8001/health || exit 1

# Start FastAPI application
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]

