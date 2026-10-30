<?php
// Start session
require_once __DIR__ . '/../includes/session_boot.php';

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /authentication/login");
    exit;
}
if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Head Manager') {
    header("Location: ../playerdashboard.php");
    exit;
}

require_once __DIR__ . '/../includes/database_connect.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

$success_message = '';
$error_message = '';

// ==========================================
// HANDLE REFUND ACTIONS (TC-AS-023)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    verify_csrf_token();
    if ($_POST['action'] == 'process_refund') {
        try {
            $refund_id = (int)$_POST['refund_id'];
            $new_status = $_POST['status'] ?? '';
            $notes = trim($_POST['admin_notes'] ?? '');

            if (!in_array($new_status, ['Approved', 'Refunded', 'Rejected'], true)) {
                throw new Exception("Invalid refund status.");
            }

            $cur = $pdo->prepare("SELECT r.status, u.full_name, u.email FROM refunds r JOIN Users u ON u.user_id = r.user_id WHERE r.refund_id = ?");
            $cur->execute([$refund_id]);
            $refund_owner = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$refund_owner) throw new Exception("Refund request not found.");
            $current = $refund_owner['status'];

            if ($new_status === 'Approved' && $current !== 'Pending') {
                throw new Exception("Only pending refund requests can be approved.");
            }

            $processed = in_array($new_status, ['Refunded', 'Rejected'], true) ? 'NOW()' : 'NULL';
            $pdo->prepare("UPDATE refunds SET status = ?, admin_notes = ?, processed_at = {$processed} WHERE refund_id = ?")
                ->execute([$new_status, $notes !== '' ? $notes : null, $refund_id]);

            $refund_ref = '#REF-' . str_pad($refund_id, 5, '0', STR_PAD_LEFT);
            $success_message = "Refund " . $refund_ref . " marked as " . $new_status . ".";

            // Send an email only on the first Pending -> Approved transition.
            if ($new_status === 'Approved' && $current === 'Pending') {
                $recipient = filter_var($refund_owner['email'] ?? '', FILTER_VALIDATE_EMAIL);
                if ($recipient) {
                    $subject = 'HI-POWER refund request approved';
                    $body = "Hello " . ($refund_owner['full_name'] ?: 'Customer') . ",\n\n"
                        . "Your refund request " . $refund_ref . " has been approved by HI-POWER.\n"
                        . "Requested amount: PHP " . number_format((float)($pdo->query("SELECT amount FROM refunds WHERE refund_id = " . (int)$refund_id)->fetchColumn() ?: 0), 2) . "\n\n"
                        . "Please note that approval confirms your request; the actual refund may still require processing by the payment provider.\n\n"
                        . "Thank you,\nHI-POWER Support";
                    $headers = "From: HI-POWER Support <no-reply@" . (preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? 'hipowerbc.xyz') ?: 'hipowerbc.xyz') . ">\r\n"
                        . "Content-Type: text/plain; charset=UTF-8\r\n";
                    if (mail($recipient, $subject, $body, $headers)) {
                        $success_message .= " Approval email sent to " . $recipient . ".";
                    } else {
                        $error_message = "Refund approved, but the email could not be sent. Configure the server's mail transport (SMTP/sendmail) and retry notification manually.";
                    }
                } else {
                    $error_message = "Refund approved, but the customer's account email is missing or invalid.";
                }
            }
        } catch (Exception $e) {
            $error_message = "Failed to process refund: " . $e->getMessage();
        }
    }
}

// ==========================================
// FETCH REFUNDS
// ==========================================
$refunds = [];
$metrics = ['pending' => 0, 'approved' => 0, 'refunded' => 0, 'total' => 0.0];
$table_ready = true;

