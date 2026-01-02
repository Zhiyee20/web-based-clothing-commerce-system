document.addEventListener("DOMContentLoaded", function () {
    console.log("✅ admin_product.js Loaded");

    const addProductBtn = document.getElementById("addProductBtn");
    const addProductModal = document.getElementById("addProductModal");
    const editProductModal = document.getElementById("editProductModal");

    const addProductForm = document.getElementById("addProductForm");
    const editProductForm = document.getElementById("editProductForm");
    const productTable = document.getElementById("productTable");

    const editColorBlocksWrap = document.getElementById("editColorBlocks");
    const editAddColorBlockBtn = document.getElementById("editAddColorBlockBtn");

    const colorBlocksWrap = document.getElementById("colorBlocks");
    const addColorBlockBtn = document.getElementById("addColorBlockBtn");

    /* ----------------------------------------------------------------
       Helpers – Color Blocks (Add + Edit)
    ---------------------------------------------------------------- */

    function wireColorBlockCommon(block, blocksWrapper) {
        const removeBtn = block.querySelector(".remove-color-block");
        if (removeBtn) {
            removeBtn.addEventListener("click", function () {
                const allBlocks = blocksWrapper.querySelectorAll(".color-block");
                if (allBlocks.length > 1) {
                    block.remove();
                } else {
                    const nameInput = block.querySelector("input[name^='ColorNames'], input[name^='EditColorNames']");
                    const codeInput = block.querySelector("input[name^='ColorCodes'], input[name^='EditColorCodes']");
                    const mainInput = block.querySelector("input[name^='ColorMainPhoto'], input[name^='EditColorMainPhoto']");
                    const extraInput = block.querySelector("input[name^='ColorExtraPhotos'], input[name^='EditColorExtraPhotos']");

                    if (nameInput) nameInput.value = "";
                    if (codeInput) codeInput.value = "#ffffff";
                    if (mainInput) mainInput.value = "";
                    if (extraInput) extraInput.value = "";

                    // also clear per-size stock/min if exists
                    block.querySelectorAll(".size-input").forEach(inp => inp.value = "");
                }
            });
        }
    }

    // ADD: color block
    function createAddColorBlock(index) {
        const block = document.createElement("div");
        block.className = "color-block";
        block.dataset.colorIndex = String(index);

        block.innerHTML = `
          <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
            <label style="margin:0;white-space:nowrap;">Color Name:</label>
            <input type="text"
                   name="ColorNames[]"
                   placeholder="e.g. Beige"
                   required
                   style="flex:1;padding:8px 10px;font-size:14px;">
            <button type="button"
                    class="remove-color-block"
                    title="Remove this color"
                    style="border:none;background:#eee;color:#c00;border-radius:50%;
                           width:20px;height:20px;font-size:12px;line-height:20px;
                           display:flex;align-items:center;justify-content:center;
                           flex:0 0 auto;cursor:pointer;">
              ✕
            </button>
          </div>

          <label style="margin-top:6px;">Color Swatch:</label>
          <div class="color-swatch-row">
            <input type="color"
                   name="ColorCodes[]"
                   value="#ffffff"
                   class="color-swatch-input">
          </div>

          <label style="margin-top:6px;">Main Photo (this color):</label>
          <input type="file" name="ColorMainPhoto[]" accept="image/*" required>

          <label style="margin-top:6px;">Extra Photos (optional, this color):</label>
          <input type="file" name="ColorExtraPhotos[${index}][]" accept="image/*" multiple>

          <!-- Sizes & stock/min-stock for this color -->
          <div class="size-stock-wrapper">
            <p class="size-title">Sizes &amp; Stock (this color):</p>

            <div class="size-grid">
              <!-- header row -->
              <div class="size-label"></div>
              <div class="size-header">XS</div>
              <div class="size-header">S</div>
              <div class="size-header">M</div>
              <div class="size-header">L</div>
              <div class="size-header">XL</div>

              <!-- Stock row -->
              <div class="size-label">Stock</div>
              <input type="number" name="SizeStock[${index}][XS]" min="0" class="size-input">
              <input type="number" name="SizeStock[${index}][S]"  min="0" class="size-input">
              <input type="number" name="SizeStock[${index}][M]"  min="0" class="size-input">
              <input type="number" name="SizeStock[${index}][L]"  min="0" class="size-input">
              <input type="number" name="SizeStock[${index}][XL]" min="0" class="size-input">

              <!-- Min stock row -->
              <div class="size-label">Min</div>
              <input type="number" name="SizeMinStock[${index}][XS]" min="0" class="size-input">
              <input type="number" name="SizeMinStock[${index}][S]"  min="0" class="size-input">
              <input type="number" name="SizeMinStock[${index}][M]"  min="0" class="size-input">
              <input type="number" name="SizeMinStock[${index}][L]"  min="0" class="size-input">
              <input type="number" name="SizeMinStock[${index}][XL]" min="0" class="size-input">
            </div>

            <small class="size-hint">
              Leave blank = treat as 0. These are per color per size.
            </small>
          </div>
        `;

        wireColorBlockCommon(block, colorBlocksWrap);
        return block;
    }

    // EDIT: color block – now takes name AND pre-selected color code
    // (size inputs are view-only; real editing via admin_stock.php)
    function createEditColorBlock(index, nameValue, codeValue) {
        const block = document.createElement("div");
        block.className = "color-block";
        block.dataset.colorIndex = String(index);

        const safeName = (nameValue || "").replace(/"/g, "&quot;");
        let safeCode = (codeValue || "").trim();
        if (!safeCode) safeCode = "#ffffff";
        if (safeCode[0] !== "#") safeCode = "#" + safeCode;

        block.innerHTML = `
      <div style="border:1px solid #eee;padding:8px;border-radius:6px;margin-bottom:6px;">
        <div style="display:flex;gap:8px;align-items:center;">
          <label style="margin:0;white-space:nowrap;">Color Name:</label>
          <input type="text"
                 name="EditColorNames[]"
                 value="${safeName}"
                 placeholder="e.g. Beige"
                 style="flex:1;padding:8px 10px;font-size:14px;">
          <button type="button"
                  class="remove-color-block"
                  title="Remove this color"
                  style="border:none;background:#eee;color:#c00;border-radius:50%;
                         width:20px;height:20px;font-size:12px;line-height:20px;
                         display:flex;align-items:center;justify-content:center;
                         flex:0 0 auto;cursor:pointer;">
            ✕
          </button>
        </div>

        <label style="margin-top:6px;">Color Swatch:</label>
        <div class="color-swatch-row">
          <input type="color"
                 name="EditColorCodes[]"
                 value="${safeCode}"
                 class="color-swatch-input">
        </div>

        <label style="margin-top:6px;">Main Photo (this color, optional):</label>
        <input type="file" name="EditColorMainPhoto[]" accept="image/*">

        <label style="margin-top:6px;">Extra Photos (optional, this color):</label>
        <input type="file" name="EditColorExtraPhotos[${index}][]" accept="image/*" multiple>

        <label style="margin-top:6px;">Current Photos (this color):</label>
        <div class="color-current-photos"></div>

        <!-- Sizes & stock/min-stock for this color (edit, view-only) -->
        <div class="size-stock-wrapper" style="margin-top:8px;">
          <div class="size-title-row" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <p class="size-title" style="margin:0;">Sizes &amp; Stock (this color):</p>
            <a
              href="/admin/admin_stock.php"
              class="size-edit-link"
              data-pid=""
              data-color=""
              data-size=""
              title="Edit stock in Stock Management"
              target="_blank"
              style="display:inline-flex;align-items:center;justify-content:center;
                     width:20px;height:20px;border-radius:50%;border:none;
                     background:#eee;color:#444;font-size:12px;line-height:20px;
                     text-decoration:none;cursor:pointer;">
              ✏️
            </a>
          </div>

          <div class="size-grid">
            <div class="size-label"></div>
            <div class="size-header">XS</div>
            <div class="size-header">S</div>
            <div class="size-header">M</div>
            <div class="size-header">L</div>
            <div class="size-header">XL</div>

            <div class="size-label">Stock</div>
            <input type="number" name="EditSizeStock[${index}][XS]" class="size-input" readonly disabled>
            <input type="number" name="EditSizeStock[${index}][S]"  class="size-input" readonly disabled>
            <input type="number" name="EditSizeStock[${index}][M]"  class="size-input" readonly disabled>
            <input type="number" name="EditSizeStock[${index}][L]"  class="size-input" readonly disabled>
            <input type="number" name="EditSizeStock[${index}][XL]" class="size-input" readonly disabled>

            <div class="size-label">Min</div>
            <input type="number" name="EditMinStock[${index}][XS]" class="size-input" readonly disabled>
            <input type="number" name="EditMinStock[${index}][S]"  class="size-input" readonly disabled>
            <input type="number" name="EditMinStock[${index}][M]"  class="size-input" readonly disabled>
            <input type="number" name="EditMinStock[${index}][L]"  class="size-input" readonly disabled>
            <input type="number" name="EditMinStock[${index}][XL]" class="size-input" readonly disabled>
          </div>

          <small class="size-hint">
            To edit stock and min stock, please go to Stock Management.
          </small>
        </div>
      </div>
    `;

        wireColorBlockCommon(block, editColorBlocksWrap);
        return block;
    }

    /* ----------------------------------------------------------------
       Modal open / close
    ---------------------------------------------------------------- */

    addProductBtn?.addEventListener("click", function () {
        if (addProductForm) addProductForm.reset();

        if (colorBlocksWrap) {
            colorBlocksWrap.innerHTML = "";
            const firstBlock = createAddColorBlock(0);
            colorBlocksWrap.appendChild(firstBlock);
        }

        if (addProductModal) addProductModal.style.display = "block";
    });

    document.querySelectorAll(".close").forEach(button => {
        button.addEventListener("click", function () {
            if (addProductModal) addProductModal.style.display = "none";
            if (editProductModal) editProductModal.style.display = "none";
        });
    });

    window.addEventListener("click", function (event) {
        if (event.target.classList.contains("modal")) {
            event.target.style.display = "none";
        }
    });

    /* ----------------------------------------------------------------
       Add Product – add color
    ---------------------------------------------------------------- */

    addColorBlockBtn?.addEventListener("click", function () {
        if (!colorBlocksWrap) return;

        let nextIndex = 0;
        colorBlocksWrap.querySelectorAll(".color-block").forEach(b => {
            const idx = parseInt(b.dataset.colorIndex || "0", 10);
            if (!Number.isNaN(idx) && idx >= nextIndex) {
                nextIndex = idx + 1;
            }
        });

        const block = createAddColorBlock(nextIndex);
        colorBlocksWrap.appendChild(block);
    });

    /* ----------------------------------------------------------------
       Add Product – submit
    ---------------------------------------------------------------- */

    addProductForm?.addEventListener("submit", function (event) {
        event.preventDefault();

        const formData = new FormData(this);
        formData.set("action", "add");

        fetch("admin_product_process.php", {
            method: "POST",
            body: formData,
        })
            .then(response => response.text())
            .then(text => JSON.parse(text))
            .then(data => {
                if (data.success) {
                    alert("✅ Product added successfully!");
                    fetch("http://127.0.0.1:8000/train", { method: "POST" })
                        .then(res => res.json())
                        .then(json => console.log("Training log:", json.log))
                        .catch(err => console.error("Training failed", err));

                    location.reload();
                } else {
                    alert("❌ Error: " + (data.message || "Unknown error"));
                }
            })
            .catch(error => {
                console.error("❌ Error:", error);
                alert("❌ An unexpected error occurred while adding the product.");
            });
    });

    /* ----------------------------------------------------------------
       Render existing images PER COLOR
    ---------------------------------------------------------------- */

    function renderEditImagesPerColor(images) {
        if (!editColorBlocksWrap) return;

        const blocks = editColorBlocksWrap.querySelectorAll(".color-block");

        if (!images || !images.length) {
            blocks.forEach(block => {
                const holder = block.querySelector(".color-current-photos");
                if (holder) {
                    holder.innerHTML = "<p style='font-size:0.85rem;color:#666;'>No photos yet for this color.</p>";
                }
            });
            return;
        }

        const map = {};
        images.forEach(img => {
            const key = (img.ColorName || "").toLowerCase().trim() || "__generic";
            (map[key] = map[key] || []).push(img);
        });

        blocks.forEach(block => {
            const holder = block.querySelector(".color-current-photos");
            if (!holder) return;

            const nameInput = block.querySelector("input[name='EditColorNames[]']");
            const key = nameInput
                ? (nameInput.value || "").toLowerCase().trim() || "__generic"
                : "__generic";

            const imgs = map[key] || [];
            holder.innerHTML = "";

            if (!imgs.length) {
                holder.innerHTML = "<p style='font-size:0.85rem;color:#666;'>No photos yet for this color.</p>";
                return;
            }

            const frag = document.createDocumentFragment();

            imgs.forEach(img => {
                const wrap = document.createElement("div");
                wrap.className = "admin-img-thumb";

                const imageEl = document.createElement("img");
                imageEl.src = "/uploads/" + (img.ImagePath || "default.jpg");
                imageEl.alt = img.ImagePath || "";
                imageEl.width = 70;
                imageEl.height = 70;
                imageEl.style.objectFit = "cover";
                imageEl.loading = "lazy";

                const meta = document.createElement("div");
                meta.className = "admin-img-meta";
                meta.textContent = img.IsPrimary ? "Primary" : "";

                const delBtn = document.createElement("button");
                delBtn.type = "button";
                delBtn.textContent = "✕";
                delBtn.className = "img-delete-btn";
                delBtn.style.cssText = "margin-left:4px;padding:1px 5px;font-size:11px;";

                delBtn.addEventListener("click", function () {
                    if (!img.ImageID) {
                        wrap.remove();
                        return;
                    }
                    if (!confirm("Delete this image?")) return;

                    const body = new URLSearchParams();
                    body.append("action", "delete_image");
                    body.append("ImageID", String(img.ImageID));

                    fetch("admin_product_process.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: body.toString()
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                wrap.remove();

                                fetch("http://127.0.0.1:8000/train", { method: "POST" })
                                    .then(res => res.json())
                                    .then(json => console.log("Training log:", json.log))
                                    .catch(err => console.error("Training failed", err));

                                location.reload();
                            } else {
                                alert("❌ Failed to delete image: " + (data.message || "Unknown error"));
                            }
                        })
                        .catch(err => {
                            console.error("❌ Error deleting image:", err);
                            alert("❌ Error deleting image.");
                        });
                });

                wrap.appendChild(imageEl);
                if (img.IsPrimary) wrap.appendChild(meta);
                wrap.appendChild(delBtn);

                frag.appendChild(wrap);
            });

            holder.appendChild(frag);
        });
    }

    /* ----------------------------------------------------------------
       Edit Product – open modal
    ---------------------------------------------------------------- */

    productTable?.addEventListener("click", function (event) {
        const btn = event.target.closest("button.editBtn, a.editBtn, .editBtn");
        if (!btn) return;

        // If user clicked the stock ✏️ link, do NOT open edit modal
        if (event.target.closest(".size-edit-link")) return;

        if (!btn) return;

        const productID = btn.dataset.id;
        const editProductID = document.getElementById("editProductID");
        const editName = document.getElementById("editName");
        const editDescription = document.getElementById("editDescription");
        const editPrice = document.getElementById("editPrice");
        const editStock = document.getElementById("editStock");
        const editCategory = document.getElementById("editCategoryID");
        const editGender = document.getElementById("editTargetGender");

        if (editProductID) editProductID.value = productID || "";
        if (editName) editName.value = btn.dataset.name || "";
        if (editDescription) editDescription.value = btn.dataset.description || "";
        if (editPrice) editPrice.value = btn.dataset.price || "";
        if (editStock) editStock.value = btn.dataset.stock || "";
        if (editCategory) editCategory.value = btn.dataset.category || "";

        if (editGender) {
            const g = btn.dataset.gender || "Unisex";
            editGender.value = (g === "Male" || g === "Female" || g === "Unisex") ? g : "Unisex";
        }

        // ---------- NEW: parse size JSON ----------
        let sizeMap = {};
        try {
            sizeMap = btn.dataset.sizes ? JSON.parse(btn.dataset.sizes) : {};
        } catch (e) {
            console.error("❌ Invalid size JSON on editBtn:", e);
            sizeMap = {};
        }

        // Build color blocks from names + codes
        if (editColorBlocksWrap) {
            editColorBlocksWrap.innerHTML = "";
            let editIndex = 0;

            const colorsRaw = btn.dataset.colors || "";
            const codesRaw = btn.dataset.colorcodes || "";

            const colorNames = colorsRaw
                .split(",")
                .map(s => s.trim())
                .filter(Boolean);

            const colorCodes = codesRaw
                .split(",")
                .map(s => s.trim())
                .filter(Boolean);

            if (colorNames.length) {
                colorNames.forEach((name, i) => {
                    const code = colorCodes[i] || "#ffffff";
                    const block = createEditColorBlock(editIndex, name, code);
                    editColorBlocksWrap.appendChild(block);

                    // 👉 Prefill size stock/min for this color
                    const colorData = sizeMap[name] || {};   // { XS:{stock,min}, S:{...}, ... }
                    const sizes = ["XS", "S", "M", "L", "XL"];

                    const stockInputs = block.querySelectorAll("input[name^='EditSizeStock']");
                    const minInputs = block.querySelectorAll("input[name^='EditMinStock']");

                    sizes.forEach((sz, idx) => {
                        const d = colorData[sz];
                        if (d) {
                            if (stockInputs[idx]) stockInputs[idx].value = d.stock ?? "";
                            if (minInputs[idx]) minInputs[idx].value = d.min ?? "";
                        }
                    });

                    // ✅ STEP 2: set Stock Management deep-link (Product + Color + optional Size)
                    const link = block.querySelector(".size-edit-link");
                    if (link) {
                        const params = new URLSearchParams();
                        params.set("product", productID);
                        params.set("color", name);

                        // optional: first available size
                        const firstSize = sizes.find(sz => colorData[sz] != null);
                        if (firstSize) params.set("size", firstSize);

                        link.href = "/admin/admin_stock.php?" + params.toString();
                    }

                    editIndex++;
                });
            } else {
                const block = createEditColorBlock(0, "", "#ffffff");
                editColorBlocksWrap.appendChild(block);
                editIndex = 1;
            }

            editColorBlocksWrap.dataset.nextIndex = String(editIndex);
        }

        // Load existing images -> then render per color
        if (productID) {
            fetch("admin_product_images.php?ProductID=" + encodeURIComponent(productID))
                .then(r => r.text())
                .then(text => JSON.parse(text))
                .then(data => {
                    if (data.success) {
                        renderEditImagesPerColor(data.images || []);
                    }
                })
                .catch(err => {
                    console.error("❌ Error loading images:", err);
                });
        }

        if (editProductModal) editProductModal.style.display = "block";
    });

    /* ----------------------------------------------------------------
       Edit Product – add color block
    ---------------------------------------------------------------- */

    editAddColorBlockBtn?.addEventListener("click", function () {
        if (!editColorBlocksWrap) return;

        let nextIndex = parseInt(editColorBlocksWrap.dataset.nextIndex || "0", 10);
        const block = createEditColorBlock(nextIndex, "", "#ffffff");
        editColorBlocksWrap.appendChild(block);
        nextIndex++;
        editColorBlocksWrap.dataset.nextIndex = String(nextIndex);
    });

    /* ----------------------------------------------------------------
       Edit Product – submit
    ---------------------------------------------------------------- */

    editProductForm?.addEventListener("submit", function (event) {
        event.preventDefault();

        const formData = new FormData(this);
        formData.set("action", "edit");

        fetch("admin_product_process.php", {
            method: "POST",
            body: formData,
        })
            .then(response => response.text())
            .then(text => JSON.parse(text))
            .then(data => {
                if (data.success) {
                    alert("✅ Product updated successfully!");
                    fetch("http://127.0.0.1:8000/train", { method: "POST" })
                        .then(res => res.json())
                        .then(json => console.log("Training log:", json.log))
                        .catch(err => console.error("Training failed", err));

                    location.reload();
                } else {
                    alert("❌ Error: " + (data.message || "Unknown error"));
                }
            })
            .catch(error => {
                console.error("❌ Error:", error);
                alert("❌ An unexpected error occurred while updating the product.");
            });
    });

    /* ----------------------------------------------------------------
       Delete Product
    ---------------------------------------------------------------- */

    productTable?.addEventListener("click", function (event) {
        const btn = event.target.closest(".deleteBtn");
        if (!btn) return;

        const productID = btn.dataset.id;
        if (!productID) {
            console.error("❌ Product ID is missing!");
            return;
        }

        if (confirm("🗑️ Are you sure you want to delete this product?")) {
            fetch("admin_product_process.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: `action=delete&ProductID=${encodeURIComponent(productID)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert("✅ Product deleted!");
                        location.reload();
                    } else {
                        alert("❌ Error: " + (data.message || "Unknown error"));
                    }
                })
                .catch(error => {
                    console.error("❌ Error:", error);
                    alert("❌ An unexpected error occurred while deleting the product.");
                });
        }
    });

    // Restore (reactivate) soft-deleted product
    document.querySelectorAll('.restoreBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            if (!confirm('Reactivate this product?')) return;

            const fd = new FormData();
            fd.append('action', 'restore');
            fd.append('ProductID', id);

            fetch('admin_product_process.php', {
                method: 'POST',
                body: fd
            })
                .then(res => res.json())
                .then(data => {
                    alert(data.message || 'Done');
                    if (data.success) location.reload();
                });
        });
    });

    /* ----------------------------------------------------------------
       Init – wire first ADD color-block from PHP markup
    ---------------------------------------------------------------- */
    if (colorBlocksWrap) {
        const existing = colorBlocksWrap.querySelectorAll(".color-block");
        if (existing.length) {
            existing.forEach(block => wireColorBlockCommon(block, colorBlocksWrap));
            const first = existing[0];
            first.dataset.colorIndex = "0";
            const extra = first.querySelector("input[name^='ColorExtraPhotos']");
            if (extra) extra.name = "ColorExtraPhotos[0][]";
        }
    }
});

