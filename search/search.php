<?php
session_start();
include '../login_base.php';
include '../user/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
<style>
  html,
  body {
    overflow-x: hidden;
  }

  .bar-container {
    width: 100%;
    margin: 20px auto;
    padding: 10px;
    text-align: center;
    display: flex;
    justify-content: center;
  }

  .search-bar-container {
    position: relative;
    display: flex;
    width: 100%;
    max-width: 600px;
  }

  .search-input {
    padding: 10px 40px 10px 20px;
    width: 100%;
    font-size: 16px;
    border: 1px solid #ccc;
    border-radius: 25px;
    box-sizing: border-box;
  }

  #camera-btn,
  #search-icon {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    font-size: 18px;
    color: #000;
    border: none;
    background: none;
  }

  #camera-btn {
    right: 35px;
  }

  #search-icon {
    right: 10px;
  }

  #camera-btn i,
  #search-icon i {
    font-size: 18px;
  }

  /* ===== Image scanning overlay ===== */
  #scan-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .55);
    display: none;
    /* toggled by JS */
    align-items: center;
    justify-content: center;
    z-index: 9999;
  }

  .scan-card {
    background: #fff;
    width: min(680px, 92vw);
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, .2);
    font-family: 'Playfair Display', serif;
  }

  .scan-header {
    font-size: 20px;
    margin: 0 0 12px;
  }

  .scan-body {
    display: flex;
    gap: 16px;
    align-items: center;
  }

  .scan-preview {
    width: 160px;
    height: 160px;
    object-fit: cover;
    border-radius: 12px;
    border: 1px solid #eee;
  }

  .scan-status {
    flex: 1;
  }

  .scan-line {
    font-size: 14px;
    color: #333;
  }

  .scan-progress {
    height: 10px;
    border-radius: 6px;
    background: #eee;
    overflow: hidden;
    margin-top: 10px;
  }

  .scan-progress::after {
    content: "";
    display: block;
    height: 100%;
    width: 0%;
    animation: prog 2.2s infinite;
    background: linear-gradient(90deg, #d1d9ff, #9fb5ff, #d1d9ff);
  }

  @keyframes prog {
    0% {
      width: 5%
    }

    50% {
      width: 85%
    }

    100% {
      width: 5%
    }
  }

  .scanner {
    position: relative;
    margin-top: 12px;
    height: 8px;
    background: #f5f7ff;
    border-radius: 999px;
    overflow: hidden;
  }

  .scanner::before {
    content: "";
    position: absolute;
    left: -30%;
    top: 0;
    height: 100%;
    width: 30%;
    background: rgba(0, 0, 0, .1);
    animation: scan 1.2s linear infinite;
  }

  @keyframes scan {
    0% {
      left: -30%
    }

    100% {
      left: 100%
    }
  }

  .scan-foot {
    font-size: 12px;
    color: #666;
    margin-top: 10px;
  }
</style>

<div class="content-container">
  <div class="search-section">
    <div class="bar-container">
      <form action="search_keyword.php" method="get" class="search-bar-container">
        <input type="text" name="query" placeholder="Search products..." id="search-bar" class="search-input" required>
        <button type="submit" id="search-icon"><i class="fa fa-search"></i></button>
        <!-- Hidden file input for image upload -->
        <input type="file" name="image" id="image-input" style="display:none;" accept="image/*" multiple>
        <button type="button" id="camera-btn"><i class="fa fa-camera"></i></button>
      </form>
    </div>
  </div>
</div>

<script>
  // Trigger hidden file input when camera icon is clicked
  document.getElementById('camera-btn').addEventListener('click', function(e) {
    e.preventDefault(); // don't submit the keyword form
    document.getElementById('image-input').click();
  });

  // Create the scanning overlay once
  function buildScanOverlay() {
    const overlay = document.createElement('div');
    overlay.id = 'scan-overlay';
    overlay.innerHTML = `
      <div class="scan-card">
        <div class="scan-header">Scanning your image…</div>
        <div class="scan-body">
          <img id="scan-preview" class="scan-preview" alt="Uploaded preview">
          <div class="scan-status">
            <div id="scan-line" class="scan-line">Matching visual features</div>
            <div class="scan-progress"></div>
            <div class="scanner"></div>
            <div class="scan-foot">Please keep this tab open while we look for similar items.</div>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    return overlay;
  }

  const scanOverlay = buildScanOverlay();

  // Handle image selection -> show overlay -> POST to search_image.php
  document.getElementById('image-input').addEventListener('change', function() {
    // No file selected
    if (!this.files || this.files.length === 0) return;

    // User selected more than one file
    if (this.files.length > 1) {
      alert('You can only upload one image for visual search.');
      this.value = ''; // clear selection
      return;
    }

    const file = this.files[0];
    const formData = new FormData();
    formData.append('image', file);

    // Preview + show overlay
    const url = URL.createObjectURL(file);
    const img = document.getElementById('scan-preview');
    img.src = url;
    scanOverlay.style.display = 'flex';

    // Animated "Scanning..." dots
    const scanLine = document.getElementById('scan-line');
    let tick = 0;
    const dotTimer = setInterval(() => {
      tick = (tick + 1) % 4;
      const dots = '.'.repeat(tick);
      scanLine.textContent = `Matching visual features${dots}`;
    }, 400);

    // Send to server
    fetch('search_image.php', {
        method: 'POST',
        body: formData
      })
      .then(r => r.text())
      .then(html => {
        // Replace entire page with server-rendered results (your existing behavior)
        document.body.innerHTML = html;
      })
      .catch(err => {
        console.error(err);
        alert('Image search failed. Please try again.');
        // Hide overlay on error (page remains)
        scanOverlay.style.display = 'none';
      })
      .finally(() => {
        clearInterval(dotTimer);
        URL.revokeObjectURL(url);
      });
  });
</script>


<?php
require __DIR__ . '../../config.php';   // ensures $pdo is defined
include __DIR__ . '/recommend.php';
?>

<?php include '../user/footer.php'; ?>