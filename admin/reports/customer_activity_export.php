<?php
// admin/reports/customer_activity_export.php
declare(strict_types=1);

require __DIR__ . '/../../config.php';
session_start();

// Only admins
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['Role'] ?? '') !== 'Admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');

/* -----------------------------
   1) Date range
   ----------------------------- */
$start = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : null;
$end   = isset($_GET['end'])   && $_GET['end']   !== '' ? $_GET['end']   : null;

function buildDateClause(&$params, string $col, ?string $start, ?string $end): string {
    $sql = '';
    if ($start) {
        $sql .= " AND $col >= :ps";
        $params[':ps'] = $start . ' 00:00:00';
    }
    if ($end) {
        $sql .= " AND $col <= :pe";
        $params[':pe'] = $end . ' 23:59:59';
    }
    return $sql;
}

/* -----------------------------
   2) Feedback
   ----------------------------- */
$p1 = [];
$dcF = buildDateClause($p1, 'f.CreatedAt', $start, $end);

$sqlFb = "
  SELECT f.FeedbackID, u.Username, f.Type, f.Rating, f.FeedbackText, f.CreatedAt
  FROM feedback f
  LEFT JOIN user u ON u.UserID = f.UserID
  WHERE 1=1
  $dcF
  ORDER BY f.CreatedAt DESC
";
$stmt = $pdo->prepare($sqlFb);
$stmt->execute($p1);
$feedbackRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------
   3) Product reviews
   ----------------------------- */
$p2 = [];
$dcR = buildDateClause($p2, 'pr.CreatedAt', $start, $end);

$sqlReviews = "
  SELECT pr.ProductID,
         p.Name AS Product,
         u.Username,
         pr.Rating,
         pr.Comment,
         pr.CreatedAt
  FROM product_reviews pr
  JOIN product p ON p.ProductID = pr.ProductID
  JOIN user u    ON u.UserID    = pr.UserID
  WHERE 1=1
  $dcR
  ORDER BY pr.CreatedAt DESC
";
$stmt = $pdo->prepare($sqlReviews);
$stmt->execute($p2);
$reviewRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------
   4) CSV headers
   ----------------------------- */
$filenameParts = ['customer_activity'];
$filenameParts[] = $start ?: 'all';
$filenameParts[] = $end   ?: 'all';
$filename = implode('_', $filenameParts) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');

/* ---- Feedback section ---- */
fputcsv($out, ['Feedback']); // title row
fputcsv($out, [
    'Feedback ID',
    'Username',
    'Type',
    'Rating',
    'Feedback Text',
    'Created At',
]);

if ($feedbackRows) {
    foreach ($feedbackRows as $f) {
        fputcsv($out, [
            $f['FeedbackID'],
            $f['Username'],
            $f['Type'],
            $f['Rating'],
            $f['FeedbackText'],
            $f['CreatedAt'],
        ]);
    }
} else {
    fputcsv($out, ['No feedback in this period']);
}

/* blank line */
fputcsv($out, []);

/* ---- Product Reviews section ---- */
fputcsv($out, ['Product Reviews']); // title row
fputcsv($out, [
    'Product ID',
    'Product',
    'Username',
    'Rating',
    'Comment',
    'Created At',
]);

if ($reviewRows) {
    foreach ($reviewRows as $r) {
        fputcsv($out, [
            $r['ProductID'],
            $r['Product'],
            $r['Username'],
            $r['Rating'],
            $r['Comment'],
            $r['CreatedAt'],
        ]);
    }
} else {
    fputcsv($out, ['No product reviews in this period']);
}

fclose($out);
exit;