/* ==========================================================
   Add Category inside Add/Edit Product modals
   ========================================================== */

// Elements
const addCategoryModal = document.getElementById('addCategoryModal');
const closeAddCategory = document.querySelector('.close-add-category');
const btnAddCatFromAdd = document.getElementById('openAddCategoryModal');
const btnAddCatFromEdit = document.getElementById('openEditCategoryModal');
const inputCatName = document.getElementById('newCategoryName');
const selectSizeGuide = document.getElementById('newSizeGuideGroup');
const btnSaveCategory = document.getElementById('saveCategoryBtn');
const selectAddCategory = document.getElementById('addCategorySelect');
const selectEditCategory = document.getElementById('editCategoryID');

// track which form opened the modal: 'add' or 'edit'
let categoryTarget = null;

function openCategoryModal(target) {
    categoryTarget = target;    // 'add' or 'edit'
    if (addCategoryModal) {
        addCategoryModal.style.display = 'block';
        inputCatName.value = '';
        selectSizeGuide.value = '';
        inputCatName.focus();
    }
}

function closeCategoryModal() {
    if (addCategoryModal) {
        addCategoryModal.style.display = 'none';
    }
}

if (btnAddCatFromAdd) {
    btnAddCatFromAdd.addEventListener('click', function () {
        openCategoryModal('add');
    });
}

