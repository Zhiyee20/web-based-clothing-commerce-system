<?php
include 'header.php';  // header.php should already require config.php and start session

// Current logged-in user (if any)
$_user = $_SESSION['user'] ?? null;

$errors = [];
$success = null;

// Preserve form values on error
$complaint_type = $_POST['complaint_type'] ?? '';
$subject        = $_POST['subject']        ?? '';
$orderID        = $_POST['order_id']       ?? '';
$message        = $_POST['message']        ?? '';

// ─────────────────────────────────────────────
// Handle Complaint / Service Form Submission
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_complaint'])) {

    // Require login so we can link to feedback.UserID (NOT NULL)
    if (!$_user) {
        $errors['login'] = 'Please log in to submit a complaint or service request.';
    }

    $complaint_type = trim($complaint_type);
    $subject        = trim($subject);
    $orderID        = trim($orderID);
    $message        = trim($message);

    if ($complaint_type === '') {
        $errors['complaint_type'] = 'Please select a category.';
    }
    if ($subject === '') {
        $errors['subject'] = 'Subject is required.';
    }
    if ($message === '') {
        $errors['message'] = 'Please describe your issue or request.';
    }

    if (!$errors && $_user) {
        // Map complaint category to feedback.Type enum('Product','Service','Suggestion')
        $fbType = 'Service';
        if ($complaint_type === 'Product')    $fbType = 'Product';
        if ($complaint_type === 'Suggestion') $fbType = 'Suggestion';

        // Combine details into one text column
        $fullText = "Subject: {$subject}\n"
                  . "Category: {$complaint_type}\n";
        if ($orderID !== '') {
            $fullText .= "Order ID: {$orderID}\n";
        }
        $fullText .= "\nMessage:\n{$message}";

        try {
            $stmt = $pdo->prepare("
                INSERT INTO feedback (UserID, Type, FeedbackText, Rating)
                VALUES (:uid, :type, :text, NULL)
            ");
            $stmt->execute([
                ':uid'  => $_user['UserID'],
                ':type' => $fbType,
                ':text' => $fullText
            ]);

            $success = '✅ Your complaint / request has been submitted. Our team will get back to you soon.';

            // Clear form values after success
            $complaint_type = '';
            $subject        = '';
            $orderID        = '';
            $message        = '';
        } catch (Exception $e) {
            $errors['general'] = 'An error occurred while submitting your request. Please try again later.';
        }
    }
}

// ─────────────────────────────────────────────
// Load FAQs from faq table (visible only, with pagination)
// ─────────────────────────────────────────────
$faqs = [];
$faqPerPage = 5; // how many FAQ items per page
$faqPage = isset($_GET['faq_page']) ? max(1, (int)$_GET['faq_page']) : 1;
$faqOffset = ($faqPage - 1) * $faqPerPage;
$faqTotal = 0;
$faqTotalPages = 1;

try {
    // Count visible FAQs
    $stmt = $pdo->query("SELECT COUNT(*) FROM faq WHERE IsHidden = 0");
    $faqTotal = (int)$stmt->fetchColumn();
    $faqTotalPages = max(1, (int)ceil($faqTotal / $faqPerPage));

    // Fetch visible FAQs only
    $stmt = $pdo->prepare("
        SELECT FAQID, Question, Answer
        FROM faq
        WHERE IsHidden = 0
        ORDER BY FAQID ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $faqPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $faqOffset, PDO::PARAM_INT);
    $stmt->execute();
    $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // silently ignore or log
}

?>

