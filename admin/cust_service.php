<?php
// admin/cust_service.php — Customer Service Center (Complaints + FAQ)
require '../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$user = $_SESSION['user'] ?? null;
if (!$user || $user['Role'] !== 'Admin') {
    header('Location: ../login.php');
    exit;
}

// Small HTML escape helper
if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

/*
  Assumptions:
  - Table feedback_responses exists
  - Table faq has column IsHidden TINYINT(1) NOT NULL DEFAULT 0
*/

/* =======================================================
   State & Messages
   ======================================================= */
$success_feedback = null;
$errors_feedback  = [];

$success_faq = null;
$errors_faq  = [];

$faqEditID   = null;
$faqQuestion = '';
$faqAnswer   = '';

/* =======================================================
   Handle FAQ hide / unhide (soft delete toggle)
   ======================================================= */
if (isset($_GET['faq_action'], $_GET['faq_id'])) {
    $id  = (int) $_GET['faq_id'];
    $act = $_GET['faq_action'];
    if ($id > 0) {
        if ($act === 'hide') {
            $stmt = $pdo->prepare("UPDATE faq SET IsHidden = 1 WHERE FAQID = :id");
            $stmt->execute([':id' => $id]);
            $msg = 'faq_hidden';
        } elseif ($act === 'unhide') {
            $stmt = $pdo->prepare("UPDATE faq SET IsHidden = 0 WHERE FAQID = :id");
            $stmt->execute([':id' => $id]);
            $msg = 'faq_unhidden';
        } else {
            $msg = null;
        }

        if ($msg) {
            $extra = '';
            if (isset($_GET['faq_page'])) {
                $extra .= '&faq_page=' . (int)$_GET['faq_page'];
            }
            header("Location: cust_service.php?msg={$msg}{$extra}");
            exit;
        }
    }
}

/* =======================================================
   Handle POST actions
   - action=respond   → save complaint response
   - action=faq_save  → create / update FAQ
   ======================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ─────────── Save / update complaint response ───────────
    if ($action === 'respond') {
        $fid      = (int)($_POST['respond_feedback_id'] ?? 0);
        $response = trim($_POST['response_text'] ?? '');

        if ($fid <= 0) {
            $errors_feedback[] = 'Invalid feedback record.';
        }
        if ($response === '') {
            $errors_feedback[] = 'Response cannot be empty.';
        }

        if (!$errors_feedback) {
            $stmt = $pdo->prepare("
                INSERT INTO feedback_responses (FeedbackID, AdminUserID, ResponseText)
                VALUES (:fid, :aid, :txt)
                ON DUPLICATE KEY UPDATE
                  AdminUserID  = VALUES(AdminUserID),
                  ResponseText = VALUES(ResponseText),
                  RespondedAt  = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                ':fid' => $fid,
                ':aid' => $user['UserID'],
                ':txt' => $response,
            ]);

            $success_feedback = 'Response saved successfully.';
        }
    }

    // ─────────── Create / update FAQ ───────────
    if ($action === 'faq_save') {
        $faqEditID   = isset($_POST['faq_id']) ? (int)$_POST['faq_id'] : null;
        $faqQuestion = trim($_POST['question'] ?? '');
        $faqAnswer   = trim($_POST['answer'] ?? '');

        if ($faqQuestion === '') {
            $errors_faq[] = 'Question is required.';
        }
        if ($faqAnswer === '') {
            $errors_faq[] = 'Answer is required.';
        }

        if (!$errors_faq) {
            if ($faqEditID) {
                $stmt = $pdo->prepare("
                    UPDATE faq
                    SET Question = :q, Answer = :a
                    WHERE FAQID = :id
                ");
                $stmt->execute([
                    ':q'  => $faqQuestion,
                    ':a'  => $faqAnswer,
                    ':id' => $faqEditID,
                ]);
                $success_faq = 'FAQ updated successfully.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO faq (Question, Answer, CreatedByUserID)
                    VALUES (:q, :a, :uid)
                ");
                $stmt->execute([
                    ':q'   => $faqQuestion,
                    ':a'   => $faqAnswer,
                    ':uid' => $user['UserID'],
                ]);
                $success_faq = 'FAQ created successfully.';
                $faqQuestion = '';
                $faqAnswer   = '';
            }
        }
    }
}

/* =======================================================
   If editing FAQ via GET
   ======================================================= */
