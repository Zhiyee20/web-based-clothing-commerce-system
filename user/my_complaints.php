<?php
include 'header.php'; // header.php should already require config.php and start session

// Logged-in user
$_user = $_SESSION['user'] ?? null;

if (!$_user) {
    ?>
    <main class="account-section" style="padding:40px 0;">
      <div class="container">
        <h2 class="page-title">My Complaints &amp; Service Requests</h2>
        <p style="font-size:0.95rem; margin-top:12px;">
          Please <a href="login.php">log in</a> to view your complaints and our responses.
        </p>
      </div>
    </main>
    <?php
    include 'footer.php';
    exit;
}

// Pagination
$perPage = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Count total feedback for this user
$stmt = $pdo->prepare("SELECT COUNT(*) FROM feedback WHERE UserID = :uid");
$stmt->execute([':uid' => $_user['UserID']]);
$total = (int) $stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

// Fetch feedback + responses for this user
$stmt = $pdo->prepare("
    SELECT
      f.FeedbackID,
      f.Type,
      f.FeedbackText,
      f.CreatedAt,
      r.ResponseText,
      r.RespondedAt,
      au.Username AS AdminName
    FROM feedback f
      LEFT JOIN feedback_responses r ON r.FeedbackID = f.FeedbackID
      LEFT JOIN user au ON au.UserID = r.AdminUserID
    WHERE f.UserID = :uid
    ORDER BY f.CreatedAt DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':uid', $_user['UserID'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
  .my-complaints-wrapper {
    padding: 40px 0;
    color: #000;
  }
  .my-complaints-header {
    margin-bottom: 16px;
  }
  .my-complaints-header h2 {
    margin: 0;
    color: #000;
  }
  .complaints-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    margin-top: 12px;
    color: #000;
  }
  .complaints-table th,
  .complaints-table td {
    border-bottom: 1px solid #e5e7eb;
    padding: 8px 6px;
    vertical-align: top;
    text-align: left;
  }
  .complaints-table th {
    font-weight: 600;
    background: #f9fafb;
  }

  .complaint-type-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.75rem;
    border: 1px solid #d1d5db;
    background: #f9fafb;
  }

  .status-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.75rem;
    border: 1px solid #d1d5db;
  }
  .status-awaiting {
    background: #fef9c3;
    border-color: #fde68a;
    color: #854d0e;
  }
  .status-responded {
    background: #dcfce7;
    border-color: #86efac;
    color: #166534;
  }

  .btn-details {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 999px;
    border: 1px solid #000;
    background: #fff;
    color: #000;
    font-size: 0.85rem;
    cursor: pointer;
    text-decoration: none;
  }
  .btn-details:hover {
    background: #000;
    color: #fff;
  }

  .complaint-details {
    margin-top: 6px;
    font-size: 0.85rem;
  }

  .details-box {
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    padding: 8px 10px;
    background: #f9fafb;
    white-space: pre-wrap;
  }

  .pagination {
    margin-top: 14px;
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

  details.complaint-details summary {
    cursor: pointer;
    font-weight: 600;
    font-size: 0.85rem;
    list-style: none;
    margin-bottom: 4px;
  }
  details.complaint-details summary::-webkit-details-marker {
    display: none;
  }
</style>

<main class="my-complaints-wrapper">
  <div class="container">
    <div class="my-complaints-header">
      <h2>My Complaints &amp; Service Requests</h2>
      <p style="font-size:0.9rem; margin-top:4px;">
        Below is the history of complaints you have submitted and our responses.
      </p>
    </div>

    <?php if (!$rows): ?>
      <p style="font-size:0.9rem; margin-top:12px;">
        You have not submitted any complaints or service requests yet.
      </p>
    <?php else: ?>
      <table class="complaints-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Type</th>
            <th>Subject</th>
            <th>Submitted At</th>
            <th>Status</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <?php
            $text = $row['FeedbackText'] ?? '';

            // Extract "subject" from first line if it starts with "Subject:"
            $lines = preg_split('/\r\n|\r|\n/', $text);
            $firstLine = isset($lines[0]) ? trim($lines[0]) : '';
            if (stripos($firstLine, 'Subject:') === 0) {
                $subject = trim(substr($firstLine, strlen('Subject:')));
            } else {
                $subject = mb_substr($text, 0, 60);
                if (mb_strlen($text) > 60) $subject .= '...';
            }

            $hasResponse = !empty($row['ResponseText']);
            $statusText = $hasResponse
                ? 'Responded by ' . ($row['AdminName'] ?: 'Admin') . ' on ' . $row['RespondedAt']
                : 'Awaiting response';
          ?>
          <tr>
            <td><?= (int)$row['FeedbackID'] ?></td>
            <td>
              <span class="complaint-type-pill">
                <?= htmlspecialchars($row['Type']) ?>
              </span>
            </td>
            <td><?= htmlspecialchars($subject) ?></td>
            <td><?= htmlspecialchars($row['CreatedAt']) ?></td>
            <td>
              <?php if ($hasResponse): ?>
                <span class="status-pill status-responded">Responded</span>
              <?php else: ?>
                <span class="status-pill status-awaiting">Awaiting response</span>
              <?php endif; ?>
            </td>
            <td>
              <details class="complaint-details">
                <summary>View details &amp; response</summary>
                <div style="margin-top:4px;">
                  <div style="margin-bottom:6px;">
                    <strong>Your complaint:</strong>
                    <div class="details-box">
                      <?= nl2br(htmlspecialchars($text)) ?>
                    </div>
                  </div>

                  <div>
                    <strong>Admin response:</strong><br>
                    <?php if ($hasResponse): ?>
                      <div style="font-size:0.8rem; color:#6b7280; margin-bottom:2px;">
                        Responded by <?= htmlspecialchars($row['AdminName'] ?: 'Admin') ?>
                        on <?= htmlspecialchars($row['RespondedAt']) ?>
                      </div>
                      <div class="details-box">
                        <?= nl2br(htmlspecialchars($row['ResponseText'])) ?>
                      </div>
                    <?php else: ?>
                      <p style="font-size:0.85rem; margin-top:4px;">
                        Our team has not responded yet. Please check back later.
                      </p>
                    <?php endif; ?>
                  </div>
                </div>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p == $page): ?>
              <span class="page current"><?= $p ?></span>
            <?php else: ?>
              <a class="page" href="my_complaints.php?page=<?= $p ?>"><?= $p ?></a>
            <?php endif; ?>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>

<?php include 'footer.php'; ?>