<style>
  /* ===============================
     Contact Page Custom Styling
     =============================== */

  body, .contact-section, .contact-info, .contact-card, .faq-card, p, label, input, textarea, select, summary, h1, h2, h3 {
    color: #000000; /* All text black */
  }

  .contact-section {
    padding: 40px 0;
  }

  .contact-hero {
    text-align: center;
    margin-bottom: 32px;
  }

  .contact-hero h1 {
    font-size: 2rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 8px;
  }

  .contact-hero p {
    font-size: 0.95rem;
    max-width: 520px;
    margin: 0 auto;
  }

  .contact-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit,minmax(260px,1fr));
    gap: 24px;
    margin-bottom: 32px;
  }

  .contact-info {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .contact-card-mini {
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    padding: 14px 16px;
    background: #ffffff;
  }

  .contact-card-mini h3 {
    margin: 0 0 6px 0;
    font-size: 1rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
  }

  .contact-info p {
    margin: 4px 0;
  }

  .contact-messages {
    max-width: 900px;
    margin: 0 auto 24px auto;
  }

  .alert {
    padding: 12px 16px;
    border-radius: 6px;
    font-size: 0.95rem;
    margin-bottom: 10px;
  }
  .alert-success {
    background: #ecfdf3;
    border: 1px solid #22c55e;
    color: #166534;
  }
  .alert-error {
    background: #fef2f2;
    border: 1px solid #f97373;
    color: #b91c1c;
  }

  .contact-extra {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
    gap: 32px;
    margin-top: 24px;
  }
  @media (max-width: 900px) {
    .contact-extra {
      grid-template-columns: 1fr;
    }
  }

  .complaint-card, .faq-card {
    background: #ffffff;
    border-radius: 10px;
    padding: 20px 24px;
    box-shadow: 0 8px 24px rgba(15,23,42,0.06);
  }

  .complaint-card h3,
  .faq-card h3 {
    margin-top: 0;
    margin-bottom: 12px;
    font-size: 1.15rem;
    color: #000000;
  }

  .contact-form-row {
    margin-bottom: 12px;
  }
  .contact-form-row label {
    display: block;
    margin-bottom: 4px;
    font-size: 0.95rem;
    font-weight: 600;
    color: #000000;
  }
  .contact-form-row input[type="text"],
  .contact-form-row select,
  .contact-form-row textarea {
    width: 100%;
    padding: 8px 10px;
    border-radius: 6px;
    border: 1px solid #ccc;
    font-size: 0.95rem;
    color: #000000;
  }
  .contact-form-row textarea {
    min-height: 110px;
    resize: vertical;
  }

  .error-text {
    display: block;
    margin-top: 4px;
    font-size: 0.8rem;
    color: #b91c1c;
  }

  /* ===== Black Button ===== */
  .btn-submit-complaint {
    display: inline-block;
    padding: 10px 18px;
    border-radius: 999px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.95rem;
    background: #000000; /* Black background */
    color: #ffffff;       /* White text */
    transition: background 0.3s ease, transform 0.2s ease;
  }

  .btn-submit-complaint:hover {
    background: #222222;  /* Slightly lighter on hover */
    transform: translateY(-1px);
  }

  .faq-list {
    margin-top: 8px;
  }
  .faq-item {
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    margin-bottom: 8px;
    padding: 6px 10px;
    background: #f9fafb;
  }
  .faq-item summary {
    cursor: pointer;
    font-weight: 600;
    font-size: 0.95rem;
    list-style: none;
    color: #000000;
  }
  .faq-item summary::-webkit-details-marker {
    display: none;
  }
  .faq-item p {
    margin: 6px 0 4px 0;
    font-size: 0.9rem;
    color: #000000;
  }

  .pagination {
    margin-top: 10px;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }
  .pagination .page {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    border: 1px solid #d1d5db;
    font-size: 0.85rem;
    text-decoration: none;
    color: #000;
  }
  .pagination .page.current {
    background: #000;
    color: #fff;
    border-color: #000;
  }

  .map-embed {
    width: 100%;
  }
</style>

<main class="contact-section">
  <div class="container">

    <!-- Hero -->
    <div class="contact-hero">
      <h1>Contact Us</h1>
      <p>
        Our Customer Care team is ready to assist with orders, delivery,
        product enquiries and service feedback.
      </p>
    </div>

    <!-- Global success/error messages -->
    <div class="contact-messages">
      <?php if ($success): ?>
        <div class="alert alert-success">
          <?= htmlspecialchars($success) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
          <strong>There were some problems with your submission:</strong>
          <ul style="margin: 6px 0 0 18px; padding-left: 0;">
            <?php foreach ($errors as $msg): ?>
              <li><?= htmlspecialchars($msg) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>

    <!-- Top grid: contact cards + map -->
    <div class="contact-grid">
      <!-- Left column: Customer Care + Address (cards) -->
      <div class="contact-info">
        <div class="contact-card-mini">
          <h3>Customer Care</h3>
          <p>Email: <a href="mailto:info@luxurystore.com">info@luxurystore.com</a></p>
          <p>Phone: <a href="tel:+60178726252">+60 17 8726 252</a></p>
          <p>
            WhatsApp:
            <a href="https://wa.me/60178726252" target="_blank" rel="noopener">
              Chat with us
            </a>
          </p>
          <p style="margin-top:8px;font-size:0.85rem;">
            Monday – Sunday, 9:00 AM – 10:00 PM (MYT)
          </p>
        </div>

        <div class="contact-card-mini">
          <h3>Store Address</h3>
          <p><strong>LUXURY STORE Sdn. Bhd. (1257553-D)</strong></p>
          <p>14-2, Lorong 100/77A, Imbi,<br>55100 Kuala Lumpur, Malaysia</p>
        </div>
      </div>

      <!-- Right column: Google / HERE Map -->
      <div class="map-embed">
        <a href="https://image.maps.ls.hereapi.com/mia/1.6/mapview?apiKey=FL1rqkI8CAmyt0Xsit06Ur04AObp0JLxUB_4FYG98WA&lat=3.2165511964097258&lon=101.72869763710223&z=14&w=1200&h=800&poi=3.2165511964097258,101.72869763710223"
           target="_blank">
        </a>
        <div id="map" style="width: 100%; height: 300px; border: 1px solid #ccc; border-radius: 8px;"></div>
      </div>
    </div>

    <!-- Complaint / Service Form + FAQ -->
    <div class="contact-extra">

      <!-- Complaint / Service Form -->
      <section class="complaint-card">
        <h3>Customer Service & Complaint Form</h3>
        <p style="font-size:0.9rem; margin-bottom:12px;">
          For any issues with your orders, deliveries, or our service, please submit the form below.
        </p>

        <?php if (!$_user): ?>
          <p style="font-size:0.9rem;">
            Please <a href="login.php">log in</a> to submit a complaint or service request so that we can link it to your account.
          </p>
        <?php else: ?>
          <form method="post" action="contact.php">
            <div class="contact-form-row">
              <label for="complaint_type">Category <span style="color:#dc2626">*</span></label>
              <select name="complaint_type" id="complaint_type">
                <option value="">-- Please select --</option>
                <option value="Order"      <?= $complaint_type === 'Order'      ? 'selected' : '' ?>>Order / Delivery Issue</option>
                <option value="Product"    <?= $complaint_type === 'Product'    ? 'selected' : '' ?>>Product Quality / Size / Others</option>
                <option value="Service"    <?= $complaint_type === 'Service'    ? 'selected' : '' ?>>Customer Service Experience</option>
                <option value="Suggestion" <?= $complaint_type === 'Suggestion' ? 'selected' : '' ?>>Suggestion / Others</option>
              </select>
              <?php if (!empty($errors['complaint_type'])): ?>
                <span class="error-text"><?= htmlspecialchars($errors['complaint_type']) ?></span>
              <?php endif; ?>
            </div>

            <div class="contact-form-row">
              <label for="order_id">Related Order ID (optional)</label>
              <input type="text" name="order_id" id="order_id"
                     value="<?= htmlspecialchars($orderID) ?>"
                     placeholder="e.g. 21">
            </div>

            <div class="contact-form-row">
              <label for="subject">Subject <span style="color:#dc2626">*</span></label>
              <input type="text" name="subject" id="subject"
                     value="<?= htmlspecialchars($subject) ?>"
                     placeholder="Short summary of your issue">
              <?php if (!empty($errors['subject'])): ?>
                <span class="error-text"><?= htmlspecialchars($errors['subject']) ?></span>
              <?php endif; ?>
            </div>

            <div class="contact-form-row">
              <label for="message">Details <span style="color:#dc2626">*</span></label>
              <textarea name="message" id="message"
                        placeholder="Please describe what happened, when it happened, and how we can help."><?= htmlspecialchars($message) ?></textarea>
              <?php if (!empty($errors['message'])): ?>
                <span class="error-text"><?= htmlspecialchars($errors['message']) ?></span>
              <?php endif; ?>
            </div>

            <button type="submit" name="submit_complaint" class="btn-submit-complaint">
              Submit Complaint / Request
            </button>
          </form>
        <?php endif; ?>
      </section>

      <!-- FAQ Section -->
      <section class="faq-card" id="faq">
        <h3>Frequently Asked Questions (FAQ)</h3>
        <p style="font-size:0.9rem; margin-bottom:8px;">
          Find quick answers to common questions about orders, delivery, and returns.
        </p>

        <?php if (!$faqs): ?>
          <p style="font-size:0.9rem;">No FAQs have been published yet.</p>
        <?php else: ?>
          <div class="faq-list">
            <?php foreach ($faqs as $faq): ?>
              <details class="faq-item">
                <summary><?= htmlspecialchars($faq['Question']) ?></summary>
                <p><?= nl2br(htmlspecialchars($faq['Answer'])) ?></p>
              </details>
            <?php endforeach; ?>
          </div>

          <?php if ($faqTotalPages > 1): ?>
            <div class="pagination">
              <?php for ($p = 1; $p <= $faqTotalPages; $p++): ?>
                <?php if ($p == $faqPage): ?>
                  <span class="page current"><?= $p ?></span>
                <?php else: ?>
                  <a class="page" href="contact.php?faq_page=<?= $p ?>#faq">
                    <?= $p ?>
                  </a>
                <?php endif; ?>
              <?php endfor; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>

    </div><!-- /.contact-extra -->

  </div>
</main>

<!-- Include HERE Maps JavaScript API -->
<script src="https://js.api.here.com/v3/3.1/mapsjs-core.js"></script>
<script src="https://js.api.here.com/v3/3.1/mapsjs-service.js"></script>
<script src="https://js.api.here.com/v3/3.1/mapsjs-ui.js"></script>
<script src="https://js.api.here.com/v3/3.1/mapsjs-mapevents.js"></script>
<link rel="stylesheet" type="text/css" href="https://js.api.here.com/v3/3.1/mapsjs-ui.css" />

<script>
  // Initialize the platform object
  const platform = new H.service.Platform({
    apikey: "FL1rqkI8CAmyt0Xsit06Ur04AObp0JLxUB_4FYG98WA"
  });

  // Get the default map types from the platform object
  const defaultLayers = platform.createDefaultLayers();

  // Initialize the map
  const map = new H.Map(
    document.getElementById('map'),
    defaultLayers.vector.normal.map, {
      zoom: 14,
      center: {
        lat: 3.144892708572713,
        lng: 101.71383156925344
      }
    }
  );

  // Add a marker for your store location
  const storeMarker = new H.map.Marker({
    lat: 3.144892708572713,
    lng: 101.71383156925344
  });
  map.addObject(storeMarker);

  // Enable map interaction (zooming, panning)
  const mapEvents = new H.mapevents.MapEvents(map);
  const behavior = new H.mapevents.Behavior(mapEvents);

  // Add the default UI components (zoom controls, etc.)
  const ui = H.ui.UI.createDefault(map, defaultLayers);

  // Popup bubble
  const storePopup = new H.ui.InfoBubble({
    lat: 3.144892708572713,
    lng: 101.71383156925344
  }, {
    content: `
      <div style="font-size: 14px; padding: 10px; max-width: 300px;">
        <b style="font-size: 16px;">Our Store</b><br>
        Luxury Store,<br>
        14-2, Lorong 100/77A, Imbi,<br>
        55100 Kuala Lumpur, Malaysia
      </div>
    `
  });
  ui.addBubble(storePopup);
</script>

<?php include 'footer.php'; ?>