if (isset($_GET['faq_edit'])) {
    $faqEditID = (int) $_GET['faq_edit'];
    if ($faqEditID > 0) {
        $stmt = $pdo->prepare("
            SELECT FAQID, Question, Answer
            FROM faq
            WHERE FAQID = :id
        ");
        $stmt->execute([':id' => $faqEditID]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'faq_save') {
                $faqQuestion = $row['Question'];
                $faqAnswer   = $row['Answer'];
            }
        } else {
            $faqEditID = null;
        }
    }
}

/* =======================================================
   Fetch data for display
   ======================================================= */
// Complaints / feedback + response (no pagination)
$sqlFeedback = "
    SELECT
      f.FeedbackID,
      f.UserID,
      u.Username AS CustomerName,
      f.Type,
      f.FeedbackText,
      f.CreatedAt,
      r.ResponseText,
      r.RespondedAt,
      au.Username AS AdminName
    FROM feedback f
      JOIN user u ON u.UserID = f.UserID
      LEFT JOIN feedback_responses r ON r.FeedbackID = f.FeedbackID
      LEFT JOIN user au ON au.UserID = r.AdminUserID
    ORDER BY f.CreatedAt DESC
";
$stmt   = $pdo->query($sqlFeedback);
$feedbackRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FAQ pagination for admin (ALL, visible + hidden) =====
$adminFaqPerPage  = 10;
$adminFaqPage     = isset($_GET['faq_page']) ? max(1, (int)$_GET['faq_page']) : 1;
$adminFaqOffset   = ($adminFaqPage - 1) * $adminFaqPerPage;

// total FAQs
$stmt = $pdo->query("SELECT COUNT(*) FROM faq");
$adminFaqTotal      = (int)$stmt->fetchColumn();
$adminFaqTotalPages = max(1, (int)ceil($adminFaqTotal / $adminFaqPerPage));

// Fetch paginated FAQs
$stmt = $pdo->prepare("
    SELECT f.FAQID, f.Question, f.Answer, f.CreatedAt, f.UpdatedAt, f.IsHidden,
           u.Username AS CreatedBy
    FROM faq f
      LEFT JOIN user u ON u.UserID = f.CreatedByUserID
    ORDER BY f.FAQID DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $adminFaqPerPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $adminFaqOffset, PDO::PARAM_INT);
$stmt->execute();
$faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'admin_header.php';
?>

