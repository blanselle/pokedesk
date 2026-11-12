"""Catalogue images: the same loading is used for the search gallery and for the images sent to the service."""

import io
from pathlib import Path

import cv2
import numpy as np
from PIL import Image


def load_catalogue_image(source: bytes | Path) -> np.ndarray:
    """BGR card image. Transparent corners are filled with mid grey, like a neutral surface."""
    with Image.open(io.BytesIO(source) if isinstance(source, bytes) else source) as image:
        image = image.convert("RGBA")
        background = Image.new("RGBA", image.size, (128, 128, 128, 255))
        background.alpha_composite(image)

        return cv2.cvtColor(np.asarray(background.convert("RGB")), cv2.COLOR_RGB2BGR)
