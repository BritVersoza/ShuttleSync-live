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
// HANDLE SUPPORT TICKET ACTIONS
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    verify_csrf_token();
    if ($_POST['action'] == 'update_status') {
        try {
            $ticket_id = (int)$_POST['ticket_id'];
            $new_status = $_POST['status'] ?? '';
            $admin_notes = trim($_POST['admin_notes'] ?? '');
            $valid_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];

            if (!in_array($new_status, $valid_statuses, true)) {
                throw new Exception("Invalid support status.");
            }

            $cur = $pdo->prepare("SELECT status FROM Support_Tickets WHERE ticket_id = ?");
            $cur->execute([$ticket_id]);
            $current = $cur->fetchColumn();
            if ($current === false) throw new Exception("Support ticket not found.");

            $pdo->prepare("UPDATE Support_Tickets SET status = ?, admin_notes = ?, updated_at = NOW() WHERE ticket_id = ?")
                ->execute([$new_status, $admin_notes !== '' ? $admin_notes : null, $ticket_id]);

            $success_message = "Support ticket #TKT-" . str_pad($ticket_id, 5, '0', STR_PAD_LEFT) . " marked as " . $new_status . ".";
        } catch (Exception $e) {
            $error_message = "Failed to update support ticket: " . $e->getMessage();
        }
    }
}

// ==========================================
// FETCH SUPPORT TICKETS
// ==========================================
$tickets = [];
$ticket_metrics = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
$table_ready = true;