<style>
  .page-wrapper {
    padding: 20px;
    color: #000;
  }
  .admin-title-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
  }
  .admin-title-row h2 {
    margin: 0;
    color: #000;
  }

  .alert {
    padding: 10px 14px;
    border-radius: 6px;
    margin-bottom: 12px;
    font-size: 0.95rem;
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

  .btn-black {
    display: inline-block;
    padding: 8px 14px;
    border-radius: 999px;
    border: none;
    background: #000000;
    color: #ffffff;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    margin-top: 6px;
    transition: background 0.2s ease, transform 0.1s ease;
  }
  .btn-black:hover {
    background: #222222;
    transform: translateY(-1px);
  }

  /* ==================== Feedback / Complaint table ==================== */
  .feedback-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    margin-top: 10px;
    color: #000;
  }
  .feedback-table th,
  .feedback-table td {
    border-bottom: 1px solid #e5e7eb;
    padding: 8px 6px;
    text-align: left;
    vertical-align: top;
  }
  .feedback-table th {
    font-weight: 600;
    background: #f9fafb;
  }
  .feedback-summary {
    font-size: 0.85rem;
    color: #4b5563;
    max-width: 280px;
  }
  .feedback-type-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.75rem;
    border: 1px solid #d1d5db;
    background: #f9fafb;
    color: #000;
  }

  /* ==================== Modal (complaint detail) ==================== */
  .modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
  }
  .modal-content {
    background: #ffffff;
    border-radius: 10px;
    max-width: 700px;
    width: 100%;
    max-height: 80vh;
    overflow: auto;
    padding: 18px 20px;
    position: relative;
    box-shadow: 0 20px 50px rgba(15,23,42,0.4);
    color: #000;
  }
  .modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
  }
  .modal-header h4 {
    margin: 0;
  }
  .modal-close {
    background: none;
    border: none;
    font-size: 1.3rem;
    cursor: pointer;
  }
  .modal-meta {
    font-size: 0.85rem;
    color: #6b7280;
    margin-bottom: 10px;
  }
  .modal-section-title {
    font-size: 0.9rem;
    font-weight: 600;
    margin: 10px 0 4px 0;
  }
  .modal-complaint-text {
    border-radius: 6px;
    border: 1px solid #e5e7eb;
    padding: 8px 10px;
    background: #f9fafb;
    font-size: 0.9rem;
    white-space: pre-wrap;
  }
  .modal-response-form textarea {
    width: 100%;
    border-radius: 6px;
    border: 1px solid #d1d5db;
    padding: 8px 10px;
    font-size: 0.9rem;
    resize: vertical;
    min-height: 80px;
    color: #000;
  }

  /* ==================== FAQ section ==================== */
  .faq-section {
    margin-top: 30px;
  }

  .faq-form-card {
    background: #ffffff;
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 3px 8px rgba(15,23,42,0.06);
    border: 1px solid #e5e7eb;
    color: #000;
    margin-bottom: 20px;
    max-width: 600px;
  }
  .faq-form-row {
    margin-bottom: 10px;
  }
  .faq-form-row label {
    display: block;
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 4px;
    color: #000;
  }
  .faq-form-row input[type="text"],
  .faq-form-row textarea {
    width: 100%;
    padding: 8px 10px;
    border-radius: 6px;
    border: 1px solid #d1d5db;
    font-size: 0.9rem;
    color: #000;
  }
  .faq-form-row textarea {
    min-height: 80px;
    resize: vertical;
  }

  .faq-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    color: #000;
  }
  .faq-table th,
  .faq-table td {
    border-bottom: 1px solid #e5e7eb;
    padding: 8px 6px;
    text-align: left;
    vertical-align: top;
  }
  .faq-table th {
    font-weight: 600;
    background: #f9fafb;
  }
  .faq-actions a {
    font-size: 0.85rem;
    margin-right: 8px;
    text-decoration: none;
    color: #000000;
  }
  .faq-actions a:hover {
    text-decoration: underline;
  }
  .faq-status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.75rem;
    border: 1px solid #d1d5db;
  }
  .faq-status-visible {
    background: #e0f2fe;
    color: #1d4ed8;
    border-color: #bfdbfe;
  }
  .faq-status-hidden {
    background: #fee2e2;
    color: #b91c1c;
    border-color: #fecaca;
  }

  /* ==================== Pagination (FAQ admin) ==================== */
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
</style>

