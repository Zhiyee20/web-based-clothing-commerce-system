import os
import torch
import numpy as np
from PIL import Image
from pathlib import Path
import clip
from tqdm import tqdm

# ------------------------------
# Paths
# ------------------------------
DATA_DIR = Path(os.path.join(os.path.dirname(os.path.dirname(__file__)), "img_category"))
EMB_DIR = "category_embeddings"
#EMB_DIR.mkdir(exist_ok=True)
os.makedirs(EMB_DIR, exist_ok=True)

# ------------------------------
# CLIP model
# ------------------------------
device = "cuda" if torch.cuda.is_available() else "cpu"
clip_model, preprocess = clip.load("ViT-B/32", device=device)
clip_model.eval()

# ------------------------------
# color embedding helper
# ------------------------------
def color_embedding(image_path):
    """
    Compute simple normalized RGB histogram as color embedding
    """
    img = Image.open(image_path).convert("RGB").resize((64, 64))
    arr = np.array(img)
    hist_r, _ = np.histogram(arr[:,:,0], bins=16, range=(0,255), density=True)
    hist_g, _ = np.histogram(arr[:,:,1], bins=16, range=(0,255), density=True)
    hist_b, _ = np.histogram(arr[:,:,2], bins=16, range=(0,255), density=True)
    hist = np.concatenate([hist_r, hist_g, hist_b])
    hist = hist / np.linalg.norm(hist)  # normalize
    return hist
# ------------------------------
# Process each category
# ------------------------------
categories = [d.name for d in DATA_DIR.iterdir() if d.is_dir()]

for cat in categories:
    print(f"[INFO] Processing category: {cat}")
    cat_dir = DATA_DIR / cat
    image_files = list(cat_dir.glob("*.*"))

    emb_list = []
    filenames = []

    for img_path in tqdm(image_files):
        try:
            # CLIP embedding
            img = preprocess(Image.open(img_path).convert("RGB")).unsqueeze(0).to(device)
            with torch.no_grad():
                clip_emb = clip_model.encode_image(img)
                clip_emb = clip_emb / clip_emb.norm(dim=-1, keepdim=True)
                clip_emb = clip_emb.cpu().numpy().flatten()

            # color embedding
            color_emb = color_embedding(img_path)

            # Combine embeddings (CLIP + color)
            combined_emb = np.concatenate([clip_emb, color_emb])
            combined_emb = combined_emb / np.linalg.norm(combined_emb)  # normalize

            emb_list.append(combined_emb)
            filenames.append(str(img_path))

        except Exception as e:
            print(f"[WARNING] Failed to process {img_path}: {e}")

    # Save embeddings and filenames
    if emb_list:
        emb_array = np.stack(emb_list)
        np.save(Path(os.path.join(EMB_DIR)) / f"{cat}_embeddings.npy", emb_array)
        np.save(Path(os.path.join(EMB_DIR)) / f"{cat}_filenames.npy", np.array(filenames))
        print(f"[INFO] Saved {len(emb_list)} embeddings for category {cat}")