if (btnAddCatFromEdit) {
    btnAddCatFromEdit.addEventListener('click', function () {
        openCategoryModal('edit');
    });
}

if (closeAddCategory) {
    closeAddCategory.addEventListener('click', closeCategoryModal);
}

// Also close when clicking outside modal content (if your other modals do similar you can keep this)
window.addEventListener('click', function (e) {
    if (e.target === addCategoryModal) {
        closeCategoryModal();
    }
});

// Save new category via AJAX
if (btnSaveCategory) {
    btnSaveCategory.addEventListener('click', function () {
        const name = inputCatName.value.trim();
        const sgg = selectSizeGuide.value; // may be ''

        if (!name) {
            alert('Please enter a category name.');
            inputCatName.focus();
            return;
        }

        const formData = new FormData();
        formData.append('CategoryName', name);
        formData.append('SizeGuideGroup', sgg);

        fetch('/admin/ajax_add_category.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (!data.ok) {
                    alert(data.error || 'Failed to add category.');
                    return;
                }

                const newId = data.CategoryID;
                const newName = data.CategoryName;

                // add option into Add Product select
                if (selectAddCategory) {
                    const opt1 = new Option(newName, newId);
                    selectAddCategory.add(opt1);
                }

                // add option into Edit Product select
                if (selectEditCategory) {
                    const opt2 = new Option(newName, newId);
                    selectEditCategory.add(opt2);
                }

                // set selected depending on which form triggered
                if (categoryTarget === 'add' && selectAddCategory) {
                    selectAddCategory.value = newId;
                } else if (categoryTarget === 'edit' && selectEditCategory) {
                    selectEditCategory.value = newId;
                }

                closeCategoryModal();
            })
            .catch(err => {
                console.error(err);
                alert('Error adding category.');
            });
    });
}
