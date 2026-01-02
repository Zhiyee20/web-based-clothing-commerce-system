# app.py
import os
import torch
import numpy as np
from fastapi import FastAPI, UploadFile, File
from fastapi.middleware.cors import CORSMiddleware
from PIL import Image
from ultralytics import YOLO
import clip
from pathlib import Path
from typing import List
from fastapi import BackgroundTasks
import subprocess

# ------------------------------
# FastAPI app
# ------------------------------
app = FastAPI(title="CLIP+YOLO Recommendation API")

# ------------------------------
# Optional CORS (for PHP frontend)
# ------------------------------
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # restrict to your frontend domain in production
    allow_methods=["*"],
    allow_headers=["*"]
)

# ------------------------------
# Paths
# ------------------------------
ROOT = Path(__file__).parent
EMB_DIR = ROOT / "data_preprocess/category_embeddings"
YOLO_WEIGHTS = ROOT / "YOLO/weights/best.pt"
UPLOAD_DIR = ROOT / "uploads"
UPLOAD_DIR.mkdir(exist_ok=True)

# ------------------------------
# Category mapping
# ------------------------------
id_to_name = {
    0: "Blouson", 1: "Dress", 2: "Jacket", 3: "Pant",
    4: "Pullover", 5: "Shirt", 6: "Trackpants", 7: "Coat"
}

# ------------------------------
# Load YOLO once
# ------------------------------
print("[INFO] Loading YOLO model...")
yolo_model = YOLO(str(YOLO_WEIGHTS))

# ------------------------------
# Load CLIP once
# ------------------------------
device = "cuda" if torch.cuda.is_available() else "cpu"
print(f"[INFO] Loading CLIP model on {device}...")
clip_model, preprocess = clip.load("ViT-B/32", device=device)
clip_model.eval()

# ------------------------------
# Load embeddings once
# ------------------------------
embeddings = {}
filenames = {}
for cat in id_to_name.values():
    emb_file = EMB_DIR / f"{cat}_embeddings.npy"
    name_file = EMB_DIR / f"{cat}_filenames.npy"
    if emb_file.exists() and name_file.exists():
        embeddings[cat] = np.load(emb_file)
        filenames[cat] = np.load(name_file)
print("[INFO] Loaded embeddings for categories:", list(embeddings.keys()))

# ------------------------------
# Helper: YOLO category prediction
# ------------------------------
def predict_category(image_path: str) -> str:
    results = yolo_model(str(image_path), imgsz=512, verbose=False, show=False)[0]
    if len(results.boxes) == 0:
        return None
    cls_id = int(results.boxes[0].cls)
    return id_to_name.get(cls_id)

# ------------------------------
# Helper: CLIP similarity search
# ------------------------------
def color_embedding(image_path: str):
    """Compute a normalized color histogram embedding."""
    img = Image.open(image_path).convert("RGB").resize((64, 64))
    arr = np.array(img)
    hist_r, _ = np.histogram(arr[:, :, 0], bins=16, range=(0, 255), density=True)
    hist_g, _ = np.histogram(arr[:, :, 1], bins=16, range=(0, 255), density=True)
    hist_b, _ = np.histogram(arr[:, :, 2], bins=16, range=(0, 255), density=True)
    hist = np.concatenate([hist_r, hist_g, hist_b])
    hist = hist / (np.linalg.norm(hist) + 1e-10)
    return hist  # shape (48,)

def recommend_similar(image_path: str, top_k: int = 4, threshold: float = 0.6):
    # Predict category
    category = predict_category(image_path)
    if category is None or category not in embeddings:
        return category, None

    embs = embeddings[category]  # shape: (N, D)
    names = filenames[category]

    # Encode CLIP
    img = preprocess(Image.open(image_path).convert("RGB")).unsqueeze(0).to(device)
    with torch.no_grad():
        clip_emb = clip_model.encode_image(img)
        clip_emb = clip_emb / clip_emb.norm(dim=-1, keepdim=True)
        clip_emb = clip_emb.cpu().numpy().flatten()  # shape (512,)

    # Compute color embedding
    col_emb = color_embedding(image_path)  # shape (48,)

    # Concatenate and normalize
    query_emb = np.concatenate([clip_emb, col_emb])
    query_emb = query_emb / (np.linalg.norm(query_emb) + 1e-10)  # shape (560,)

    # Compute similarity (cosine via dot since normalized)
    scores = np.dot(embs, query_emb)  # embs is (N, 560), query_emb is (560,)

    # Filter by threshold
    valid_idx = np.where(scores >= threshold)[0]
    if len(valid_idx) == 0:
        return category, None

    # Sort and pick top_k
    sorted_idx = valid_idx[np.argsort(scores[valid_idx])[::-1]]
    top_idx = sorted_idx[:top_k]
    top_files = [os.path.basename(names[i]) for i in top_idx]

    return category, top_files

# ------------------------------
# FastAPI endpoint: POST /search
# ------------------------------
@app.post("/search")
async def search_clip(file: UploadFile = File(...), top_k: int = 4, threshold: float = 0.6):
    temp_path = UPLOAD_DIR / file.filename
    with open(temp_path, "wb") as f:
        f.write(await file.read())

    category, top_files = recommend_similar(str(temp_path), top_k=top_k, threshold=threshold)

    if not top_files:
        return {"category": category, "message": "No product found"}

    return {"category": category, "top_similar": top_files}

# ------------------------------
# Health check
# ------------------------------
@app.get("/")
def root():
    return {"status": "running", "device": device, "categories_loaded": list(embeddings.keys())}

# ------------------------------
# Run .npy file
# ------------------------------
@app.post("/train")
async def train_embeddings(background_tasks: BackgroundTasks):
    def run_training():
        subprocess.run(["python", "data_preprocess/train_npy_embeddings.py"], shell=True)

    background_tasks.add_task(run_training)
    return {"status": "started", "message": "Training started in background"}

train_status = "idle"

@app.get("/train/status")
def status():
    return {"status": train_status}
