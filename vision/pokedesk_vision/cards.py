"""Finds the cards in a photo and cuts each one out as an upright, normalised image."""

from dataclasses import dataclass, field

import cv2
import numpy as np
from PIL import Image, ImageOps

# Real card ratio is 63 x 88 mm.
CARD_W, CARD_H = 488, 680
CARD_RATIO = 63 / 88

# Accepted short side / long side of a card, wide enough for perspective.
RATIO_RANGE = (0.60, 0.82)
MIN_FILL = 0.85

# Surface texture is averaged out at several strengths: no single value suits every surface.
BLURS = (1, 2, 4, 6, 8, 10)


@dataclass
class Detection:
    quads: list[np.ndarray] = field(default_factory=list)


def load_photo(data: bytes | str) -> np.ndarray:
    """BGR image with the EXIF orientation applied (phones store photos rotated)."""
    import io

    source = io.BytesIO(data) if isinstance(data, bytes) else data
    with Image.open(source) as image:
        image = ImageOps.exif_transpose(image).convert("RGB")
        return cv2.cvtColor(np.asarray(image), cv2.COLOR_RGB2BGR)


def order_points(points: np.ndarray) -> np.ndarray:
    """Top-left, top-right, bottom-right, bottom-left."""
    sums = points.sum(axis=1)
    diffs = np.diff(points, axis=1).ravel()

    return np.float32([points[np.argmin(sums)], points[np.argmin(diffs)], points[np.argmax(sums)], points[np.argmax(diffs)]])


def _foreground_mask(small: np.ndarray, blur: int) -> np.ndarray:
    """Whatever does not look like the surface around the edge of the photo."""
    lab = cv2.cvtColor(cv2.GaussianBlur(small, (0, 0), blur), cv2.COLOR_BGR2LAB)
    frame = 20
    border = np.concatenate([
        lab[:frame].reshape(-1, 3), lab[-frame:].reshape(-1, 3),
        lab[:, :frame].reshape(-1, 3), lab[:, -frame:].reshape(-1, 3),
    ])
    # The median stays right even when a card runs up to the edge of the photo.
    background = np.median(border, axis=0)
    distance = cv2.normalize(np.linalg.norm(lab.astype(np.float32) - background, axis=2), None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)

    _, mask = cv2.threshold(distance, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (9, 9))
    mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel)

    return cv2.morphologyEx(mask, cv2.MORPH_OPEN, kernel)


def _sides(quad: np.ndarray) -> tuple[float, float]:
    width = (np.linalg.norm(quad[1] - quad[0]) + np.linalg.norm(quad[2] - quad[3])) / 2
    height = (np.linalg.norm(quad[3] - quad[0]) + np.linalg.norm(quad[2] - quad[1])) / 2

    return float(width), float(height)


def _ratio(quad: np.ndarray) -> float:
    width, height = _sides(quad)

    return min(width, height) / max(width, height)


def _is_card(quad: np.ndarray) -> bool:
    return RATIO_RANGE[0] <= _ratio(quad) <= RATIO_RANGE[1]


def _blobs(small: np.ndarray, blur: int, min_area: float) -> list[np.ndarray]:
    """Rectangular regions that differ from the surface, as ordered quadrilaterals."""
    contours, _ = cv2.findContours(_foreground_mask(small, blur), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    quads = []

    for contour in contours:
        area = cv2.contourArea(contour)
        if area < min_area:
            continue

        (_, _), (rect_w, rect_h), _ = rect = cv2.minAreaRect(contour)
        if min(rect_w, rect_h) > 0 and area / (rect_w * rect_h) >= MIN_FILL:
            quads.append(order_points(cv2.boxPoints(rect)))

    return quads


def _grid(quad: np.ndarray, columns: int, rows: int) -> list[np.ndarray]:
    """Cuts a quadrilateral into columns x rows equal cells, following its own tilt."""
    top = lambda t: quad[0] + (quad[1] - quad[0]) * t  # noqa: E731
    bottom = lambda t: quad[3] + (quad[2] - quad[3]) * t  # noqa: E731

    def point(u: float, v: float) -> np.ndarray:
        return top(u) + (bottom(u) - top(u)) * v

    return [
        np.float32([point(c / columns, r / rows), point((c + 1) / columns, r / rows), point((c + 1) / columns, (r + 1) / rows), point(c / columns, (r + 1) / rows)])
        for r in range(rows)
        for c in range(columns)
    ]


def _split_touching(blobs: list[np.ndarray]) -> list[np.ndarray]:
    """Cards in sleeves touch, so a blob may hold several: cut it into the grid that gives card-shaped cells."""
    single = [quad for quad in blobs if _is_card(quad)]
    reference = float(np.median([cv2.contourArea(quad) for quad in single])) if single else None
    cards = []

    for quad in blobs:
        area = cv2.contourArea(quad)

        # Judged against the other cards when there are some, else against the card ratio alone.
        best = (1, 1)
        best_error = abs(_ratio(quad) - CARD_RATIO) if reference is None else abs(np.log(area / reference))
        for columns in range(1, 6):
            for rows in range(1, 6):
                if columns * rows == 1:
                    continue
                cell = _grid(quad, columns, rows)[0]
                if not _is_card(cell):
                    continue
                error = abs(_ratio(cell) - CARD_RATIO) if reference is None else abs(np.log(cv2.contourArea(cell) / reference))
                if error + (0.0 if reference is None else 0.05) < best_error:
                    best, best_error = (columns, rows), error

        if best == (1, 1):
            if _is_card(quad):
                cards.append(quad)
        else:
            cards.extend(_grid(quad, *best))

    return cards


def detect_cards(photo: np.ndarray, min_area_ratio: float = 0.01) -> Detection:
    height, width = photo.shape[:2]
    scale = 1000 / max(height, width)
    small = cv2.resize(photo, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA)
    min_area = min_area_ratio * small.shape[0] * small.shape[1]

    best: list[np.ndarray] = []
    for blur in BLURS:
        cards = _split_touching(_blobs(small, blur, min_area))

        # Drop specks: cards of one photo have about the same size.
        if cards:
            median = np.median([cv2.contourArea(quad) for quad in cards])
            cards = [quad for quad in cards if cv2.contourArea(quad) >= 0.4 * median]

        if len(cards) > len(best):
            best = cards

    return Detection(quads=_reading_order([quad / scale for quad in best]))


def _reading_order(quads: list[np.ndarray]) -> list[np.ndarray]:
    if not quads:
        return quads

    centers = [quad.mean(axis=0) for quad in quads]
    row_height = np.median([np.linalg.norm(quad[3] - quad[0]) for quad in quads]) / 2

    return [quads[i] for i in sorted(range(len(quads)), key=lambda i: (round(centers[i][1] / row_height), centers[i][0]))]


def warp_card(photo: np.ndarray, quad: np.ndarray) -> np.ndarray:
    """Upright portrait crop of CARD_W x CARD_H. Upside-down is not resolved here."""
    width, height = _sides(quad)
    source = np.roll(quad, 1, axis=0) if width > height else quad

    # Warp at twice the size then shrink, to avoid aliasing on high resolution photos.
    destination = np.float32([[0, 0], [2 * CARD_W - 1, 0], [2 * CARD_W - 1, 2 * CARD_H - 1], [0, 2 * CARD_H - 1]])
    matrix = cv2.getPerspectiveTransform(source.astype(np.float32), destination)
    warped = cv2.warpPerspective(photo, matrix, (2 * CARD_W, 2 * CARD_H), flags=cv2.INTER_CUBIC)

    return cv2.resize(warped, (CARD_W, CARD_H), interpolation=cv2.INTER_AREA)