<div class="page-wrapper">
  <div class="admin-title-row">
    <h2>Customer Service Center</h2>
  </div>

  <?php if (isset($_GET['msg']) && $_GET['msg'] === 'faq_hidden'): ?>
    <div class="alert alert-success">FAQ hidden successfully.</div>
  <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'faq_unhidden'): ?>
    <div class="alert alert-success">FAQ unhidden successfully.</div>
  <?php endif; ?>

  <!-- ===================== Complaints / Feedback Section ===================== -->
  <section style="margin-bottom: 24px;">
    <h3 style="color:#000;">Customer Complaints & Feedback</h3>

    <?php if ($success_feedback): ?>
      <div class="alert alert-success"><?= e($success_feedback) ?></div>
    <?php endif; ?>

    <?php if ($errors_feedback): ?>
      <div class="alert alert-error">
        <?php foreach ($errors_feedback as $err): ?>
          <div><?= e($err) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$feedbackRows): ?>
      <p>No feedback / complaints yet.</p>
    <?php else: ?>
      <table class="feedback-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Type</th>
            <th>Customer</th>
            <th>Created At</th>
            <th>Summary</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($feedbackRows as $row): ?>
          <?php
            $fullText = $row['FeedbackText'] ?? '';
            $summary = mb_substr($fullText, 0, 60);
            if (mb_strlen($fullText) > 60) $summary .= '...';
          ?>
          <tr>
            <td><?= (int)$row['FeedbackID'] ?></td>
            <td>
              <span class="feedback-type-pill"><?= e($row['Type']) ?></span>
            </td>
            <td>
              <?= e($row['CustomerName']) ?><br>
              <span style="font-size:0.8rem;color:#6b7280;">User ID: <?= (int)$row['UserID'] ?></span>
            </td>
            <td><?= e($row['CreatedAt']) ?></td>
            <td class="feedback-summary">
              <?= nl2br(e($summary)) ?>
            </td>
            <td>
              <button
                type="button"
                class="btn-black btn-open-modal"
                data-id="<?= (int)$row['FeedbackID'] ?>"
                data-user="<?= e($row['CustomerName']) ?>"
                data-userid="<?= (int)$row['UserID'] ?>"
                data-type="<?= e($row['Type']) ?>"
                data-created="<?= e($row['CreatedAt']) ?>"
                data-response="<?= e($row['ResponseText'] ?? '') ?>"
                data-admin="<?= e($row['AdminName'] ?? '') ?>"
                data-responded="<?= e($row['RespondedAt'] ?? '') ?>"
              >
                View &amp; Respond
              </button>

              <textarea
                id="fb-text-<?= (int)$row['FeedbackID'] ?>"
                class="feedback-text-src"
                style="display:none;"
              ><?= e($fullText) ?></textarea>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <!-- ===================== FAQ Management Section ===================== -->
  <section class="faq-section">
    <h3 style="color:#000;">FAQ Management</h3>

    <?php if ($success_faq): ?>
      <div class="alert alert-success"><?= e($success_faq) ?></div>
    <?php endif; ?>

    <?php if ($errors_faq): ?>
      <div class="alert alert-error">
        <?php foreach ($errors_faq as $err): ?>
          <div><?= e($err) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- FAQ form -->
    <div class="faq-form-card">
      <h4 style="margin-top:0; color:#000;">
        <?= $faqEditID ? 'Edit FAQ #' . (int)$faqEditID : 'Create New FAQ' ?>
      </h4>

      <?php
        $formQuery = [];
        if ($faqEditID) {
          $formQuery['faq_edit'] = (int)$faqEditID;
        }
        if ($adminFaqPage > 1) {
          $formQuery['faq_page'] = $adminFaqPage;
        }
        $formAction = 'cust_service.php';
        if ($formQuery) {
          $formAction .= '?' . http_build_query($formQuery);
        }
      ?>

      <form method="post" action="<?= e($formAction) ?>">
        <input type="hidden" name="action" value="faq_save">
        <?php if ($faqEditID): ?>
          <input type="hidden" name="faq_id" value="<?= (int)$faqEditID ?>">
        <?php endif; ?>

        <div class="faq-form-row">
          <label for="question">Question</label>
          <input type="text" name="question" id="question"
                 value="<?= e($faqQuestion) ?>"
                 placeholder="e.g. How long does delivery take?">
        </div>

        <div class="faq-form-row">
          <label for="answer">Answer</label>
          <textarea name="answer" id="answer"
                    placeholder="Provide a clear answer for customers."><?= e($faqAnswer) ?></textarea>
        </div>

        <button type="submit" class="btn-black">
          <?= $faqEditID ? 'Update FAQ' : 'Create FAQ' ?>
        </button>
      </form>
    </div>

    <!-- FAQ table (full width, all visible + hidden) -->
    <h4 style="margin-top:10px; color:#000;">All FAQs (visible + hidden)</h4>

    <?php if (!$faqs): ?>
      <p>No FAQ items yet.</p>
    <?php else: ?>
      <table class="faq-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Question</th>
            <th>Answer (short)</th>
            <th>Status</th>
            <th>Created / Updated</th>
            <th>By</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($faqs as $f): ?>
          <?php
            $isHidden = (int)$f['IsHidden'] === 1;
            $short = mb_substr($f['Answer'], 0, 70);
            if (mb_strlen($f['Answer']) > 70) $short .= '...';

            $baseParams = ['faq_page' => $adminFaqPage];
            $editParams = $baseParams;
            $editParams['faq_edit'] = (int)$f['FAQID'];
            $editUrl = 'cust_service.php?' . http_build_query($editParams);

            $toggleParams = $baseParams;
            $toggleParams['faq_id'] = (int)$f['FAQID'];
            $toggleParams['faq_action'] = $isHidden ? 'unhide' : 'hide';
            $toggleUrl = 'cust_service.php?' . http_build_query($toggleParams);
          ?>
          <tr>
            <td><?= (int)$f['FAQID'] ?></td>
            <td><?= e($f['Question']) ?></td>
            <td><?= nl2br(e($short)) ?></td>
            <td>
              <?php if ($isHidden): ?>
                <span class="faq-status-badge faq-status-hidden">Hidden</span>
              <?php else: ?>
                <span class="faq-status-badge faq-status-visible">Visible</span>
              <?php endif; ?>
            </td>
            <td style="font-size:0.8rem;">
              <div><?= e($f['CreatedAt']) ?></div>
              <?php if (!empty($f['UpdatedAt'])): ?>
                <div>Updated: <?= e($f['UpdatedAt']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= e($f['CreatedBy'] ?? '-') ?></td>
            <td class="faq-actions">
              <a href="<?= e($editUrl) ?>">Edit</a>
              <?php if ($isHidden): ?>
                <a href="<?= e($toggleUrl) ?>"
                   onclick="return confirm('Unhide this FAQ so customers can see it again?');">
                  Unhide
                </a>
              <?php else: ?>
                <a href="<?= e($toggleUrl) ?>"
                   onclick="return confirm('Hide this FAQ from customers?');">
                  Hide
                </a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($adminFaqTotalPages > 1): ?>
        <div class="pagination">
          <?php for ($p = 1; $p <= $adminFaqTotalPages; $p++): ?>
            <?php
              $pageUrl = 'cust_service.php?faq_page=' . $p;
            ?>
            <?php if ($p == $adminFaqPage): ?>
              <span class="page current"><?= $p ?></span>
            <?php else: ?>
              <a class="page" href="<?= e($pageUrl) ?>"><?= $p ?></a>
            <?php endif; ?>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<!-- ==================== Modal Markup (Complaints) ==================== -->
<div id="feedbackModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-header">
      <h4>Complaint / Feedback #<span id="modalFeedbackId"></span></h4>
      <button type="button" class="modal-close" id="modalClose">&times;</button>
    </div>

    <div class="modal-meta" id="modalFeedbackMeta"></div>

    <div>
      <div class="modal-section-title">Complaint Details</div>
      <div class="modal-complaint-text" id="modalFeedbackText"></div>
    </div>

    <div style="margin-top:12px;">
      <div class="modal-section-title">Admin Response</div>
      <div id="modalResponseMeta" style="font-size:0.8rem; color:#6b7280; margin-bottom:4px;"></div>

      <form method="post" class="modal-response-form" id="feedbackModalForm">
        <input type="hidden" name="action" value="respond">
        <input type="hidden" name="respond_feedback_id" id="modalRespondId" value="">
        <textarea name="response_text" id="modalResponseText"
                  placeholder="Type your response to this customer here..."></textarea>
        <button type="submit" class="btn-black" style="margin-top:8px;">
          Save Response
        </button>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const overlay   = document.getElementById('feedbackModal');
  const btnClose  = document.getElementById('modalClose');
  const idSpan    = document.getElementById('modalFeedbackId');
  const metaDiv   = document.getElementById('modalFeedbackMeta');
  const textDiv   = document.getElementById('modalFeedbackText');
  const respMeta  = document.getElementById('modalResponseMeta');
  const respText  = document.getElementById('modalResponseText');
  const respIdInp = document.getElementById('modalRespondId');

  // Open modal
  document.querySelectorAll('.btn-open-modal').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id        = this.dataset.id;
      const user      = this.dataset.user;
      const userId    = this.dataset.userid;
      const type      = this.dataset.type;
      const created   = this.dataset.created;
      const response  = this.dataset.response || '';
      const admin     = this.dataset.admin || '';
      const responded = this.dataset.responded || '';

      const textArea = document.getElementById('fb-text-' + id);
      const fullText = textArea ? textArea.value : '';

      idSpan.textContent = id;
      metaDiv.textContent = user + ' (User ID: ' + userId + ') • ' + type + ' • ' + created;
      textDiv.textContent = fullText;

      if (response.trim() !== '') {
        respMeta.textContent = 'Last responded by ' + (admin || 'Admin') + ' on ' + responded;
        respText.value = response;
      } else {
        respMeta.textContent = 'No response yet. Add your reply below.';
        respText.value = '';
      }

      respIdInp.value = id;

      overlay.style.display = 'flex';
    });
  });

  function closeModal() {
    overlay.style.display = 'none';
  }

  btnClose.addEventListener('click', closeModal);

  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) {
      closeModal();
    }
  });
});
</script>

<?php include 'admin_footer.php'; ?>