try {
    // Ensure proof_path column exists (migration-safe).
    try {
        if (!$pdo->query("SHOW COLUMNS FROM refunds LIKE 'proof_path'")->fetch()) {
            $pdo->exec("ALTER TABLE refunds ADD COLUMN proof_path VARCHAR(255) NULL AFTER reason");
        }
    } catch (PDOException $e) { /* ignore */ }

    $refunds = $pdo->query("
        SELECT r.refund_id, r.amount, r.reason, r.status, r.admin_notes, r.requested_at, r.processed_at,
               r.booking_id, r.order_id, r.proof_path, u.full_name, u.email
        FROM refunds r
        JOIN Users u ON u.user_id = r.user_id
        ORDER BY FIELD(r.status, 'Pending', 'Approved', 'Refunded', 'Rejected'), r.requested_at DESC
    ")->fetchAll();

    $metrics['pending']  = $pdo->query("SELECT COUNT(*) FROM refunds WHERE status = 'Pending'")->fetchColumn() ?? 0;
    $metrics['approved'] = $pdo->query("SELECT COUNT(*) FROM refunds WHERE status = 'Approved'")->fetchColumn() ?? 0;
    $metrics['refunded'] = $pdo->query("SELECT COUNT(*) FROM refunds WHERE status = 'Refunded'")->fetchColumn() ?? 0;
    $metrics['total']    = (float)($pdo->query("SELECT SUM(amount) FROM refunds WHERE status = 'Refunded'")->fetchColumn() ?? 0);
} catch (PDOException $e) {
    $table_ready = false;
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Refund Requests | HI-POWER Admin</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <style>
        .refund-row:hover { background: #f8f9fc; }
        @keyframes modalIn { from { opacity: 0; transform: translateY(14px) scale(0.98); } to { opacity: 1; transform: none; } }
        .badge { display:inline-flex; align-items:center; gap:5px; padding:3px 11px; border-radius:9999px; font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
        .badge--pending  { background:#fffbeb; color:#b45309; border:1px solid #fde68a; }
        .badge--approved { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
        .badge--refunded { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
        .badge--rejected { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
    </style>
</head>
<body class="bg-background min-h-screen">

<form id="refundActionForm" method="POST" action="refunds" style="display:none;">
    <?php echo get_csrf_input(); ?>
    <input type="hidden" name="action" value="process_refund">
    <input type="hidden" name="refund_id" id="ra_refund_id">
    <input type="hidden" name="status" id="ra_status">
    <input type="hidden" name="admin_notes" id="ra_notes">
</form>

<?php include __DIR__ . '/includes/admin_header.php'; ?>

<main class="md:ml-[260px] p-4 md:p-8 min-h-screen">

    <?php if (!empty($success_message)): ?>
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-3 shadow-sm">
            <div class="w-9 h-9 rounded-xl bg-emerald-100 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-emerald-600 text-[20px]">check_circle</span>
            </div>
            <p class="text-emerald-700 text-sm font-semibold"><?php echo htmlspecialchars($success_message); ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-2xl flex items-center gap-3 shadow-sm">
            <div class="w-9 h-9 rounded-xl bg-red-100 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-red-600 text-[20px]">error</span>
            </div>
            <p class="text-red-700 text-sm font-semibold"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php endif; ?>

    <div class="mb-8 mt-4">
        <div class="flex items-center gap-3 mb-1">
            <div class="w-10 h-10 rounded-xl bg-purple-50 flex items-center justify-center">
                <span class="material-symbols-outlined text-purple-600 text-[22px]">currency_exchange</span>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-on-surface tracking-tight">Refund Requests</h1>
                <p class="text-sm text-on-surface-variant mt-0.5">Review, approve and settle customer refund requests.</p>
                <div class="hp-page-actions mt-4 no-print">
                    <button type="button" class="hp-action hp-action--primary" onclick="exportRefundsCSV()"><span class="material-symbols-outlined">download</span> Export CSV</button>
                    <button type="button" class="hp-action" onclick="window.print()"><span class="material-symbols-outlined">print</span> Print</button>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$table_ready): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-sm text-amber-800">
            <p class="font-bold mb-1">Refund table not found.</p>
            <p>Run <code class="bg-amber-100 px-1.5 py-0.5 rounded">database_admin_upgrade.sql</code> once via phpMyAdmin to enable the refund workflow.</p>
        </div>
    <?php else: ?>

    <!-- Metrics -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5">
            <p class="text-xs font-bold text-outline uppercase tracking-widest">Pending</p>
            <p class="text-2xl font-bold text-amber-600 mt-1"><?php echo number_format($metrics['pending']); ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5">
            <p class="text-xs font-bold text-outline uppercase tracking-widest">Approved</p>
            <p class="text-2xl font-bold text-blue-600 mt-1"><?php echo number_format($metrics['approved']); ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5">
            <p class="text-xs font-bold text-outline uppercase tracking-widest">Refunded</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1"><?php echo number_format($metrics['refunded']); ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5">
            <p class="text-xs font-bold text-outline uppercase tracking-widest">Total Refunded</p>
            <p class="text-2xl font-bold text-on-surface mt-1">₱<?php echo number_format($metrics['total'], 2); ?></p>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table id="refundsTable" class="w-full text-left border-collapse min-w-[900px]">
                <thead>
                    <tr class="border-b border-outline-variant/20 bg-surface-container-low">
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Refund</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Customer</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Related To</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Reason</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Proof</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Amount</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Status</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/20">
                    <?php if (empty($refunds)): ?>
                        <tr>
                            <td colspan="8" class="py-16 text-center">
                                <div class="flex flex-col items-center">
                                    <span class="material-symbols-outlined text-outline-variant text-[48px] mb-3">currency_exchange</span>
                                    <p class="text-on-surface-variant font-semibold text-sm">No refund requests</p>
                                    <p class="text-outline text-xs mt-1">Customer refund requests will appear here.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($refunds as $r):
                            $badge = 'badge--pending';
                            if ($r['status'] === 'Approved') $badge = 'badge--approved';
                            elseif ($r['status'] === 'Refunded') $badge = 'badge--refunded';
                            elseif ($r['status'] === 'Rejected') $badge = 'badge--rejected';
                            $words = explode(' ', trim($r['full_name']));
                            $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                        ?>
                        <tr class="refund-row transition-colors">
                            <td class="py-4 px-6">
                                <p class="font-bold text-sm font-mono text-primary">#REF-<?php echo str_pad($r['refund_id'], 5, '0', STR_PAD_LEFT); ?></p>
                                <p class="text-[10px] text-outline mt-0.5"><?php echo date('M d, Y', strtotime($r['requested_at'])); ?></p>
                            </td>
                            <td class="py-4 px-6">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-primary/10 text-primary flex items-center justify-center text-[11px] font-bold shrink-0"><?php echo $initials; ?></div>
                                    <div class="min-w-0">
                                        <p class="font-semibold text-sm text-on-surface truncate"><?php echo htmlspecialchars($r['full_name']); ?></p>
                                        <p class="text-[11px] text-outline truncate"><?php echo htmlspecialchars($r['email']); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 px-6 text-sm text-on-surface-variant">
                                <?php if (!empty($r['booking_id'])): ?>Booking #<?php echo (int)$r['booking_id']; ?>
                                <?php elseif (!empty($r['order_id'])): ?>Order #ORD-<?php echo str_pad($r['order_id'], 5, '0', STR_PAD_LEFT); ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="py-4 px-6 text-sm text-on-surface-variant max-w-[220px]"><span class="line-clamp-2"><?php echo htmlspecialchars($r['reason'] ?: '—'); ?></span></td>
                            <td class="py-4 px-6 text-sm">
                                <?php if (!empty($r['proof_path'])): ?>
                                    <a href="<?php echo htmlspecialchars($r['proof_path']); ?>" target="_blank" class="text-primary font-semibold hover:underline text-xs">View</a>
                                <?php else: ?>
                                    <span class="text-outline text-xs">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-6 text-sm font-bold text-on-surface">₱<?php echo number_format($r['amount'], 2); ?></td>
                            <td class="py-4 px-6"><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
                            <td class="py-4 px-6">
                                <div class="flex justify-end gap-1.5">
                                    <?php if ($r['status'] === 'Pending'): ?>
                                        <button onclick="processRefund(<?php echo (int)$r['refund_id']; ?>, 'Approved')" class="h-9 px-3 rounded-xl text-xs font-bold text-blue-600 hover:bg-blue-50 transition-all" title="Approve">Approve</button>
                                    <?php endif; ?>
                                    <?php if (in_array($r['status'], ['Pending', 'Approved'], true)): ?>
                                        <button onclick="processRefund(<?php echo (int)$r['refund_id']; ?>, 'Refunded')" class="h-9 px-3 rounded-xl text-xs font-bold text-emerald-600 hover:bg-emerald-50 transition-all" title="Mark released">Mark Released</button>
                                        <button onclick="processRefund(<?php echo (int)$r['refund_id']; ?>, 'Rejected')" class="h-9 px-3 rounded-xl text-xs font-bold text-red-500 hover:bg-red-50 transition-all" title="Reject">Reject</button>
                                    <?php endif; ?>
                                    <?php if (in_array($r['status'], ['Refunded', 'Rejected'], true)): ?>
                                        <span class="text-[11px] text-outline italic"><?php echo htmlspecialchars($r['processed_at'] ? date('M d, Y', strtotime($r['processed_at'])) : 'Completed'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

    <footer class="mt-12 pt-6 border-t border-outline-variant/30 flex flex-col sm:flex-row justify-between items-center gap-4 text-[11px] font-bold tracking-widest text-outline uppercase pb-8">
        <p>&copy; <?php echo date('Y'); ?> HI-POWER Technologies</p>
    </footer>
</main>

<script>
    function exportRefundsCSV() {
        const table = document.getElementById('refundsTable');
        if (!table) return;
        const rows = Array.from(table.querySelectorAll('tr')).map(row =>
            Array.from(row.querySelectorAll('th, td')).slice(0, 7).map(cell =>
                '"' + (cell.innerText || '').replace(/\\s+/g, ' ').trim().replace(/"/g, '""') + '"'
            ).join(',')
        );
        const blob = new Blob(["\\uFEFF" + rows.join("\\r\\n")], {type:'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = 'hi-power-refunds-' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    }

    function processRefund(refundId, status) {
        const labels = { 'Approved': 'approve', 'Refunded': 'mark as released', 'Rejected': 'reject' };
        let note = prompt('Optional note for this action (e.g. reference number):', '');
        if (note === null) return; // cancelled
        if (!confirm('Are you sure you want to ' + labels[status] + ' refund #REF-' + String(refundId).padStart(5, '0') + '?')) return;
        document.getElementById('ra_refund_id').value = refundId;
        document.getElementById('ra_status').value = status;
        document.getElementById('ra_notes').value = note;
        document.getElementById('refundActionForm').submit();
    }
</script>

</body>
</html>
