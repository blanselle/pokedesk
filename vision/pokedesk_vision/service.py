"""HTTP service: turns images into vectors and finds the cards in a photo. It never touches the database."""

import base64
import os
from contextlib import asynccontextmanager

import cv2
import numpy as np
import torch
from fastapi import FastAPI, File, HTTPException, Request, UploadFile
from starlette.concurrency import run_in_threadpool

from pokedesk_vision.cards import detect_cards, load_photo, warp_card
from pokedesk_vision.embedding import MODELS
from pokedesk_vision.images import load_catalogue_image

MODEL = os.environ.get("VISION_MODEL", "clip-b32")
MAX_BATCH = 64
MAX_PHOTO_BYTES = 40 * 1024 * 1024
# Size of the crop sent back for display, not the one used for the fingerprint.
PREVIEW_SIZE = (300, 420)

state: dict = {}


@asynccontextmanager
async def lifespan(_: FastAPI):
    torch.set_num_threads(int(os.environ.get("THREADS", "8")))
    embedder = MODELS[MODEL]()
    state["embedder"] = embedder
    state["dimension"] = embedder.embed([np.zeros((68, 49, 3), np.uint8)])[MODEL].shape[1]
    yield


app = FastAPI(title="Pokedesk vision", lifespan=lifespan)


def _embed(images: list[np.ndarray]) -> np.ndarray:
    return state["embedder"].embed(images)[MODEL]


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "model": MODEL, "dimension": state["dimension"]}


@app.post("/embed")
async def embed(request: Request) -> dict:
    """One vector per catalogue image, in the order received (any multipart field name is accepted)."""
    form = await request.form()
    uploads = [value for _, value in form.multi_items() if hasattr(value, "read")]

    if not 1 <= len(uploads) <= MAX_BATCH:
        raise HTTPException(422, f"Send between 1 and {MAX_BATCH} images.")

    decoded = []
    for upload in uploads:
        try:
            decoded.append(load_catalogue_image(await upload.read()))
        except Exception as error:
            raise HTTPException(422, f"Unreadable image {upload.filename!r}.") from error

    vectors = await run_in_threadpool(_embed, decoded)

    return {"model": MODEL, "vectors": vectors.tolist()}


@app.post("/scan")
def scan(image: UploadFile = File(...)) -> dict:
    """Detects every card of a photo. Each comes with a preview and two vectors (upright and upside-down)."""
    data = image.file.read(MAX_PHOTO_BYTES + 1)
    if len(data) > MAX_PHOTO_BYTES:
        raise HTTPException(413, "Photo too large.")

    try:
        photo = load_photo(data)
    except Exception as error:
        raise HTTPException(422, "Unreadable photo.") from error

    quads = detect_cards(photo).quads
    crops = [warp_card(photo, quad) for quad in quads]
    cards = []

    if crops:
        upright = _embed(crops)
        # Whether the card lies upside-down is unknown: the caller searches with both.
        flipped = _embed([cv2.rotate(crop, cv2.ROTATE_180) for crop in crops])

        for index, crop in enumerate(crops):
            preview = cv2.resize(crop, PREVIEW_SIZE, interpolation=cv2.INTER_AREA)
            _, encoded = cv2.imencode(".jpg", preview, [cv2.IMWRITE_JPEG_QUALITY, 85])
            cards.append({
                "quad": quads[index].tolist(),
                "crop": base64.b64encode(encoded.tobytes()).decode(),
                "embeddings": [upright[index].tolist(), flipped[index].tolist()],
            })

    return {"model": MODEL, "cards": cards}
