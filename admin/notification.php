<?php include '../user/header.php'; ?>

<link rel="stylesheet" href="/assets/style.css" />
<link rel="stylesheet" href="/assets/admin_style.css">

<main class="admin-content" style="max-width: none;margin: 50px 190px;padding: 0;box-shadow: none;background: transparent;">
    <!-- Page Header -->
    <div class="page-head" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div>
            <h1 style="margin:0;font-size:24px;line-height:1.2;display:flex;align-items:center;gap:10px;">
                <!-- bell icon -->
                <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2a6 6 0 0 0-6 6v3.09c0 .46-.16.9-.45 1.25L4.1 14.2A1.5 1.5 0 0 0 5.25 16h13.5a1.5 1.5 0 0 0 1.15-2.45l-1.45-1.86c-.29-.35-.45-.79-.45-1.25V8a6 6 0 0 0-6-6zm0 20a3 3 0 0 1-3-3h6a3 3 0 0 1-3 3z"></path>
                </svg>
                Pending Tasks
            </h1>
        </div>
        <div class="stat-chips" style="display:flex;gap:8px;flex-wrap:wrap;">
            <span class="chip chip-unread" id="chipUnread">Unread: <b>0</b></span>
            <span class="chip chip-all" id="chipAll">Total: <b>0</b></span>
        </div>
    </div>

    <!-- Controls -->
    <form id="notifControls" class="full" style="margin-top:18px;">
        <div class="card controls" style="padding:12px 14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;display:grid;grid-template-columns:1.2fr .9fr .9fr .9fr auto;gap:10px;align-items:end;background:transparent;width:1490px;">
            <div class="form-row">
                <label for="q" class="label">Search</label>
                <input type="text" id="q" class="input" placeholder="Find by title or message…">
            </div>
            <div class="form-row">
                <label for="filterType" class="label">Type</label>
                <select id="filterType" class="select">
                    <option value="">All</option>
                    <option value="Stock">Stock</option>
                    <option value="Orders">Orders</option>
                    <option value="Feedback">Feedback</option>
                    <option value="Payment">Payment</option>
                    <option value="Deletion">Deletion</option>
                </select>
            </div>
            <div class="form-row">
                <label for="filterStatus" class="label">Status</label>
                <select id="filterStatus" class="select">
                    <option value="">All</option>
                    <option value="unread">Unread</option>
                    <option value="read">Read</option>
                </select>
            </div>
            <div>
                <div class="date-range">
                    <label for="from" class="label">From</label>
                    <input type="date" id="from" class="input" placeholder="—">
                    <label for="to" class="label">To</label>
                    <input type="date" id="to" class="input" placeholder="—">
                </div>
            </div>

            <div style="display:flex;gap:8px;align-items:end;white-space:nowrap;" class="controls-actions">
                <!-- Apply still here but filters also auto-apply -->
                <button type="button" id="btnApply" class="btn btn-primary" style="background:#fff;">Apply</button>
                <button type="button" id="btnReset" class="btn">Reset</button>
            </div>
        </div>
    </form>

    <!-- Bulk Actions -->
    <div class="bulk-actions" style="display:flex;align-items:center;justify-content:space-between;margin:14px 0;">
        <div style="display:flex;gap:10px;align-items:center;">
            <label style="display:flex;gap:8px;align-items:center;cursor:pointer;">
                <input type="checkbox" id="selectAll">
                <span>Select all</span>
            </label>

            <!-- Always available -->
            <button type="button" class="btn btn-primary" id="btnMarkRead" style="padding:5px 10px">
                🔕 Mark as Read
            </button>
            <button type="button" class="btn" id="btnMarkUnread" style="padding:5px 10px">
                🔔 Mark as Unread
            </button>

            <!-- Bulk approve / reject for cancel + return & refund ONLY -->
            <button
                type="button"
                class="btn btn-primary"
                id="btnApproveSelected"
                style="padding:5px 10px; display:none;">
                ✅ Approve Selected
            </button>

            <button
                type="button"
                class="btn btn-danger"
                id="btnRejectSelected"
                style="padding:5px 10px; display:none;">
                ❌ Reject Selected
            </button>
        </div>
        <div style="font-size:13px;color:#6b7280;">
            <span id="countSelected">0</span> selected
        </div>
    </div>

    <!-- List -->
    <section class="card" style="border:1px solid #e5e7eb;border-radius:10px;background:#fff;">
        <ul id="notifList" class="notif-list" style="list-style:none;margin:0;padding:0;">
            <?php
            // ===== Dynamic, ACTIONABLE notifications only =====
            // Assumes: $pdo is available from header/config
            $notifications = [];

            try {
                /* ==========================
                   1) STOCK – Low / Out of Stock
                   ========================== */
                // Low stock by color+size: Stock > 0 and <= MinStock
                $sqlLow = "
    SELECT 
        pcs.ColorSizeID,
        pcs.Stock,
        pcs.MinStock,
        pcs.Size,
        p.ProductID,
        p.Name,
        pc.ColorName,
        pc.ProductColorID,
        (
            SELECT MAX(sm.CreatedAt)
            FROM stock_movements sm
            WHERE sm.ColorSizeID = pcs.ColorSizeID
        ) AS LastMovementAt
    FROM product_color_sizes pcs
    JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
    JOIN product p        ON p.ProductID       = pc.ProductID
    WHERE pcs.Stock > 0 AND pcs.Stock <= pcs.MinStock
";

                $stmtLow = $pdo->query($sqlLow);
                foreach ($stmtLow as $row) {
                    $label = $row['Name'];
                    if (!empty($row['ColorName']) || !empty($row['Size'])) {
                        $label .= ' (' .
                            trim(($row['ColorName'] ?? '') . ' ' . ($row['Size'] ?? '')) . ')';
                    }

                    // 🔗 Go to Stock Management for this exact Product + Color + Size
                    $url = 'admin_stock.php'
                        . '?ProductID='      . (int)$row['ProductID']
                        . '&ProductColorID=' . (int)$row['ProductColorID']
                        . '&Size='           . urlencode($row['Size']);

                    $notifications[] = [
                        'id'         => 'low-' . (int)$row['ColorSizeID'],
                        'type'       => 'Stock',
                        'title'      => 'Low Stock – Product #' . (int)$row['ProductID'],
                        'message'    => $label . ' only ' . (int)$row['Stock'] .
                            ' left. (Min level ' . (int)$row['MinStock'] . ')',
                        'created_at' => !empty($row['LastMovementAt'])
                            ? date('Y-m-d H:i', strtotime($row['LastMovementAt']))
                            : date('Y-m-d H:i'),
                        'status'     => 'unread',
                        'url'        => $url,
                    ];
                }

                // Out of stock by color+size: Stock <= 0
                $sqlOut = "
    SELECT 
        pcs.ColorSizeID,
        pcs.Stock,
        pcs.MinStock,
        pcs.Size,
        p.ProductID,
        p.Name,
        pc.ColorName,
        pc.ProductColorID,
        (
            SELECT MAX(sm.CreatedAt)
            FROM stock_movements sm
            WHERE sm.ColorSizeID = pcs.ColorSizeID
        ) AS LastMovementAt
    FROM product_color_sizes pcs
    JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
    JOIN product p        ON p.ProductID       = pc.ProductID
    WHERE pcs.Stock <= 0
";

                $stmtOut = $pdo->query($sqlOut);
                foreach ($stmtOut as $row) {
                    $label = $row['Name'];
                    if (!empty($row['ColorName']) || !empty($row['Size'])) {
                        $label .= ' (' .
                            trim(($row['ColorName'] ?? '') . ' ' . ($row['Size'] ?? '')) . ')';
                    }

                    // 🔗 Go to Stock Management for this exact Product + Color + Size
                    $url = 'admin_stock.php'
                        . '?ProductID='      . (int)$row['ProductID']
                        . '&ProductColorID=' . (int)$row['ProductColorID']
                        . '&Size='           . urlencode($row['Size']);

                    $notifications[] = [
                        'id'         => 'out-' . (int)$row['ColorSizeID'],
                        'type'       => 'Stock',
                        'title'      => 'Out of Stock – Product #' . (int)$row['ProductID'],
                        'message'    => $label . ' is out of stock. Please restock or hide this item.',
                        'created_at' => !empty($row['LastMovementAt'])
                            ? date('Y-m-d H:i', strtotime($row['LastMovementAt']))
                            : date('Y-m-d H:i'),
                        'status'     => 'unread',
                        'url'        => $url,
                    ];
                }

                /* ==========================
                   2) ORDERS – Pending Orders
                   ========================== */
                $sqlPendingOrders = "
                    SELECT o.OrderID, o.OrderDate, o.TotalAmt, u.Username
                    FROM orders o
                    LEFT JOIN user u ON u.UserID = o.UserID
                    WHERE o.Status = 'Pending'
                    ORDER BY o.OrderDate DESC
                    LIMIT 50
                ";
                $stmtPO = $pdo->query($sqlPendingOrders);
                foreach ($stmtPO as $row) {
                    $username = $row['Username'] ?? 'Guest / Unknown';
                    $notifications[] = [
                        'id'         => 'order-pending-' . (int)$row['OrderID'],
                        'type'       => 'Orders',
                        'title'      => 'New Order Pending – #' . (int)$row['OrderID'],
                        'message'    => 'Order #' . (int)$row['OrderID'] . ' from ' . $username .
                            ' (Total RM' . number_format((float)$row['TotalAmt'], 2) . ') is waiting to be processed.',
                        'created_at' => $row['OrderDate']
                            ? date('Y-m-d H:i', strtotime($row['OrderDate']))
                            : date('Y-m-d H:i'),
                        'status'     => 'unread',
                        'url'        => 'admin_orders.php#order-' . (int)$row['OrderID'],
                    ];
                }

                /* ====================================
   3) ORDERS – Pending Cancellation Requests (NO proof image)
   ==================================== */
                $sqlCancel = "
    SELECT 
        oc.cancellationId,
        oc.OrderID,
        oc.Reason,
        oc.RequestedAt
    FROM ordercancellation oc
    JOIN orders o ON o.OrderID = oc.OrderID
    WHERE oc.Status = 'Pending'
      AND (oc.ProofImage IS NULL OR oc.ProofImage = '')
    ORDER BY oc.RequestedAt DESC
    LIMIT 50
";
                $stmtCancel = $pdo->query($sqlCancel);
                foreach ($stmtCancel as $row) {
                    $notifications[] = [
                        'id'         => 'cancel-' . (int)$row['cancellationId'],
                        'type'       => 'Orders',
                        'subtype'    => 'Cancel', // 👈 NEW
                        'title'      => 'Cancellation Request – Order #' . (int)$row['OrderID'],
                        'message'    => 'Order #' . (int)$row['OrderID'] .
                            ' requested cancellation: ' . $row['Reason'],
                        'created_at' => $row['RequestedAt']
                            ? date('Y-m-d H:i', strtotime($row['RequestedAt']))
                            : date('Y-m-d H:i'),
                        'status'     => 'unread',
                        'url'        => 'admin_orders.php#cancel-' . (int)$row['cancellationId'],
                    ];
                }

                /* ====================================
   3b) ORDERS – Pending Return / Refund Requests
   ==================================== */
                $sqlReturn = "
    SELECT 
        oc.cancellationId,
        oc.OrderID,
        oc.Reason,
        oc.RequestedAt,
        oc.RefundFinalStatus,
        oc.ProofImage
    FROM ordercancellation oc
    JOIN orders o ON o.OrderID = oc.OrderID
    WHERE oc.ProofImage IS NOT NULL
      AND oc.ProofImage <> ''
      AND (
            oc.RefundFinalStatus IS NULL
         OR oc.RefundFinalStatus = ''
         OR oc.RefundFinalStatus = 'Pending'
      )
    ORDER BY oc.RequestedAt DESC
    LIMIT 50
";
                $stmtReturn = $pdo->query($sqlReturn);
                foreach ($stmtReturn as $row) {
                    // Normalise RefundFinalStatus
                    $refundStatusRaw = $row['RefundFinalStatus'] ?? null;
                    $refundStatus = $refundStatusRaw === null ? '' : trim($refundStatusRaw);

                    // Decide label based on your rule:
                    //  - ProofImage not null + RefundFinalStatus NULL/''  => Return request
                    //  - ProofImage not null + RefundFinalStatus 'Pending'=> Refund request
                    if ($refundStatus === '') {
                        $subtypeLabel = 'Return';          // for badge
                        $titlePrefix  = 'Return Request';  // for title
                        $msgPrefix    = 'requested return: ';
                    } elseif (strcasecmp($refundStatus, 'Pending') === 0) {
                        $subtypeLabel = 'Refund';          // for badge
                        $titlePrefix  = 'Refund Request';  // for title
                        $msgPrefix    = 'requested refund: ';
                    } else {
                        // Any other final status (Approved / Rejected / etc.) – no pending task
                        continue;
                    }

                    $notifications[] = [
                        'id'         => 'return-' . (int)$row['cancellationId'],  // keep prefix 'return-' for bulk logic
                        'type'       => 'Orders',
                        'subtype'    => $subtypeLabel, // 'Return' or 'Refund'
                        'title'      => $titlePrefix . ' – Order #' . (int)$row['OrderID'],
                        'message'    => 'Order #' . (int)$row['OrderID'] . ' ' . $msgPrefix . $row['Reason'],
                        'created_at' => $row['RequestedAt']
                            ? date('Y-m-d H:i', strtotime($row['RequestedAt']))
                            : date('Y-m-d H:i'),
                        'status'     => 'unread',
                        'url'        => 'admin_orders.php#return-' . (int)$row['cancellationId'],
                    ];
                }

                /* ====================================
                   4) FEEDBACK – Unanswered Feedback Only
                   ==================================== */
                $sqlFb = "
                    SELECT f.FeedbackID,
                           f.Type,
                           f.FeedbackText,
                           f.Rating,
                           f.CreatedAt,
                           u.Username
                    FROM feedback f
                    JOIN user u ON u.UserID = f.UserID
                    LEFT JOIN feedback_responses fr ON fr.FeedbackID = f.FeedbackID
                    WHERE fr.FeedbackID IS NULL
                    ORDER BY f.CreatedAt DESC
                    LIMIT 50
                ";
                $stmtFb = $pdo->query($sqlFb);
                foreach ($stmtFb as $row) {
                    $rating = is_null($row['Rating']) ? null : (int)$row['Rating'];

                    $titleBase = ($rating !== null && $rating <= 3)
                        ? 'Unanswered Complaint'
                        : 'Unanswered Feedback';

                    $prefix = '[' . $row['Type'] . '] ';
                    if ($rating !== null) {
                        $prefix .= '(Rating ' . $rating . '/5) ';
                    }

                    $notifications[] = [
                        'id'         => 'fb-' . (int)$row['FeedbackID'],
                        'type'       => 'Feedback',
                        'title'      => $titleBase . ' from ' . $row['Username'],
                        'message'    => $prefix . $row['FeedbackText'],
                        'created_at' => $row['CreatedAt']
                            ? date('Y-m-d H:i', strtotime($row['CreatedAt']))
                            : date('Y-m-d H:i'),
                        'status'     => 'unread',
                        'url'        => 'cust_service.php',
                    ];
                }

                /* ====================================
                   5) PAYMENT – Pending & Failed Payments
                   ==================================== */
                $sqlPay = "
                    SELECT p.PaymentID,
                           p.OrderID,
                           p.PaymentMethod,
                           p.AmountPaid,
                           p.PaymentDate,
                           p.Status
                    FROM payment p
                    WHERE p.Status IN ('Pending','Failed')
                    ORDER BY p.PaymentDate DESC
                    LIMIT 50
                ";
                $stmtPay = $pdo->query($sqlPay);
                foreach ($stmtPay as $row) {
                    $statusLabel = $row['Status'] === 'Failed' ? 'Failed Payment' : 'Pending Payment';

                    $notifications[] = [
                        'id'         => 'pay-' . (int)$row['PaymentID'],
                        'type'       => 'Payment',
                        'title'      => $statusLabel . ' – Order #' . (int)$row['OrderID'],
                        'message'    => 'Order #' . (int)$row['OrderID'] .
                            ' via ' . $row['PaymentMethod'] .
                            ' for RM' . number_format((float)$row['AmountPaid'], 2) .
                            ' is ' . $row['Status'] . '.',
                        'created_at' => $row['PaymentDate']
                            ? date('Y-m-d H:i', strtotime($row['PaymentDate']))
                            : date('Y-m-d H:i'),
                        'status'     => 'unread',
                        'url'        => 'admin_orders.php#order-' . (int)$row['OrderID'],
                    ];
                }

                /* ====================================
                   6) DELETION – Categories < 24h before permanent delete
                   ==================================== */
                $sqlDeletion = "
    SELECT 
        CategoryID,
        CategoryName,
        DeletedAt
    FROM categories
    WHERE IsDeleted = 1
      AND DeletedAt IS NOT NULL
      -- only those that will be auto-deleted within next 24 hours
      AND TIMESTAMPDIFF(HOUR, NOW(), DATE_ADD(DeletedAt, INTERVAL 30 DAY)) BETWEEN 0 AND 24
";
                $stmtDel = $pdo->query($sqlDeletion);
                foreach ($stmtDel as $row) {
                    $categoryID   = (int)$row['CategoryID'];
                    $categoryName = $row['CategoryName'] ?? ('Category #' . $categoryID);

                    // hours left until permanent delete (0–24)
                    $hoursLeft = (int)$pdo->query("
        SELECT TIMESTAMPDIFF(
            HOUR,
            NOW(),
            DATE_ADD(" . $pdo->quote($row['DeletedAt']) . ", INTERVAL 30 DAY)
        ) AS h
    ")->fetchColumn();

                    if ($hoursLeft < 0) {
                        $hoursLeft = 0;
                    }

                    $notifications[] = [
                        'id'         => 'del-cat-' . $categoryID,
                        'type'       => 'Deletion',
                        'title'      => 'Permanent Deletion Reminder – Category #' . $categoryID,
                        'message'    => $categoryName . ' will be permanently deleted in about ' . $hoursLeft . ' hour(s).',
                        'created_at' => date('Y-m-d H:i'),
                        'status'     => 'unread',
                        // 🔴 IMPORTANT: jump directly to this category row
                        'url' => 'admin_category.php#cat-' . (int)$row['CategoryID'],
                    ];
                }

                // Sort all notifications by datetime desc (newest first)
                usort($notifications, function ($a, $b) {
                    return strcmp($b['created_at'], $a['created_at']);
                });
            } catch (Throwable $e) {
                // TEMP: show error so you know if something still breaks
                echo '<li style="padding:14px 16px;color:#b91c1c;">Error loading notifications: ' .
                    htmlspecialchars($e->getMessage()) . '</li>';
            }

            foreach ($notifications as $n):

            ?>
                <li class="notif-item <?= $n['status'] === 'unread' ? 'is-unread' : 'is-read' ?>"

                    data-id="<?= htmlspecialchars($n['id']) ?>"
                    data-type="<?= htmlspecialchars($n['type']) ?>"
                    data-status="<?= htmlspecialchars($n['status']) ?>"
                    data-date="<?= htmlspecialchars(substr($n['created_at'], 0, 10)) ?>"
                    data-url="<?= htmlspecialchars($n['url']) ?>"
                    data-subtype="<?= htmlspecialchars($n['subtype'] ?? '') ?>"

                    style="display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border-bottom:1px solid #f1f5f9;cursor:pointer;">
                    <input type="checkbox" class="row-check" style="margin-top:4px;cursor:pointer;">
                    <div class="icon-wrap" aria-hidden="true" style="width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;">
                        <?php
                        $icon = [
                            'Stock'     => '📦',
                            'Orders'    => '🧾',
                            'Feedback'  => '💬',
                            'Payment'   => '💳',
                            'Deletion'  => '🗑️',
                        ][$n['type']] ?? '🔔';
                        ?>
                        <span><?= $icon ?></span>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <h3 style="margin:0;font-size:16px;line-height:1.3;" class="title">
                                <a href="<?= htmlspecialchars($n['url']) ?>" class="notif-link" style="color:inherit;text-decoration:none;">
                                    <?= htmlspecialchars($n['title']) ?>
                                </a>
                            </h3>
                            <span class="badge type-<?= strtolower($n['type']) ?>">
                                <?= htmlspecialchars($n['type']) ?>
                            </span>

                            <?php if (!empty($n['subtype'])): ?>
                                <span class="badge badge-sub badge-sub-<?= strtolower(str_replace([' ', '&'], ['-', 'and'], $n['subtype'])) ?>">
                                    <?= htmlspecialchars($n['subtype']) ?>
                                </span>
                            <?php endif; ?>

                            <span class="dot-sep">•</span>
                            <time class="when" datetime="<?= htmlspecialchars($n['created_at']) ?>" style="color:#6b7280;font-size:13px;">
                                <?= htmlspecialchars($n['created_at']) ?>
                            </time>

                            <?php if ($n['status'] === 'unread'): ?>
                                <span class="pill unread">Unread</span>
                            <?php else: ?>
                                <span class="pill read">Read</span>
                            <?php endif; ?>
                        </div>
                        <p class="msg" style="margin:6px 0 0;color:#334155;line-height:1.45;word-break:break-word;">
                            <?= htmlspecialchars($n['message']) ?>
                        </p>
                    </div>
                    <div class="row-actions" style="display:flex;gap:8px;align-items:center;">
                        <button type="button" class="btn btn-xs jsMarkRead" style="cursor:pointer;">🔕</button>
                        <button type="button" class="btn btn-xs jsMarkUnread" style="cursor:pointer;">🔔</button>
                    </div>
                </li>
            <?php endforeach; ?>

            <?php if (empty($notifications)): ?>
                <li style="padding:20px 16px;color:#6b7280;text-align:center;">
                    No pending admin tasks at the moment 🎉
                </li>
            <?php endif; ?>
        </ul>

        <!-- Pagination -->
        <div id="pagination" class="pagination"
            style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-top:1px solid #f1f5f9;">
            <div>
                <button type="button" id="pagePrev" class="btn btn-xs">← Prev</button>
                <button type="button" id="pageNext" class="btn btn-xs">Next →</button>
            </div>
            <div style="font-size:13px;color:#6b7280;">
                Showing <span id="pageFrom">0</span>–<span id="pageTo">0</span>
                of <span id="pageTotal">0</span> tasks
            </div>
        </div>

        <div id="emptyState" style="display:none;padding:40px 16px;text-align:center;color:#6b7280;">
            <div style="font-size:40px;line-height:1;">🔔</div>
            <div style="margin-top:6px;font-weight:600;">No notifications match your filters</div>
            <div style="font-size:13px;">Try clearing filters or adjusting the date range.</div>
        </div>
    </section>

</main>

<style>
    .chip {
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        border: 1px solid #e5e7eb;
        background: #fff;
    }

    .chip-unread b {
        color: #dc2626;
    }

    .chip-all b {
        color: #111827;
    }

    .badge {
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 999px;
        border: 1px solid #e5e7eb;
        background: #f8fafc;
        color: #334155;
    }

    .badge.type-stock {
        background: #eef2ff;
        border-color: #e0e7ff;
        color: #1d4ed8;
    }

    .badge.type-orders {
        background: #eef2ff;
        border-color: #e0e7ff;
        color: #3730a3;
    }

    .badge.type-feedback {
        background: #fef3c7;
        border-color: #fde68a;
        color: #92400e;
    }

    .badge.type-payment {
        background: #fee2e2;
        border-color: #fecaca;
        color: #b91c1c;
    }

    .badge.type-deletion {
        background: #fef2f2;
        border-color: #fecaca;
        color: #b91c1c;
    }

    .pill {
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
    }

    .pill.unread {
        background: #fff7f7;
        border-color: #fecaca;
        color: #b91c1c;
    }

    .pill.read {
        background: #f8fafc;
        border-color: #e5e7eb;
        color: #475569;
    }

    .btn {
        padding: 8px 12px;
        border: 2px solid #e5e7eb;
        border-radius: 30px;
        background: #fff;
        cursor: pointer;
    }

    .btn:hover {
        background: #f8fafc;
    }

    .btn-primary {
        background: #0ea5e9;
        border-color: #0ea5e9;
        color: #fff;
    }

    .btn-primary:hover {
        filter: brightness(0.95);
    }

    .btn-danger {
        border-color: #ef4444;
        color: #b91c1c;
        background: #fff5f5;
    }

    .btn-danger:hover {
        background: #fee2e2;
    }

    .btn-xs {
        padding: 6px 8px;
        font-size: 12px;
    }

    .notif-item.is-unread .title {
        font-weight: 700;
    }

    .icon-wrap {
        background: #f1f5f9;
    }

    .label {
        display: block;
        font-size: 13px;
        color: #334155;
        margin-bottom: 6px;
    }

    .dot-sep {
        color: #cbd5e1;
    }

    /* Black/white buttons */
    .btn,
    .btn-danger {
        background: #000 !important;
        color: #fff !important;
        border: 1px solid #000 !important;
    }

    .btn-primary,
    .btn-xs {
        background: #fff !important;
        color: #000 !important;
        border: 1px solid #000 !important;
    }

    .btn-xs {
        padding: 8px 15px;
    }

    .btn:hover {
        background: #fff !important;
        color: #000 !important;
    }

    /* Inline labels + compact filters */
    .form-row,
    .controls>div {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .full .input,
    .full .select {
        margin-right: 24px !important;
        border-radius: 5px !important;
        padding: 8px 14px;
    }

    .full .label {
        margin: 0 !important;
        min-width: auto !important;
        display: inline-block;
    }

    .input,
    .select {
        border-radius: 5px !important;
        padding: 8px 14px;
    }

    .full .label {
        display: inline-block !important;
        margin: 0 6px 0 0 !important;
        min-width: auto !important;
        font-size: 13px;
    }

    .full .input,
    .full .select {
        display: inline-block !important;
        vertical-align: middle;
        margin-right: 10px !important;
        border-radius: 5px !important;
        padding: 8px 14px;
        width: auto;
    }

    .controls>div {
        display: flex;
        align-items: center;
        gap: 6px !important;
    }

    .controls .date-range {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
    }

    .controls .date-range .input {
        width: 170px;
    }

    .controls {
        display: flex !important;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
    }

    .card.controls {
        display: flex !important;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 8px 28px !important;
        width: 100%;
    }

    .card.controls>div:last-child {
        margin-left: auto !important;
        display: flex;
        align-items: center;
        gap: 8px;
        justify-content: flex-end;
        white-space: nowrap;
    }

    /* Selected notification (clicked from this page) */
    .notif-item.selected {
        background: #f9fafb !important;
    }

    @media (max-width:760px) {
        .controls {
            grid-template-columns: 1fr 1fr;
        }

        .row-actions {
            display: none;
        }

        .card.controls>div:last-child {
            flex: 1 1 100%;
            justify-content: flex-end;
        }
    }

    .badge-sub {
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 999px;
        border: 1px solid #e5e7eb;
        margin-left: 2px;
    }

    /* Cancel = light red */
    .badge-sub-cancel {
        background: #fee2e2;
        border-color: #fecaca;
        color: #b91c1c;
    }

    /* Return request = blue */
    .badge-sub-return {
        background: #e0f2fe;
        border-color: #bae6fd;
        color: #075985;
    }

    /* Refund request = teal (or reuse blue if you prefer) */
    .badge-sub-refund {
        background: #ccfbf1;
        border-color: #99f6e4;
        color: #0f766e;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const list = document.getElementById('notifList');
        if (!list) return;

        const items = () => Array.from(list.querySelectorAll('.notif-item'));
        const q = document.getElementById('q');
        const type = document.getElementById('filterType');
        const status = document.getElementById('filterStatus');
        const from = document.getElementById('from');
        const to = document.getElementById('to');
        const emptyState = document.getElementById('emptyState');
        const selectAll = document.getElementById('selectAll');
        const countSelected = document.getElementById('countSelected');
        const chipUnread = document.getElementById('chipUnread')?.querySelector('b');
        const chipAll = document.getElementById('chipAll')?.querySelector('b');

        const pagePrev = document.getElementById('pagePrev');
        const pageNext = document.getElementById('pageNext');
        const pageFromEl = document.getElementById('pageFrom');
        const pageToEl = document.getElementById('pageTo');
        const pageTotalEl = document.getElementById('pageTotal');

        const btnApprove = document.getElementById('btnApproveSelected');
        const btnReject = document.getElementById('btnRejectSelected');

        let currentPage = 1;
        const pageSize = 10;

        const STORAGE_KEY = 'adminNotifStatus';

        function loadSavedStatuses() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY);
                return raw ? JSON.parse(raw) : {};
            } catch (e) {
                return {};
            }
        }

        function saveSavedStatuses(map) {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(map));
            } catch (e) {
                // ignore quota errors
            }
        }

        // Map: { [notifID]: 'read' | 'unread' }
        let statusMap = loadSavedStatuses();

        function applySavedStatuses() {
            items().forEach(li => {
                const id = li.dataset.id;
                if (!id) return;

                const stored = statusMap[id];
                if (stored !== 'read' && stored !== 'unread') return;

                li.dataset.status = stored;
                li.classList.toggle('is-unread', stored === 'unread');
                li.classList.toggle('is-read', stored === 'read');

                const pill = li.querySelector('.pill');
                if (pill) {
                    pill.textContent = stored === 'unread' ? 'Unread' : 'Read';
                    pill.className = 'pill ' + (stored === 'unread' ? 'unread' : 'read');
                }
            });
        }

        function normalize(s) {
            return (s || '').toLowerCase().trim();
        }

        function applyFilters() {
            const qq = normalize(q?.value);
            const t = type?.value || '';
            const st = status?.value || '';
            const df = from && from.value ? new Date(from.value) : null;
            const dt = to && to.value ? new Date(to.value) : null;

            items().forEach(li => {
                const title = normalize(li.querySelector('.title')?.textContent);
                const msg = normalize(li.querySelector('.msg')?.textContent);
                const matchesQ = !qq || title.includes(qq) || msg.includes(qq);
                const matchesType = !t || li.dataset.type === t;
                const matchesStatus = !st || li.dataset.status === st;
                const dAttr = li.dataset.date;
                const d = dAttr ? new Date(dAttr) : null;
                const matchesDate = (!df || (d && d >= df)) && (!dt || (d && d <= dt));

                const ok = matchesQ && matchesType && matchesStatus && matchesDate;
                li.dataset.match = ok ? '1' : '0';
            });

            currentPage = 1;
            renderPaginationAndItems();
        }

        function getMatchedItems() {
            return items().filter(li => li.dataset.match !== '0');
        }

        function renderPaginationAndItems() {
            const matched = getMatchedItems();
            const totalMatched = matched.length;
            const totalPages = totalMatched > 0 ? Math.ceil(totalMatched / pageSize) : 1;

            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            items().forEach(li => {
                li.style.display = 'none';
            });

            if (totalMatched > 0) {
                const startIndex = (currentPage - 1) * pageSize;
                const endIndex = Math.min(startIndex + pageSize, totalMatched);

                for (let i = startIndex; i < endIndex; i++) {
                    const li = matched[i];
                    if (li) li.style.display = 'flex';
                }

                emptyState.style.display = 'none';
            } else {
                emptyState.style.display = 'block';
            }

            if (chipAll) chipAll.textContent = totalMatched;
            if (chipUnread) {
                const unreadVisible = matched.filter(li => li.dataset.status === 'unread').length;
                chipUnread.textContent = unreadVisible;
            }

            if (pageFromEl && pageToEl && pageTotalEl) {
                if (totalMatched === 0) {
                    pageFromEl.textContent = 0;
                    pageToEl.textContent = 0;
                    pageTotalEl.textContent = 0;
                } else {
                    const startIndex = (currentPage - 1) * pageSize;
                    const endIndex = Math.min(startIndex + pageSize, totalMatched);
                    pageFromEl.textContent = startIndex + 1;
                    pageToEl.textContent = endIndex;
                    pageTotalEl.textContent = totalMatched;
                }
            }

            if (selectAll) {
                selectAll.checked = false;
            }
            items().forEach(li => {
                const ck = li.querySelector('.row-check');
                if (ck) ck.checked = false;
            });

            updateSelectedCount(); // also updates bulk approve/reject visibility
        }

        // Decide whether bulk Approve / Reject buttons should be visible
        function updateBulkActionVisibility() {
            if (!btnApprove || !btnReject) return;

            // Consider only visible + checked items
            const selectedLis = items().filter(li => {
                if (li.style.display === 'none') return false;
                const ck = li.querySelector('.row-check');
                return ck && ck.checked;
            });

            if (selectedLis.length === 0) {
                btnApprove.style.display = 'none';
                btnReject.style.display = 'none';
                return;
            }

            let hasCancelOrReturn = false;
            let hasNonCancelReturn = false;

            selectedLis.forEach(li => {
                const dataId = li.dataset.id || '';
                const prefix = dataId.split('-')[0]; // e.g. "cancel", "return", "order", "low", "pay"

                if (prefix === 'cancel' || prefix === 'return') {
                    hasCancelOrReturn = true;
                } else {
                    // includes "order-pending-..." and all others (stock, payment, deletion, etc.)
                    hasNonCancelReturn = true;
                }
            });

            // Show Approve/Reject ONLY when:
            // - there is at least one cancel/return AND
            // - there is NO other type selected (including new order pending)
            const showBulkDecision = hasCancelOrReturn && !hasNonCancelReturn;

            btnApprove.style.display = showBulkDecision ? 'inline-block' : 'none';
            btnReject.style.display = showBulkDecision ? 'inline-block' : 'none';
        }

        function updateSelectedCount() {
            const n = items().filter(li => li.style.display !== 'none' && li.querySelector('.row-check')?.checked).length;
            if (countSelected) countSelected.textContent = n;

            updateBulkActionVisibility();
        }

        function setStatus(li, toStatus) {
            const id = li.dataset.id;

            li.dataset.status = toStatus;
            li.classList.toggle('is-unread', toStatus === 'unread');
            li.classList.toggle('is-read', toStatus === 'read');

            const pill = li.querySelector('.pill');
            if (pill) {
                pill.textContent = toStatus === 'unread' ? 'Unread' : 'Read';
                pill.className = 'pill ' + (toStatus === 'unread' ? 'unread' : 'read');
            }

            if (id) {
                statusMap[id] = toStatus;
                saveSavedStatuses(statusMap);
            }
        }

        list.addEventListener('click', (e) => {
            const li = e.target.closest('.notif-item');
            if (!li) return;

            const btn = e.target.closest('button');
            const ck = e.target.closest('input[type="checkbox"]');
            const link = e.target.closest('a.notif-link');

            // If title link was clicked, prevent default so we control navigation
            if (link) {
                e.preventDefault();
            }

            // 1) Row-level buttons (small 🔕 / 🔔)
            if (btn) {
                if (btn.classList.contains('jsMarkRead')) setStatus(li, 'read');
                if (btn.classList.contains('jsMarkUnread')) setStatus(li, 'unread');
                renderPaginationAndItems();
                return;
            }

            // 2) Checkbox click – only update selected count + bulk visibility
            if (ck) {
                updateSelectedCount();
                return;
            }

            // 3) Click on the row itself (anywhere else):
            //    - mark as READ
            //    - visually mark as selected
            //    - refresh chips & pagination
            //    - then open target in new tab
            setStatus(li, 'read');

            // Remove previous selection and mark this one
            items().forEach(row => row.classList.remove('selected'));
            li.classList.add('selected');

            renderPaginationAndItems();

            const url = li.dataset.url;
            if (url) {
                window.open(url, '_blank', 'noopener');
            }
        });

        document.getElementById('btnMarkRead')?.addEventListener('click', () => {
            items().forEach(li => {
                if (li.style.display === 'none') return;
                const ck = li.querySelector('.row-check');
                if (ck && ck.checked) setStatus(li, 'read');
            });
            renderPaginationAndItems();
        });

        document.getElementById('btnMarkUnread')?.addEventListener('click', () => {
            items().forEach(li => {
                if (li.style.display === 'none') return;
                const ck = li.querySelector('.row-check');
                if (ck && ck.checked) setStatus(li, 'unread');
            });
            renderPaginationAndItems();
        });

        // ============================================
        // NEW: Bulk approve / reject cancel + return
        // ============================================
        async function handleBulkAction(action) {
            // Get all visible + checked notifications
            const selectedLis = items().filter(li => {
                if (li.style.display === 'none') return false;
                const ck = li.querySelector('.row-check');
                return ck && ck.checked;
            });

            const cancelIds = [];
            const returnIds = [];
            let hasNonCancelReturn = false;

            selectedLis.forEach(li => {
                const dataId = li.dataset.id || ''; // e.g. "cancel-5", "return-7", "order-pending-10"
                if (!dataId) return;

                const parts = dataId.split('-'); // ["cancel","5"] / ["order","pending","10"]
                if (parts.length < 2) return;

                const prefix = parts[0]; // "cancel", "return", "order", "low", "pay", etc.
                const numeric = parseInt(parts[1], 10);

                if (prefix === 'cancel') {
                    if (numeric) cancelIds.push(numeric);
                } else if (prefix === 'return') {
                    if (numeric) returnIds.push(numeric);
                } else {
                    hasNonCancelReturn = true;
                }
            });

            // Extra safety on backend logic
            if (hasNonCancelReturn) {
                alert('Bulk approve / reject is only allowed for cancellation and return & refund requests.');
                return;
            }

            if (cancelIds.length === 0 && returnIds.length === 0) {
                alert('Please select at least one cancellation / return & refund notification.');
                return;
            }

            const confirmMsg = action === 'approve_cancel_return' ?
                'Approve all selected cancellation / return & refund requests?' :
                'Reject all selected cancellation / return & refund requests?';

            if (!confirm(confirmMsg)) {
                return;
            }

            try {
                const res = await fetch('admin_bulk_notif_action.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: action,
                        cancel_ids: cancelIds,
                        return_ids: returnIds
                    })
                });

                const data = await res.json().catch(() => ({}));

                if (!res.ok || !data.ok) {
                    alert(data.message || 'Failed to process selected requests. Please try again.');
                    return;
                }

                // Mark those notifications as READ in the UI
                selectedLis.forEach(li => setStatus(li, 'read'));

                renderPaginationAndItems();

                alert(
                    action === 'approve_cancel_return' ?
                    'Selected requests have been approved successfully.' :
                    'Selected requests have been rejected successfully.'
                );
            } catch (err) {
                console.error(err);
                alert('Error while processing requests. Please try again.');
            }
        }

        btnApprove?.addEventListener('click', () => {
            handleBulkAction('approve_cancel_return');
        });

        btnReject?.addEventListener('click', () => {
            handleBulkAction('reject_cancel_return');
        });

        selectAll?.addEventListener('change', () => {
            items().forEach(li => {
                if (li.style.display === 'none') return;
                const ck = li.querySelector('.row-check');
                if (ck) ck.checked = selectAll.checked;
            });
            updateSelectedCount();
        });

        document.getElementById('btnApply')?.addEventListener('click', applyFilters);
        document.getElementById('btnReset')?.addEventListener('click', () => {
            if (q) q.value = '';
            if (type) type.value = '';
            if (status) status.value = '';
            if (from) from.value = '';
            if (to) to.value = '';
            applyFilters();
        });

        q?.addEventListener('input', applyFilters);
        q?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
        [type, status, from, to].forEach(el => {
            el?.addEventListener('change', applyFilters);
        });

        pagePrev?.addEventListener('click', () => {
            currentPage--;
            renderPaginationAndItems();
        });
        pageNext?.addEventListener('click', () => {
            currentPage++;
            renderPaginationAndItems();
        });

        items().forEach(li => {
            li.dataset.match = '1';
        });

        // Apply stored read/unread state BEFORE first render
        applySavedStatuses();
        renderPaginationAndItems();
    });
</script>

<?php include 'admin_footer.php'; ?>