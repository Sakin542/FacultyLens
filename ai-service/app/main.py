import logging
from contextlib import asynccontextmanager
from fastapi import FastAPI, Request, status
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse

from app.config import get_settings
from app.services.huggingface_service import get_hf_service
from app.api.routes import router

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("facultylens.ai")


@asynccontextmanager
async def lifespan(app: FastAPI):
    """
    Lifespan events: Load Hugging Face model on application startup once.
    """
    settings = get_settings()
    logger.info(f"Starting {settings.app_name} on {settings.ai_service_host}:{settings.ai_service_port}...")

    # Preload the Hugging Face model
    hf_service = get_hf_service()
    success = hf_service.load_model()
    if success:
        logger.info(f"Model '{hf_service.model_name}' preloaded successfully.")
    else:
        logger.warning(f"Model preload failed: {hf_service.load_error}. Model will be lazily loaded upon first request.")

    yield

    logger.info(f"Shutting down {settings.app_name}...")


settings = get_settings()

app = FastAPI(
    title=settings.app_name,
    description="FacultyLens AI Service - Hugging Face NLP Pipeline and Semantic Processing Microservice",
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/docs" if settings.debug else None,
    redoc_url="/redoc" if settings.debug else None,
)

# CORS Configuration
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Exception Handlers
@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    """
    Format Pydantic validation errors cleanly without leaking internal structures.
    """
    errors = []
    for error in exc.errors():
        field = " -> ".join(str(loc) for loc in error.get("loc", []))
        msg = error.get("msg", "Invalid value")
        errors.append(f"{field}: {msg}")

    return JSONResponse(
        status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
        content={
            "status": "error",
            "message": "Validation failed for provided input.",
            "errors": errors,
        },
    )


@app.exception_handler(Exception)
async def general_exception_handler(request: Request, exc: Exception):
    """
    Catch-all exception handler to avoid leaking stack traces or internal secrets.
    """
    logger.error(f"Unhandled exception during request {request.url}: {exc}", exc_info=True)
    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={
            "status": "error",
            "message": "An internal error occurred while processing the request.",
        },
    )


# Include API Router
app.include_router(router)


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(
        "app.main:app",
        host=settings.ai_service_host,
        port=settings.ai_service_port,
        reload=settings.debug,
    )

