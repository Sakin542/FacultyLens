# FacultyLens Real-Time Notification System Setup & Deployment Guide

This guide describes how to configure, run, and maintain the real-time notification infrastructure for FacultyLens.

---

## 1. Prerequisites

- Docker & Docker Compose
- Node.js 18+ (for local frontend development)
- PHP 8.2+ with Composer (for local backend development)
- Redis 7+ (included in Docker setup)
- MySQL 8+ (included in Docker setup)

---

## 2. Infrastructure Architecture (Docker Services)

The real-time stack runs five coordinated services:

```text
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│   facultylens-  │       │   facultylens-  │       │   facultylens-  │
│       app       │◄─────►│      reverb     │◄─────►│      redis      │
│  (port 8080)    │       │  (port 8085)    │       │  (port 6379)    │
└─────────────────┘       └─────────────────┘       └─────────────────┘
         ▲                                                   ▲
         │                                                   │
         ▼                                                   ▼
┌─────────────────┐                                 ┌─────────────────┐
│   facultylens-  │                                 │   facultylens-  │
│      mysql      │                                 │     horizon     │
│  (port 3307)    │                                 │  (async queues) │
└─────────────────┘                                 └─────────────────┘
```

### Docker Compose Service Definition

In `docker-compose.yml`:
```yaml
  reverb:
    build:
      context: ./backend
      dockerfile: Dockerfile
    container_name: facultylens-reverb
    command: php artisan reverb:start --host=0.0.0.0 --port=8080 --debug
    ports:
      - "8085:8080"
    environment:
      - APP_ENV=local
      - BROADCAST_CONNECTION=reverb
      - REVERB_APP_ID=${REVERB_APP_ID:-facultylens-app-id}
      - REVERB_APP_KEY=${REVERB_APP_KEY:-facultylens-app-key}
      - REVERB_APP_SECRET=${REVERB_APP_SECRET:-facultylens-app-secret}
      - REVERB_HOST=0.0.0.0
      - REVERB_PORT=8080
      - REVERB_SCHEME=http
      - REDIS_HOST=redis
      - REDIS_PORT=6379
    volumes:
      - ./backend:/var/www/html
    networks:
      - facultylens-network
    depends_on:
      redis:
        condition: service_healthy
    restart: unless-stopped
```

---

## 3. Environment Variables Configuration

### Backend (`backend/.env`)

```env
# Broadcasting Driver
BROADCAST_CONNECTION=reverb

# Laravel Reverb Server Configuration
REVERB_APP_ID=facultylens-app-id
REVERB_APP_KEY=facultylens-app-key
REVERB_APP_SECRET=facultylens-app-secret
REVERB_HOST="reverb"
REVERB_PORT=8080
REVERB_SCHEME=http

# Client Reverb Connection (used if frontend connects on host machine)
REVERB_SERVER_HOST="0.0.0.0"
REVERB_SERVER_PORT=8080
```

### Frontend (`frontend/.env`)

```env
# Laravel Reverb WebSocket Client Configuration
VITE_REVERB_APP_KEY="facultylens-app-key"
VITE_REVERB_HOST="localhost"
VITE_REVERB_PORT="8085"
VITE_REVERB_SCHEME="http"
```

---

## 4. Starting the Real-Time Stack

To start all services with health checks:

```bash
docker compose up -d
```

Verify service health:
```bash
docker compose ps
```

All 5 containers should report healthy or running:
- `facultylens-app`
- `facultylens-reverb`
- `facultylens-redis`
- `facultylens-mysql`
- `facultylens-horizon`

---

## 5. Production SSL / WSS Deployment Notes

In production with HTTPS:
1. Terminate TLS at the reverse proxy (Nginx, Traefik, or AWS ALB).
2. Route standard HTTP traffic to `facultylens-app:8000`.
3. Route WebSocket path `/app` to `facultylens-reverb:8080`:
   ```nginx
   location /app {
       proxy_pass http://reverb:8080;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "Upgrade";
       proxy_set_header Host $host;
       proxy_cache_bypass $http_upgrade;
   }
   ```
4. Set `VITE_REVERB_SCHEME="https"`, `VITE_REVERB_PORT="443"`.

