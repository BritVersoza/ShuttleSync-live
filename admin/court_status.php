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

$admin_name = $_SESSION['full_name'];
$admin_role = $_SESSION['role'];
$success_message = '';
$error_message = '';

// ==========================================
// DATE NAVIGATION LOGIC FOR COURTS
// ==========================================
$view_date = isset($_GET['date']) ? date('Y-m-d', strtotime($_GET['date'])) : date('Y-m-d');
$display_date = date('M d, Y', strtotime($view_date));
$prev_date = date('Y-m-d', strtotime('-1 day', strtotime($view_date)));
$next_date = date('Y-m-d', strtotime('+1 day', strtotime($view_date)));

// ==========================================
// SCHEMA (self-healing) + PHOTO SETTINGS
// ==========================================
$court_img_dir   = __DIR__ . '/../img/courts/';   // physical folder
$court_img_url   = '/img/courts/';                // public URL (same path courtbooking uses)
$max_imgs_court  = 6;                             // photos per court
$max_img_mb      = 20;                            // MB per photo (change this number to raise/lower the limit)
$max_img_bytes   = $max_img_mb * 1024 * 1024;
$reopen_court    = 0;                             // re-open the manage modal after a save

try {
    // Editable hourly rate (defaults to the old fixed rate of 300).
    if (!$pdo->query("SHOW COLUMNS FROM Courts LIKE 'price_per_hour'")->fetch()) {
        $pdo->exec("ALTER TABLE Courts ADD COLUMN price_per_hour DECIMAL(10,2) NOT NULL DEFAULT 300.00");
    }
    // Photos shown in the court-booking "view" popup.
    $pdo->exec("CREATE TABLE IF NOT EXISTS court_images (
        image_id INT AUTO_INCREMENT PRIMARY KEY,
        court_id INT NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_court_images_court (court_id)
    ) ENGINE=InnoDB");
} catch (PDOException $e) {}