try {
    // Ensure Support_Tickets table exists (self-healing for deployments that missed the migration).
    $pdo->exec("CREATE TABLE IF NOT EXISTS Support_Tickets (ticket_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, subject VARCHAR(255) NOT NULL, category VARCHAR(64) NOT NULL DEFAULT 'General', message TEXT NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'Open', admin_notes VARCHAR(1000) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL DEFAULT NULL, INDEX idx_support_user (user_id), INDEX idx_support_status (status))");

    $tickets = $pdo->query("
        SELECT t.ticket_id, t.subject, t.category, t.message, t.status, t.admin_notes, t.created_at, t.updated_at,
               u.full_name, u.email, u.user_id as owner_user_id
        FROM Support_Tickets t
        LEFT JOIN Users u ON u.user_id = t.user_id
        ORDER BY FIELD(t.status, 'Open', 'In Progress', 'Resolved', 'Closed'), t.created_at DESC
    ")->fetchAll();

    $ticket_metrics['open'] = (int)($pdo->query("SELECT COUNT(*) FROM Support_Tickets WHERE status = 'Open'")->fetchColumn() ?? 0);
    $ticket_metrics['in_progress'] = (int)($pdo->query("SELECT COUNT(*) FROM Support_Tickets WHERE status = 'In Progress'")->fetchColumn() ?? 0);
    $ticket_metrics['resolved'] = (int)($pdo->query("SELECT COUNT(*) FROM Support_Tickets WHERE status = 'Resolved'")->fetchColumn() ?? 0);
    $ticket_metrics['closed'] = (int)($pdo->query("SELECT COUNT(*) FROM Support_Tickets WHERE status = 'Closed'")->fetchColumn() ?? 0);
} catch (PDOException $e) {
    $table_ready = false;
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Support Tickets | HI-POWER Admin</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <style>
        .support-row:hover { background: #f8f9fc; }
        .badge { display:inline-flex; align-items:center; gap:5px; padding:3px 11px; border-radius:9999px; font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
        .badge--open { background:#fffbeb; color:#b45309; border:1px solid #fde68a; }
        .badge--in_progress { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
        .badge--resolved { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
        .badge--closed { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
    </style>
</head>
<body class="bg-background min-h-screen">

<form id="supportActionForm" method="POST" action="support" style="display:none;">
    <?php echo get_csrf_input(); ?>
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="ticket_id" id="sa_ticket_id">
    <input type="hidden" name="status" id="sa_status">
    <input type="hidden" name="admin_notes" id="sa_notes">
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
            <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center">
                <span class="material-symbols-outlined text-teal-600 text-[22px]">headset</span>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-on-surface tracking-tight">Support Tickets</h1>
                <p class="text-sm text-on-surface-variant mt-0.5">View and manage customer support requests and refund tickets.</p>
            </div>
        </div>
    </div>

    <?php if (!$table_ready): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-sm text-amber-800">
            <p class="font-bold mb-1">Support tickets table not found.</p>
            <p>Run the database upgrade script once via phpMyAdmin to enable the support workflow.</p>
        </div>
    <?php else: ?>

    <!-- Metrics -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5">
            <p class="text-xs font-bold text-outline uppercase tracking-widest">Open</p>
            <p class="text-2xl font-bold text-amber-600 mt-1"><?php echo number_format($ticket_metrics['open']); ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5">
            <p class="text-xs font-bold text-outline uppercase tracking-widest">In Progress</p>
            <p class="text-2xl font-bold text-blue-600 mt-1"><?php echo number_format($ticket_metrics['in_progress']); ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5">
            <p class="text-xs font-bold text-outline uppercase tracking-widest">Resolved</p>
            <p class="text-2xl font-bold text-emerald-600 mt-1"><?php echo number_format($ticket_metrics['resolved']); ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5">
            <p class="text-xs font-bold text-outline uppercase tracking-widest">Closed</p>
            <p class="text-2xl font-bold text-on-surface mt-1"><?php echo number_format($ticket_metrics['closed']); ?></p>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[1000px]">
                <thead>
                    <tr class="border-b border-outline-variant/20 bg-surface-container-low">
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Ticket</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Customer</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Category</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Message</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Status</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Date</th>
                        <th class="py-4 px-6 text-[10px] font-bold text-outline uppercase tracking-widest">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="7" class="py-14 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-12 h-12 rounded-xl bg-surface-container-low flex items-center justify-center">
                                        <span class="material-symbols-outlined text-outline text-[22px]">headset</span>
                                    </div>
                                    <p class="font-bold text-on-surface text-sm">No support tickets yet.</p>
                                    <p class="text-on-surface-variant text-xs">Customer support requests will appear here.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($tickets as $t): ?>
                        <tr class="support-row border-b border-outline-variant/10">
                            <td class="py-4 px-6">
                                <p class="font-bold text-sm font-mono text-primary">#TKT-<?php echo str_pad($t['ticket_id'], 5, '0', STR_PAD_LEFT); ?></p>
                            </td>
                            <td class="py-4 px-6">
                                <p class="font-bold text-sm text-on-surface"><?php echo htmlspecialchars($t['full_name'] ?? 'Guest'); ?></p>
                                <p class="text-xs text-on-surface-variant"><?php echo htmlspecialchars($t['email'] ?? '—'); ?></p>
                            </td>
                            <td class="py-4 px-6">
                                <span class="badge bg-surface-container text-on-surface border border-outline-variant/40"><?php echo htmlspecialchars($t['category']); ?></span>
                            </td>
                            <td class="py-4 px-6">
                                <p class="text-sm text-on-surface-variant line-clamp-2"><?php echo nl2br(htmlspecialchars($t['message'])); ?></p>
                                <?php if (!empty($t['admin_notes'])): ?>
                                    <p class="text-xs text-outline mt-1"><span class="font-bold">Admin note:</span> <?php echo htmlspecialchars($t['admin_notes']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-6">
                                <span class="badge badge--<?php echo htmlspecialchars($t['status']); ?>"><?php echo htmlspecialchars($t['status']); ?></span>
                            </td>
                            <td class="py-4 px-6 text-sm text-on-surface-variant"><?php echo date('M d, Y H:i', strtotime($t['created_at'])); ?></td>
                            <td class="py-4 px-6">
                                <div class="flex items-center gap-2">
                                    <select id="sa_status_sel_<?php echo (int)$t['ticket_id']; ?>" class="bg-surface border border-outline-variant/40 rounded-lg px-2 py-1.5 text-xs font-bold text-on-surface">
                                        <?php foreach (['Open', 'In Progress', 'Resolved', 'Closed'] as $st): ?>
                                            <option value="<?php echo $st; ?>" <?php echo $t['status'] === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="h-9 px-3 rounded-xl text-xs font-bold text-primary hover:bg-primary/5 transition-all" onclick="updateSupportStatus(<?php echo (int)$t['ticket_id']; ?>, this)">Update</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</main>

<script>
    function updateSupportStatus(ticketId, el) {
        const statusEl = document.getElementById('sa_status_sel_' + ticketId);
        const status = statusEl ? statusEl.value : 'Open';
        const notesEl = document.getElementById('sa_notes');
        const notes = notesEl ? notesEl.value.trim() : '';

        const form = document.getElementById('supportActionForm');
        document.getElementById('sa_ticket_id').value = ticketId;
        document.getElementById('sa_status').value = status;
        document.getElementById('sa_notes').value = notes;
        form.submit();
    }
</script>

</body>
</html>
