"""Turns a card image into a vector. Catalogue images and photo crops must go through the same code."""

import numpy as np
import torch

# Fraction cut on each side: removes the rounded corners and the surface visible around a crop.
MARGIN = 0.04


def inner_crop(image: np.ndarray, margin: float = MARGIN) -> np.ndarray:
    height, width = image.shape[:2]
    dy, dx = int(height * margin), int(width * margin)

    return image[dy:height - dy, dx:width - dx]


def _l2(vectors: torch.Tensor) -> np.ndarray:
    return torch.nn.functional.normalize(vectors, dim=-1).float().numpy()


class Embedder:
    """Subclasses return one L2-normalised matrix per variant name."""

    name: str

    def embed(self, images: list[np.ndarray]) -> dict[str, np.ndarray]:
        raise NotImplementedError


class Dinov2Embedder(Embedder):
    # Patch size is 14: 224 x 308 keeps the card ratio (about 0.73) without cropping.
    SIZE = (224, 308)
    MEAN = np.float32([0.485, 0.456, 0.406])
    STD = np.float32([0.229, 0.224, 0.225])

    def __init__(self, name: str, model_id: str) -> None:
        from transformers import AutoModel

        self.name = name
        self.model = AutoModel.from_pretrained(model_id).eval()

    def _pixels(self, images: list[np.ndarray]) -> torch.Tensor:
        import cv2

        batch = []
        for image in images:
            resized = cv2.resize(inner_crop(image), self.SIZE, interpolation=cv2.INTER_AREA)
            batch.append((cv2.cvtColor(resized, cv2.COLOR_BGR2RGB).astype(np.float32) / 255 - self.MEAN) / self.STD)

        return torch.from_numpy(np.stack(batch)).permute(0, 3, 1, 2).contiguous()

    @torch.inference_mode()
    def embed(self, images: list[np.ndarray]) -> dict[str, np.ndarray]:
        output = self.model(pixel_values=self._pixels(images))
        cls = _l2(output.pooler_output)
        mean = _l2(output.last_hidden_state[:, 1:].mean(dim=1))

        return {f"{self.name}-cls": cls, f"{self.name}-cls+mean": np.concatenate([cls, mean], axis=1) / np.sqrt(2)}


class ClipEmbedder(Embedder):
    SIZE = (224, 224)
    MEAN = np.float32([0.48145466, 0.4578275, 0.40821073])
    STD = np.float32([0.26862954, 0.26130258, 0.27577711])

    def __init__(self, name: str, model_id: str) -> None:
        from transformers import CLIPModel

        self.name = name
        self.model = CLIPModel.from_pretrained(model_id).eval()

    @torch.inference_mode()
    def embed(self, images: list[np.ndarray]) -> dict[str, np.ndarray]:
        import cv2

        batch = []
        for image in images:
            # CLIP expects a square input: the card is squashed rather than cropped.
            resized = cv2.resize(inner_crop(image), self.SIZE, interpolation=cv2.INTER_AREA)
            batch.append((cv2.cvtColor(resized, cv2.COLOR_BGR2RGB).astype(np.float32) / 255 - self.MEAN) / self.STD)

        pixels = torch.from_numpy(np.stack(batch)).permute(0, 3, 1, 2).contiguous()
        pooled = self.model.vision_model(pixel_values=pixels).pooler_output

        return {self.name: _l2(self.model.visual_projection(pooled))}


MODELS = {
    "dinov2-small": lambda: Dinov2Embedder("dinov2-small", "facebook/dinov2-small"),
    "dinov2-base": lambda: Dinov2Embedder("dinov2-base", "facebook/dinov2-base"),
    "clip-b32": lambda: ClipEmbedder("clip-b32", "openai/clip-vit-base-patch32"),
}