// ==========================================
// HANDLE COURT ACTIONS
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    verify_csrf_token();
    
    // --- TOGGLE COURT STATUS ---
    if ($_POST['action'] == 'toggle_court_status') {
        $court_id   = (int)($_POST['court_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';
        $notes      = trim($_POST['notes'] ?? '');
        $mstart     = trim($_POST['maintenance_start'] ?? '');
        $mend       = trim($_POST['maintenance_end'] ?? '');
        $override   = (($_POST['override_bookings'] ?? '') === '1');

        if (!in_array($new_status, ['Available', 'Maintenance', 'Occupied'], true)) {
            $error_message = "Invalid court status.";
        } elseif ($new_status === 'Maintenance') {
            try {
                // TC-AS-017: a maintenance entry needs a valid effective range.
                if ($mstart === '' || $mend === '') {
                    throw new Exception("Please provide the maintenance start and end date/time.");
                }
                $ms = strtotime($mstart);
                $me = strtotime($mend);
                if ($ms === false || $me === false) throw new Exception("Invalid maintenance date/time.");
                if ($me <= $ms) throw new Exception("Maintenance end must be after the start.");
                $start_sql = date('Y-m-d H:i:s', $ms);
                $end_sql   = date('Y-m-d H:i:s', $me);

                // TC-AS-016: does this window overlap active bookings?
                $conf = $pdo->prepare("SELECT COUNT(*) FROM Bookings WHERE court_id = ? AND status IN ('Confirmed','Pending Payment','Pending') AND TIMESTAMP(booking_date, start_time) < ? AND TIMESTAMP(booking_date, end_time) > ?");
                $conf->execute([$court_id, $end_sql, $start_sql]);
                $conflict_count = (int)$conf->fetchColumn();

                if ($conflict_count > 0 && !$override) {
                    throw new Exception("This maintenance window overlaps {$conflict_count} active booking(s). Tick \"override\" to cancel them, then save again.");
                }

                $pdo->beginTransaction();

                try {
                    $pdo->prepare("UPDATE Courts SET status = 'Maintenance', maintenance_notes = ?, maintenance_start = ?, maintenance_end = ? WHERE court_id = ?")
                        ->execute([$notes, $start_sql, $end_sql, $court_id]);
                } catch (PDOException $e) {
                    // maintenance window columns not migrated yet.
                    $pdo->prepare("UPDATE Courts SET status = 'Maintenance', maintenance_notes = ? WHERE court_id = ?")
                        ->execute([$notes, $court_id]);
                }

                $cancelled = 0;
                if ($conflict_count > 0 && $override) {
                    try {
                        $stmt = $pdo->prepare("UPDATE Bookings SET status = 'Cancelled', cancel_reason = 'Court placed under maintenance', cancelled_at = NOW() WHERE court_id = ? AND status IN ('Confirmed','Pending Payment','Pending') AND TIMESTAMP(booking_date, start_time) < ? AND TIMESTAMP(booking_date, end_time) > ?");
                        $stmt->execute([$court_id, $end_sql, $start_sql]);
                    } catch (PDOException $e) {
                        $stmt = $pdo->prepare("UPDATE Bookings SET status = 'Cancelled' WHERE court_id = ? AND status IN ('Confirmed','Pending Payment','Pending') AND TIMESTAMP(booking_date, start_time) < ? AND TIMESTAMP(booking_date, end_time) > ?");
                        $stmt->execute([$court_id, $end_sql, $start_sql]);
                    }
                    $cancelled = $stmt->rowCount();
                }

                $pdo->commit();

                $success_message = "Court marked as Under Maintenance.";
                if ($cancelled > 0) {
                    $success_message .= " {$cancelled} conflicting booking(s) cancelled and released.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error_message = $e->getMessage();
            }
        } else {
            // Available / Occupied - clear any maintenance window.
            try {
                $pdo->prepare("UPDATE Courts SET status = ?, maintenance_notes = ?, maintenance_start = NULL, maintenance_end = NULL WHERE court_id = ?")
                    ->execute([$new_status, $notes, $court_id]);
            } catch (PDOException $e) {
                $pdo->prepare("UPDATE Courts SET status = ?, maintenance_notes = ? WHERE court_id = ?")
                    ->execute([$new_status, $notes, $court_id]);
            }
            $success_message = "Court status updated to " . htmlspecialchars($new_status) . "!";
        }
    }

    // --- UPDATE COURT PRICE ---
    if ($_POST['action'] == 'update_court_price') {
        $court_id = (int)($_POST['court_id'] ?? 0);
        $reopen_court = $court_id;
        $price = filter_var(trim($_POST['price_per_hour'] ?? ''), FILTER_VALIDATE_FLOAT);
        if ($court_id <= 0) {
            $error_message = "Invalid court.";
        } elseif ($price === false || $price <= 0 || $price > 100000) {
            $error_message = "Please enter a valid price greater than 0.";
        } else {
            try {
                $pdo->prepare("UPDATE Courts SET price_per_hour = ? WHERE court_id = ?")->execute([round($price, 2), $court_id]);
                $success_message = "Court price updated to ₱" . number_format($price, 2) . " per hour. New bookings will use this rate.";
            } catch (PDOException $e) {
                $error_message = "Could not update the price. Please try again.";
            }
        }
    }

    // --- UPLOAD COURT PHOTOS ---
    if ($_POST['action'] == 'upload_court_images') {
        $court_id = (int)($_POST['court_id'] ?? 0);
        $reopen_court = $court_id;
        try {
            if ($court_id <= 0) throw new Exception("Invalid court.");
            $exists = $pdo->prepare("SELECT COUNT(*) FROM Courts WHERE court_id = ?");
            $exists->execute([$court_id]);
            if (!(int)$exists->fetchColumn()) throw new Exception("Court not found.");

            if (empty($_FILES['images']) || !is_array($_FILES['images']['name'])) {
                throw new Exception("Please choose at least one photo.");
            }
            if (!is_dir($court_img_dir) && !@mkdir($court_img_dir, 0755, true)) {
                throw new Exception("The photo folder (/img/courts) could not be created. Check folder permissions.");
            }

            $cntStmt = $pdo->prepare("SELECT COUNT(*), COALESCE(MAX(sort_order), 0) FROM court_images WHERE court_id = ?");
            $cntStmt->execute([$court_id]);
            [$existing_imgs, $max_sort] = array_map('intval', $cntStmt->fetch(PDO::FETCH_NUM));

            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $saved = 0; $skipped = [];

            foreach ($_FILES['images']['name'] as $i => $orig) {
                $err = $_FILES['images']['error'][$i];
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                $label = basename((string)$orig);
                if ($existing_imgs + $saved >= $max_imgs_court) { $skipped[] = "$label (limit of {$max_imgs_court} photos reached)"; continue; }
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $skipped[] = "$label (too large)"; continue; }
                if ($err !== UPLOAD_ERR_OK) { $skipped[] = "$label (upload failed)"; continue; }
                if ($_FILES['images']['size'][$i] > $max_img_bytes) { $skipped[] = "$label (over {$max_img_mb} MB)"; continue; }

                $tmp = $_FILES['images']['tmp_name'][$i];
                if (!is_uploaded_file($tmp)) { $skipped[] = "$label (invalid upload)"; continue; }
                // getimagesize() reads the real image header (no fileinfo extension needed).
                $info = @getimagesize($tmp);
                $mime = $info['mime'] ?? '';
                if ($info === false || !isset($allowed[$mime])) { $skipped[] = "$label (use JPG, PNG or WebP)"; continue; }

                $fname = 'court' . $court_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
                if (!move_uploaded_file($tmp, $court_img_dir . $fname)) { $skipped[] = "$label (could not be saved)"; continue; }

                $pdo->prepare("INSERT INTO court_images (court_id, image_path, sort_order) VALUES (?, ?, ?)")
                    ->execute([$court_id, $fname, $max_sort + $saved + 1]);
                $saved++;
            }

            if ($saved > 0) {
                $success_message = $saved . " photo" . ($saved === 1 ? '' : 's') . " uploaded. They now show on the court booking page.";
                if ($skipped) $success_message .= " Skipped: " . implode('; ', $skipped) . ".";
            } else {
                throw new Exception($skipped ? "No photos were uploaded. " . implode('; ', $skipped) . "." : "Please choose at least one photo.");
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }

    // --- DELETE COURT PHOTO ---
    if ($_POST['action'] == 'delete_court_image') {
        $court_id = (int)($_POST['court_id'] ?? 0);
        $image_id = (int)($_POST['image_id'] ?? 0);
        $reopen_court = $court_id;
        try {
            $q = $pdo->prepare("SELECT image_path FROM court_images WHERE image_id = ? AND court_id = ?");
            $q->execute([$image_id, $court_id]);
            $path = $q->fetchColumn();
            if ($path === false) throw new Exception("Photo not found.");
            $pdo->prepare("DELETE FROM court_images WHERE image_id = ? AND court_id = ?")->execute([$image_id, $court_id]);
            $file = $court_img_dir . basename($path);
            if (is_file($file)) @unlink($file);
            $success_message = "Photo removed.";
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}

// ==========================================
// FETCH COURT DATA & SCHEDULES
// ==========================================
try {
    $courts = $pdo->query("SELECT court_id, name, status, maintenance_notes, maintenance_start, maintenance_end, price_per_hour FROM Courts ORDER BY court_id ASC")->fetchAll();
} catch (PDOException $e) {
    try {
        $courts = $pdo->query("SELECT court_id, name, status, maintenance_notes, price_per_hour FROM Courts ORDER BY court_id ASC")->fetchAll();
    } catch (PDOException $e2) {
        $courts = $pdo->query("SELECT court_id, name, status, maintenance_notes FROM Courts ORDER BY court_id ASC")->fetchAll();
    }
}

// Photos per court (for the card badges, the eye popup and the manage popup).
$court_images = [];
try {
    foreach ($pdo->query("SELECT image_id, court_id, image_path FROM court_images ORDER BY court_id ASC, sort_order ASC, image_id ASC")->fetchAll() as $im) {
        $court_images[(int)$im['court_id']][] = ['id' => (int)$im['image_id'], 'url' => $court_img_url . basename($im['image_path'])];
    }
} catch (PDOException $e) {}

$court_js = [];
foreach ($courts as $c) {
    $cid = (int)$c['court_id'];
    $p = (float)($c['price_per_hour'] ?? 300);
    $court_js[$cid] = [
        'name'   => $c['name'],
        'price'  => $p > 0 ? $p : 300.0,
        'images' => $court_images[$cid] ?? [],
    ];
}

$bookings_stmt = $pdo->prepare("SELECT b.court_id, b.start_time, b.end_time, u.full_name FROM Bookings b JOIN Users u ON b.user_id = u.user_id WHERE b.booking_date = ? AND b.status = 'Confirmed' ORDER BY b.start_time ASC");
$bookings_stmt->execute([$view_date]);
$all_date_bookings = $bookings_stmt->fetchAll();

$court_schedules = [];
foreach($all_date_bookings as $booking) {
    $court_schedules[$booking['court_id']][] = $booking;
}

// Calculate Summary Stats
$total_courts = count($courts);
$count_available = 0;
$count_maintenance = 0;
$count_occupied = 0;

foreach ($courts as $c) {
    if ($c['status'] == 'Available') $count_available++;
    elseif ($c['status'] == 'Maintenance') $count_maintenance++;
    else $count_occupied++;
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Court Status & Maintenance | HI-POWER Admin</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <style>
        .court-card { transition: all 0.2s ease; }
        .court-card:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,0,0,0.08); }
        .status-pulse { animation: pulse-ring 2s ease-out infinite; }
        @keyframes pulse-ring {
            0% { transform: scale(0.8); opacity: 1; }
            100% { transform: scale(2.2); opacity: 0; }
        }
        .booking-item { transition: background 0.15s ease; }
        .booking-item:hover { background: rgba(255,87,34,0.04); }
        @keyframes modalIn { from { opacity: 0; transform: translateY(14px) scale(0.98); } to { opacity: 1; transform: none; } }
    </style>
</head>
<body class="bg-surface text-on-background min-h-screen">

<!-- Court status modal (maintenance window + override) -->
<div id="courtModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4" style="background: rgba(15,23,42,0.55); backdrop-filter: blur(2px);">
    <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" style="animation: modalIn .22s ease-out;">
        <form id="courtStatusForm" method="POST" action="court_status?date=<?php echo $view_date; ?>" class="p-6 space-y-5">
            <?php echo get_csrf_input(); ?>
            <input type="hidden" name="action" value="toggle_court_status">
            <input type="hidden" name="court_id" id="status_court_id">
            <input type="hidden" name="new_status" id="status_new_status" value="Maintenance">

            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[accent]/10 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[accent]">build</span>
                </div>
                <div>
                    <h3 class="font-bold text-on-surface" id="courtModalTitle">Set Court to Maintenance</h3>
                    <p class="text-xs text-outline" id="courtModalSub">&nbsp;</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" id="maintenanceFields">
                <div>
                    <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-widest mb-2">Start</label>
                    <input type="datetime-local" name="maintenance_start" id="status_start" class="w-full h-11 px-3 bg-surface border border-outline-variant/60 rounded-xl text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                </div>
                <div>
                    <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-widest mb-2">End</label>
                    <input type="datetime-local" name="maintenance_end" id="status_end" class="w-full h-11 px-3 bg-surface border border-outline-variant/60 rounded-xl text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-widest mb-2">Reason / Notes</label>
                <textarea name="notes" id="status_notes" rows="2" class="w-full px-3 py-2.5 bg-surface border border-outline-variant/60 rounded-xl text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 resize-none" placeholder="e.g. Net Repair, Deep Cleaning"></textarea>
            </div>

            <label id="overrideRow" class="hidden items-start gap-2.5 p-3 bg-amber-50 border border-amber-200 rounded-xl cursor-pointer">
                <input type="checkbox" name="override_bookings" id="status_override" value="1" class="mt-0.5 w-4 h-4 rounded border-gray-300 text-[accent] focus:ring-[accent]/20">
                <span class="text-xs text-amber-800 leading-snug">This court has active bookings. <strong>Override &amp; cancel</strong> any booking that overlaps the maintenance window.</span>
            </label>

            <div class="flex gap-3 pt-1">
                <button type="button" onclick="closeCourtModal()" class="flex-1 h-11 bg-surface border border-outline-variant/60 text-on-surface rounded-xl font-bold text-sm hover:bg-surface-container-high transition-all">Cancel</button>
                <button type="submit" class="flex-1 h-11 bg-[accent] text-white rounded-xl font-bold text-sm hover:bg-[#d13a23] transition-all">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Photo viewer modal (eye button) -->
<div id="photoModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4" style="background: rgba(15,23,42,0.6); backdrop-filter: blur(2px);">
    <div class="bg-white w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl shadow-2xl" style="animation: modalIn .22s ease-out;">
        <div class="flex items-center justify-between gap-3 p-5 border-b border-outline-variant/20">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-10 h-10 rounded-xl bg-[primary]/10 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[primary]">photo_library</span>
                </div>
                <div class="min-w-0">
                    <h3 class="font-bold text-on-surface truncate" id="photoModalTitle">Court photos</h3>
                    <p class="text-xs text-outline" id="photoModalSub">&nbsp;</p>
                </div>
            </div>
            <button type="button" onclick="closeOverlay('photoModal')" aria-label="Close" class="w-9 h-9 rounded-lg bg-surface-container hover:bg-red-50 hover:text-red-600 flex items-center justify-center transition-colors shrink-0">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>
        <div class="p-5">
            <div id="photoGrid" class="grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
            <div id="photoEmpty" class="hidden text-center py-10 border border-dashed border-outline-variant/50 rounded-xl bg-surface">
                <span class="material-symbols-outlined text-on-surface-variant/30 text-[40px] block mb-1">image_not_supported</span>
                <p class="text-sm font-semibold text-on-surface-variant">No photos yet for this court.</p>
                <button type="button" id="photoEmptyBtn" class="mt-3 px-4 py-2 bg-[primary] text-white rounded-xl text-sm font-bold hover:bg-[accent] transition-colors">Add photos</button>
            </div>
        </div>
    </div>
</div>

<!-- Manage modal: price + photos -->
<div id="manageModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4" style="background: rgba(15,23,42,0.6); backdrop-filter: blur(2px);">
    <div class="bg-white w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl shadow-2xl" style="animation: modalIn .22s ease-out;">
        <div class="flex items-center justify-between gap-3 p-5 border-b border-outline-variant/20 sticky top-0 bg-white z-10">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-10 h-10 rounded-xl bg-[primary]/10 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[primary]">tune</span>
                </div>
                <div class="min-w-0">
                    <h3 class="font-bold text-on-surface truncate" id="manageTitle">Edit court</h3>
                    <p class="text-xs text-outline" id="manageSub">&nbsp;</p>
                </div>
            </div>
            <button type="button" onclick="closeOverlay('manageModal')" aria-label="Close" class="w-9 h-9 rounded-lg bg-surface-container hover:bg-red-50 hover:text-red-600 flex items-center justify-center transition-colors shrink-0">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <div class="p-5 space-y-6">
            <!-- Price -->
            <form method="POST" action="court_status?date=<?php echo $view_date; ?>" class="space-y-2">
                <?php echo get_csrf_input(); ?>
                <input type="hidden" name="action" value="update_court_price">
                <input type="hidden" name="court_id" id="price_court_id">
                <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-widest" for="price_input">Price per hour</label>
                <div class="flex gap-3">
                    <div class="relative flex-1">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-bold text-on-surface-variant">₱</span>
                        <input type="number" name="price_per_hour" id="price_input" step="0.01" min="1" max="100000" required
                               class="w-full h-11 pl-8 pr-3 bg-surface border border-outline-variant/60 rounded-xl text-sm font-bold outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                    </div>
                    <button type="submit" class="px-5 h-11 bg-[accent] text-white rounded-xl font-bold text-sm hover:bg-[#d13a23] transition-all">Save price</button>
                </div>
                <p class="text-[11px] text-on-surface-variant/70">Applies to new bookings only. Existing bookings keep the price they were booked at.</p>
            </form>

            <!-- Current photos -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <p class="text-xs font-bold text-on-surface-variant uppercase tracking-widest">Court photos</p>
                    <span class="text-[11px] font-semibold text-on-surface-variant/70" id="manageCount"></span>
                </div>
                <div id="manageGrid" class="grid grid-cols-2 sm:grid-cols-3 gap-3"></div>
                <p id="manageEmpty" class="hidden text-sm text-on-surface-variant/70 italic py-4 text-center border border-dashed border-outline-variant/50 rounded-xl">No photos uploaded yet.</p>
            </div>

            <!-- Upload -->
            <form method="POST" enctype="multipart/form-data" action="court_status?date=<?php echo $view_date; ?>" class="space-y-2 p-4 bg-surface border border-outline-variant/30 rounded-xl">
                <?php echo get_csrf_input(); ?>
                <input type="hidden" name="action" value="upload_court_images">
                <input type="hidden" name="court_id" id="upload_court_id">
                <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-widest" for="upload_input">Add photos</label>
                <input type="file" name="images[]" id="upload_input" multiple required accept="image/jpeg,image/png,image/webp"
                       class="block w-full text-sm text-on-surface-variant file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-[primary]/10 file:text-[primary] file:font-bold hover:file:bg-[primary]/20">
                <p class="text-[11px] text-on-surface-variant/70">JPG, PNG or WebP · up to <?php echo (int)$max_img_mb; ?> MB each · max <?php echo (int)$max_imgs_court; ?> per court. These show in the eye popup on the court booking page.</p>
                <button type="submit" class="w-full h-11 bg-[primary] text-white rounded-xl font-bold text-sm hover:bg-[accent] transition-all">
                    <span class="material-symbols-outlined text-[18px] align-middle mr-1">upload</span> Upload photos
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Hidden form used by the photo delete buttons -->
<form id="imgDeleteForm" method="POST" action="court_status?date=<?php echo $view_date; ?>" style="display:none">
    <?php echo get_csrf_input(); ?>
    <input type="hidden" name="action" value="delete_court_image">
    <input type="hidden" name="court_id" id="del_court_id">
    <input type="hidden" name="image_id" id="del_image_id">
</form>

<?php include __DIR__ . '/includes/admin_header.php'; ?>

    <!-- Main Content -->
    <main class="md:ml-[260px] p-4 md:p-8 min-h-screen">

        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[primary] to-[primary]/80 flex items-center justify-center shadow-md shadow-[primary]/20">
                            <span class="material-symbols-outlined text-white text-[20px]" style="font-variation-settings:'FILL' 1;">stadium</span>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-on-surface tracking-tight">Court Management</h1>
                            <p class="text-sm text-on-surface-variant">Monitor facility status, manage maintenance, and view bookings.</p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Date Navigator -->
                    <div class="bg-white rounded-xl border border-outline-variant/30 shadow-sm flex items-center overflow-hidden">
                        <a href="court_status?date=<?php echo $prev_date; ?>" class="px-3 py-2.5 hover:bg-surface-container-low transition-colors flex items-center justify-center">
                            <span class="material-symbols-outlined text-[20px] text-on-surface-variant">chevron_left</span>
                        </a>
                        <div class="px-4 py-2.5 border-x border-outline-variant/20 min-w-[140px] text-center">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-on-surface-variant mb-0.5">Viewing</p>
                            <p class="text-sm font-bold text-on-surface"><?php echo $display_date; ?></p>
                        </div>
                        <a href="court_status?date=<?php echo $next_date; ?>" class="px-3 py-2.5 hover:bg-surface-container-low transition-colors flex items-center justify-center">
                            <span class="material-symbols-outlined text-[20px] text-on-surface-variant">chevron_right</span>
                        </a>
                    </div>

                    <button onclick="reloadPage()" class="flex items-center gap-2 px-4 py-2.5 bg-white border border-outline-variant/30 rounded-xl text-sm font-bold text-on-surface-variant hover:bg-surface-container-low hover:border-outline-variant/50 transition-all shadow-sm">
                        <span class="material-symbols-outlined text-[18px]" id="reload-icon">refresh</span>
                        <span class="hidden sm:inline">Refresh</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($success_message)): ?>
            <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-3 shadow-sm">
                <div class="w-9 h-9 rounded-lg bg-emerald-100 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-emerald-600 text-[20px]">check_circle</span>
                </div>
                <div>
                    <p class="text-sm font-bold text-emerald-800">Success</p>
                    <p class="text-sm text-emerald-700"><?php echo htmlspecialchars($success_message); ?></p>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3 shadow-sm">
                <div class="w-9 h-9 rounded-lg bg-red-100 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-red-600 text-[20px]">error</span>
                </div>
                <div>
                    <p class="text-sm font-bold text-red-800">Error</p>
                    <p class="text-sm text-red-700"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Summary Stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <!-- Total Courts -->
            <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5 group hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-surface-container flex items-center justify-center">
                        <span class="material-symbols-outlined text-on-surface-variant text-[20px]">sports_tennis</span>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-widest text-on-surface-variant/60">Total</span>
                </div>
                <p class="text-3xl font-extrabold text-on-surface tracking-tight"><?php echo str_pad($total_courts, 2, '0', STR_PAD_LEFT); ?></p>
                <p class="text-xs text-on-surface-variant mt-1">Registered courts</p>
            </div>

            <!-- Available -->
            <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5 group hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center">
                        <span class="material-symbols-outlined text-emerald-600 text-[20px]">check_circle</span>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-widest text-emerald-600">Open</span>
                </div>
                <p class="text-3xl font-extrabold text-on-surface tracking-tight"><?php echo str_pad($count_available, 2, '0', STR_PAD_LEFT); ?></p>
                <p class="text-xs text-on-surface-variant mt-1">Courts available</p>
            </div>

            <!-- Occupied -->
            <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5 group hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-[primary]/5 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[primary] text-[20px]">schedule</span>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-widest text-[primary]">Active</span>
                </div>
                <p class="text-3xl font-extrabold text-on-surface tracking-tight"><?php echo str_pad($count_occupied, 2, '0', STR_PAD_LEFT); ?></p>
                <p class="text-xs text-on-surface-variant mt-1">Currently in use</p>
            </div>

            <!-- Maintenance -->
            <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-5 group hover:shadow-md transition-shadow">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-10 h-10 rounded-xl bg-[accent]/5 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[accent] text-[20px]">build</span>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-widest text-[accent]">Down</span>
                </div>
                <p class="text-3xl font-extrabold text-on-surface tracking-tight"><?php echo str_pad($count_maintenance, 2, '0', STR_PAD_LEFT); ?></p>
                <p class="text-xs text-on-surface-variant mt-1">Under maintenance</p>
            </div>
        </div>

        <!-- Court Cards Grid -->
        <div id="court-grid" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-5">
            <?php foreach ($courts as $court): 
                $c_id = $court['court_id'];
                $current_bookings = $court_schedules[$c_id] ?? [];
                $booking_count = count($current_bookings);
                
                // Status-specific styling
                if ($court['status'] == 'Available') {
                    $status_color = 'emerald';
                    $status_bg = 'bg-emerald-50';
                    $status_text = 'text-emerald-700';
                    $status_border = 'border-emerald-200';
                    $icon_bg = 'bg-emerald-50';
                    $icon_color = 'text-emerald-600';
                    $card_icon = 'stadium';
                    $btn_text = 'Set Maintenance';
                    $btn_action = "setMaintenance($c_id, $booking_count)";
                    $btn_class = 'bg-white border-outline-variant/40 text-on-surface-variant hover:border-[accent]/40 hover:text-[accent] hover:bg-[accent]/5';
                    $accent_bar = 'bg-emerald-500';
                } elseif ($court['status'] == 'Maintenance') {
                    $status_color = 'red';
                    $status_bg = 'bg-red-50';
                    $status_text = 'text-red-700';
                    $status_border = 'border-red-200';
                    $icon_bg = 'bg-red-50';
                    $icon_color = 'text-red-500';
                    $card_icon = 'handyman';
                    $btn_text = 'Mark Available';
                    $btn_action = "setAvailable($c_id)";
                    $btn_class = 'bg-[accent] text-white hover:bg-[#d13a23] border-transparent shadow-sm shadow-[accent]/20';
                    $accent_bar = 'bg-[accent]';
                } else {
                    $status_color = 'blue';
                    $status_bg = 'bg-[primary]/5';
                    $status_text = 'text-[primary]';
                    $status_border = 'border-[primary]/20';
                    $icon_bg = 'bg-[primary]/5';
                    $icon_color = 'text-[primary]';
                    $card_icon = 'sports_tennis';
                    $btn_text = 'Set Maintenance';
                    $btn_action = "setMaintenance($c_id, $booking_count)";
                    $btn_class = 'bg-white border-outline-variant/40 text-on-surface-variant hover:border-[accent]/40 hover:text-[accent] hover:bg-[accent]/5';
                    $accent_bar = 'bg-[primary]';
                }
            ?>
            <div class="court-card bg-white rounded-2xl border border-outline-variant/30 shadow-sm overflow-hidden flex flex-col relative">
                
                <!-- Top accent bar -->
                <div class="h-1 <?php echo $accent_bar; ?>"></div>

                <!-- Card Header -->
                <div class="p-5 pb-4">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl <?php echo $icon_bg; ?> flex items-center justify-center relative">
                                <span class="material-symbols-outlined <?php echo $icon_color; ?> text-[22px]" style="font-variation-settings: 'FILL' 1;"><?php echo $card_icon; ?></span>
                                <?php if($court['status'] == 'Available'): ?>
                                    <span class="absolute -top-0.5 -right-0.5 w-3 h-3 bg-emerald-500 rounded-full border-2 border-white"></span>
                                    <span class="absolute -top-0.5 -right-0.5 w-3 h-3 bg-emerald-400 rounded-full status-pulse"></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h3 class="font-bold text-on-surface text-[15px] leading-tight"><?php echo htmlspecialchars($court['name']); ?></h3>
                                <p class="text-xs text-on-surface-variant mt-0.5">Court #<?php echo $c_id; ?></p>
                                <p class="text-xs font-bold text-[primary] mt-0.5">
                                    ₱<?php echo number_format($court_js[(int)$c_id]['price'], $court_js[(int)$c_id]['price'] == floor($court_js[(int)$c_id]['price']) ? 0 : 2); ?>/hr
                                    <span class="text-on-surface-variant/60 font-medium"> · <?php $pc = count($court_js[(int)$c_id]['images']); echo $pc . ($pc === 1 ? ' photo' : ' photos'); ?></span>
                                </p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg <?php echo $status_bg; ?> <?php echo $status_text; ?> border <?php echo $status_border; ?> text-[11px] font-bold uppercase tracking-wide">
                            <span class="w-1.5 h-1.5 rounded-full <?php echo str_replace('text-', 'bg-', $status_text); ?>"></span>
                            <?php echo htmlspecialchars($court['status']); ?>
                        </span>
                    </div>

                    <?php if ($court['status'] == 'Maintenance'): ?>
                        <!-- Maintenance Info -->
                        <div class="bg-red-50/60 border border-red-100 rounded-xl p-3.5">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="material-symbols-outlined text-red-500 text-[16px]">warning</span>
                                <p class="text-[10px] font-bold uppercase tracking-widest text-red-600">Maintenance Notes</p>
                            </div>
                            <p class="text-sm text-red-800 font-medium leading-relaxed"><?php echo htmlspecialchars($court['maintenance_notes'] ?: 'General maintenance in progress'); ?></p>
                            <?php if (!empty($court['maintenance_start']) || !empty($court['maintenance_end'])): ?>
                                <p class="text-[11px] text-red-600/80 font-semibold mt-2">
                                    <span class="material-symbols-outlined text-[13px] align-middle mr-0.5">schedule</span>
                                    <?php
                                        echo htmlspecialchars(
                                            (!empty($court['maintenance_start']) ? date('M d, Y H:i', strtotime($court['maintenance_start'])) : 'Now')
                                            . ' – ' .
                                            (!empty($court['maintenance_end']) ? date('M d, Y H:i', strtotime($court['maintenance_end'])) : 'Open')
                                        );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Bookings List -->
                        <div class="border border-outline-variant/20 rounded-xl overflow-hidden">
                            <div class="px-3.5 py-2.5 bg-surface-container-low/50 flex items-center justify-between border-b border-outline-variant/10">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-on-surface-variant/60 text-[16px]">event_note</span>
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-on-surface-variant">Today's Reservations</p>
                                </div>
                                <span class="text-[11px] font-bold <?php echo $booking_count > 0 ? 'text-[primary] bg-[primary]/5' : 'text-on-surface-variant/50 bg-surface-container'; ?> px-2 py-0.5 rounded-md"><?php echo $booking_count; ?> <?php echo $booking_count === 1 ? 'slot' : 'slots'; ?></span>
                            </div>
                            <div class="max-h-[160px] overflow-y-auto custom-scrollbar">
                                <?php if(empty($current_bookings)): ?>
                                    <div class="p-5 text-center">
                                        <span class="material-symbols-outlined text-on-surface-variant/20 text-[32px] block mb-1">event_busy</span>
                                        <p class="text-xs text-on-surface-variant/50 italic">No bookings for this date</p>
                                    </div>
                                <?php else: ?>
                                    <div class="divide-y divide-outline-variant/10">
                                        <?php foreach($current_bookings as $b): ?>
                                            <div class="booking-item px-3.5 py-2.5 flex items-center justify-between">
                                                <div class="flex items-center gap-2.5 min-w-0">
                                                    <div class="w-8 h-8 rounded-lg bg-[primary]/5 flex items-center justify-center shrink-0">
                                                        <span class="material-symbols-outlined text-[primary] text-[14px]">schedule</span>
                                                    </div>
                                                    <div class="min-w-0">
                                                        <p class="text-xs font-bold text-on-surface"><?php echo htmlspecialchars($b['full_name']); ?></p>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-1.5 shrink-0 ml-2">
                                                    <span class="text-[11px] font-bold text-[primary] bg-[primary]/5 px-2 py-0.5 rounded-md">
                                                        <?php echo date('H:i', strtotime($b['start_time'])); ?>
                                                    </span>
                                                    <span class="text-on-surface-variant/30 text-[10px]">-</span>
                                                    <span class="text-[11px] font-medium text-on-surface-variant">
                                                        <?php echo date('H:i', strtotime($b['end_time'])); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Card Action -->
                <div class="px-5 pb-5 mt-auto space-y-2.5">
                    <div class="flex gap-2">
                        <!-- Eye: preview the court photos -->
                        <button type="button" onclick="viewCourtPhotos(<?php echo (int)$c_id; ?>)" title="View court photos" aria-label="View photos of <?php echo htmlspecialchars($court['name']); ?>"
                                class="w-11 h-[42px] shrink-0 flex items-center justify-center border border-outline-variant/40 rounded-xl bg-white text-on-surface-variant hover:border-[primary]/40 hover:text-[primary] hover:bg-[primary]/5 transition-all active:scale-[0.98]">
                            <span class="material-symbols-outlined text-[20px]">visibility</span>
                        </button>
                        <!-- Edit price + photos -->
                        <button type="button" onclick="manageCourt(<?php echo (int)$c_id; ?>)"
                                class="flex-1 py-2.5 border border-outline-variant/40 rounded-xl text-sm font-bold bg-white text-on-surface-variant hover:border-[primary]/40 hover:text-[primary] hover:bg-[primary]/5 transition-all active:scale-[0.98]">
                            <span class="material-symbols-outlined text-[16px] align-middle mr-1.5">edit</span>
                            Price &amp; Photos
                        </button>
                    </div>
                    <button onclick="<?php echo $btn_action; ?>" class="w-full py-2.5 border rounded-xl text-sm font-bold transition-all active:scale-[0.98] <?php echo $btn_class; ?>">
                        <span class="material-symbols-outlined text-[16px] align-middle mr-1.5"><?php echo $court['status'] == 'Maintenance' ? 'check' : 'build'; ?></span>
                        <?php echo $btn_text; ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Empty State (if no courts) -->
        <?php if (empty($courts)): ?>
            <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-12 text-center">
                <div class="w-16 h-16 rounded-2xl bg-surface-container flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-on-surface-variant/30 text-[40px]">sports_tennis</span>
                </div>
                <h3 class="text-lg font-bold text-on-surface mb-1">No Courts Found</h3>
                <p class="text-sm text-on-surface-variant">There are no courts registered in the system yet.</p>
            </div>
        <?php endif; ?>

    </main>

<script>
    function reloadPage() {
        const icon = document.getElementById('reload-icon');
        icon.classList.add('animate-spin');
        setTimeout(() => {
            window.location.reload();
        }, 600);
    }

    function toLocalInput(d) {
        const pad = n => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function openCourtModal() {
        const m = document.getElementById('courtModal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }

    function closeCourtModal() {
        const m = document.getElementById('courtModal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }

    // TC-AS-013 / 016 / 017: maintenance window + conflict override
    function setMaintenance(courtId, bookingCount) {
        document.getElementById('status_court_id').value = courtId;
        document.getElementById('status_new_status').value = 'Maintenance';
        document.getElementById('courtModalTitle').textContent = 'Set Court to Maintenance';
        document.getElementById('courtModalSub').textContent = 'Court #' + courtId;
        document.getElementById('status_notes').value = '';

        const now = new Date();
        const tomorrow = new Date(now.getTime() + 24 * 3600 * 1000);
        document.getElementById('status_start').value = toLocalInput(now);
        document.getElementById('status_end').value = toLocalInput(tomorrow);

        const oRow = document.getElementById('overrideRow');
        if (bookingCount > 0) {
            oRow.classList.remove('hidden');
            oRow.classList.add('flex');
        } else {
            oRow.classList.add('hidden');
            oRow.classList.remove('flex');
        }
        document.getElementById('status_override').checked = false;

        openCourtModal();
    }

    function setAvailable(courtId) {
        if (confirm("Mark this court as available and end maintenance?")) {
            document.getElementById('status_court_id').value = courtId;
            document.getElementById('status_new_status').value = 'Available';
            document.getElementById('status_notes').value = '';
            document.getElementById('courtStatusForm').submit();
        }
    }

    // Close modal when clicking the dimmed backdrop
    document.getElementById('courtModal').addEventListener('click', function (e) {
        if (e.target === this) closeCourtModal();
    });

    // ---------- Court photos + price ----------
    const courtData = <?php echo json_encode($court_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function peso(n) {
        return '₱' + Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });
    }
    function openOverlay(id) {
        const m = document.getElementById(id);
        m.classList.remove('hidden'); m.classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
    function closeOverlay(id) {
        const m = document.getElementById(id);
        m.classList.add('hidden'); m.classList.remove('flex');
        document.body.style.overflow = '';
    }

    // Eye button: read-only photo viewer
    function viewCourtPhotos(id) {
        const c = courtData[id]; if (!c) return;
        document.getElementById('photoModalTitle').textContent = c.name;
        document.getElementById('photoModalSub').textContent = 'Court #' + id + ' · ' + peso(c.price) + ' / hour';
        const grid = document.getElementById('photoGrid');
        const empty = document.getElementById('photoEmpty');
        grid.innerHTML = '';
        if (c.images.length) {
            empty.classList.add('hidden');
            c.images.forEach((im, i) => {
                const img = document.createElement('img');
                img.src = im.url; img.alt = c.name + ' photo ' + (i + 1); img.loading = 'lazy';
                img.className = 'w-full h-52 object-cover rounded-xl bg-surface-container' + (c.images.length === 1 ? ' sm:col-span-2 sm:h-72' : '');
                img.onerror = () => img.remove();
                grid.appendChild(img);
            });
        } else {
            empty.classList.remove('hidden');
            document.getElementById('photoEmptyBtn').onclick = () => { closeOverlay('photoModal'); manageCourt(id); };
        }
        openOverlay('photoModal');
    }

    // Price & Photos manager
    function manageCourt(id) {
        const c = courtData[id]; if (!c) return;
        document.getElementById('manageTitle').textContent = c.name;
        document.getElementById('manageSub').textContent = 'Court #' + id;
        document.getElementById('price_court_id').value = id;
        document.getElementById('upload_court_id').value = id;
        document.getElementById('price_input').value = Number(c.price).toFixed(2);
        document.getElementById('upload_input').value = '';

        const grid = document.getElementById('manageGrid');
        const empty = document.getElementById('manageEmpty');
        grid.innerHTML = '';
        document.getElementById('manageCount').textContent = c.images.length + ' / <?php echo (int)$max_imgs_court; ?>';
        empty.classList.toggle('hidden', c.images.length > 0);
        c.images.forEach((im, i) => {
            const wrap = document.createElement('div');
            wrap.className = 'relative group rounded-xl overflow-hidden border border-outline-variant/30 bg-surface-container';
            const img = document.createElement('img');
            img.src = im.url; img.alt = 'Photo ' + (i + 1); img.loading = 'lazy';
            img.className = 'w-full h-28 object-cover';
            const del = document.createElement('button');
            del.type = 'button'; del.title = 'Delete photo'; del.setAttribute('aria-label', 'Delete photo ' + (i + 1));
            del.className = 'absolute top-1.5 right-1.5 w-8 h-8 rounded-lg bg-white/95 text-red-600 shadow flex items-center justify-center hover:bg-red-600 hover:text-white transition-colors';
            del.innerHTML = '<span class="material-symbols-outlined text-[18px]">delete</span>';
            del.onclick = () => {
                if (!confirm('Delete this photo? It will no longer show on the booking page.')) return;
                document.getElementById('del_court_id').value = id;
                document.getElementById('del_image_id').value = im.id;
                document.getElementById('imgDeleteForm').submit();
            };
            wrap.appendChild(img); wrap.appendChild(del);
            grid.appendChild(wrap);
        });
        openOverlay('manageModal');
    }

    ['photoModal', 'manageModal'].forEach(mid => {
        document.getElementById(mid).addEventListener('click', function (e) {
            if (e.target === this) closeOverlay(mid);
        });
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeOverlay('photoModal'); closeOverlay('manageModal'); closeCourtModal(); }
    });

    <?php if ($reopen_court > 0 && isset($court_js[$reopen_court])): ?>
    // Back to the manage popup after saving a price / uploading / deleting.
    manageCourt(<?php echo (int)$reopen_court; ?>);
    <?php endif; ?>
</script>
</body>
</html>