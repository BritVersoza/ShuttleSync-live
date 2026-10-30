<?php
// Venue timezone. Set here as well so this page is correct even if an older
// session_boot.php is still cached/deployed.
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/includes/session_boot.php';
$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_name    = $is_logged_in ? ($_SESSION['full_name'] ?? '') : '';
$is_admin     = $is_logged_in && in_array($_SESSION['role'] ?? '', ['Admin', 'Head Manager'], true);
require_once __DIR__ . '/includes/database_connect.php';
require_once __DIR__ . '/includes/csrf_helper.php';

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;
$error_message = '';

// Some installations predate the created_at column; detect it once.
$has_created_col = false;
try { $has_created_col = (bool)$pdo->query("SHOW COLUMNS FROM Bookings LIKE 'created_at'")->fetch(); } catch (PDOException $e) {}

// Per-court hourly rate (set by admins in court_status.php). Falls back to 300 if not migrated yet.
$DEFAULT_RATE = 300.00;
$has_price_col = false;
try { $has_price_col = (bool)$pdo->query("SHOW COLUMNS FROM Courts LIKE 'price_per_hour'")->fetch(); } catch (PDOException $e) {}

$view_date = isset($_GET['date']) ? date('Y-m-d', strtotime($_GET['date'])) : date('Y-m-d');
$is_today = ($view_date === date('Y-m-d'));
$prev_date = date('Y-m-d', strtotime('-1 day', strtotime($view_date)));
$next_date = date('Y-m-d', strtotime('+1 day', strtotime($view_date)));

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'book_court') {
    verify_csrf_token();
    if (!$is_logged_in) { header("Location: /authentication/login"); exit; }
    try {
        // Prevent booking for past dates
        $today_date = date('Y-m-d');
        if ($view_date < $today_date) {
            throw new Exception("Cannot book a court for a past date.");
        }
        $court_id = (int)($_POST['court_id'] ?? 0);
        $start_time = $_POST['start_time'] ?? '';
        // Validate the submitted slot format (HH:MM:SS) before using it.
        $st = DateTime::createFromFormat('H:i:s', $start_time);
        if ($court_id <= 0 || !$st || $st->format('H:i:s') !== $start_time) {
            throw new Exception("Invalid booking request. Please choose a valid court and time.");
        }
        // Prevent booking a time slot that already started today
        if ($view_date === $today_date && strtotime($start_time) <= time()) {
            throw new Exception("Cannot book a time slot that has already passed.");
        }
        $player_count = (int)$_POST['player_count'];
        if ($player_count < 1 || $player_count > 20) throw new Exception("Player count must be between 1 and 20.");
        $duration_hours = (int)$_POST['duration'];
        $end_time = date('H:i:s', strtotime("+{$duration_hours} hours", strtotime($start_time)));

        if ($duration_hours < 1 || $duration_hours > 6) throw new Exception("Invalid booking duration.");
        if ($start_time < '09:00:00' || $start_time >= '22:00:00') throw new Exception("Bookings are available from 9:00 AM to 10:00 PM.");
        if ($end_time > '22:00:00') throw new Exception("Your booking must end by 10:00 PM.");

        // Ensure the locking table exists (self-healing for deployments that missed the migration).
        // Done OUTSIDE the transaction because DDL (CREATE TABLE) causes an implicit commit.
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS booking_locks (court_id INT NOT NULL, booking_date DATE NOT NULL, PRIMARY KEY (court_id, booking_date)) ENGINE=InnoDB"); } catch (PDOException $e) {}

        $pdo->beginTransaction();

        // Serialize bookings per court/date (first come, first served).
        $lock = $pdo->prepare("INSERT INTO booking_locks (court_id, booking_date) VALUES (?, ?) ON DUPLICATE KEY UPDATE court_id = court_id");
        $lock->execute([$court_id, $view_date]);
        $lk = $pdo->prepare("SELECT court_id FROM booking_locks WHERE court_id = ? AND booking_date = ? FOR UPDATE");
        $lk->execute([$court_id, $view_date]);

        // Release expired holds (15 minutes).
        $pdo->prepare("UPDATE Bookings SET status = 'Expired' WHERE court_id = ? AND booking_date = ? AND status IN ('In Cart','Pending Payment') AND created_at < (NOW() - INTERVAL 15 MINUTE)")
            ->execute([$court_id, $view_date]);

        $cs = $pdo->prepare("SELECT status" . ($has_price_col ? ", price_per_hour" : "") . " FROM Courts WHERE court_id = ?");
        $cs->execute([$court_id]);
        $court_row = $cs->fetch(PDO::FETCH_ASSOC);
        if (!$court_row) throw new Exception("Court not found.");
        if (($court_row['status'] ?? '') === 'Maintenance') throw new Exception("Court is under maintenance.");
        // Price is always taken from the database, never from the browser.
        $rate = ($has_price_col && (float)($court_row['price_per_hour'] ?? 0) > 0) ? (float)$court_row['price_per_hour'] : $DEFAULT_RATE;
        $total_price = $rate * $duration_hours;

        // Overlap check against active bookings.
        $chk = $pdo->prepare("SELECT booking_id FROM Bookings WHERE court_id = ? AND booking_date = ? AND status IN ('Confirmed','Pending Payment','In Cart') AND start_time < ? AND end_time > ?");
        $chk->execute([$court_id, $view_date, $end_time, $start_time]);
        if ($chk->rowCount() > 0) {
            throw new Exception("Sorry, this slot was just booked by someone else. Please choose another time.");
        }

        $ref = '#B-' . strtoupper(substr(uniqid(), -5));
        if ($has_created_col) {
            $pdo->prepare("INSERT INTO Bookings (booking_reference, user_id, court_id, booking_date, start_time, end_time, player_count, total_price, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'In Cart', NOW())")->execute([$ref, $user_id, $court_id, $view_date, $start_time, $end_time, $player_count, $total_price]);
        } else {
            $pdo->prepare("INSERT INTO Bookings (booking_reference, user_id, court_id, booking_date, start_time, end_time, player_count, total_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'In Cart')")->execute([$ref, $user_id, $court_id, $view_date, $start_time, $end_time, $player_count, $total_price]);
        }
        $new_id = $pdo->lastInsertId();
        $pdo->commit();
        // Hand the booking straight to the bank-transfer checkout page.
        $_SESSION['pending_checkout'] = [
            'type'        => 'checkout_booking',
            'booking_ids' => [(int)$new_id],
        ];
        header("Location: /checkout");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error_message = $e->getMessage();
    }
}

$courts = $pdo->query("SELECT * FROM Courts ORDER BY court_id ASC")->fetchAll();
$existing_bookings_by_court = [];
try {
    // Confirmed bookings and unexpired holds both block a slot.
    $active_sql = "b.status = 'Confirmed'";
    if ($has_created_col) {
        $active_sql .= " OR (b.status IN ('In Cart','Pending Payment') AND b.created_at >= (NOW() - INTERVAL 15 MINUTE))";
    } else {
        $active_sql .= " OR b.status IN ('In Cart','Pending Payment')";
    }
    $bs = $pdo->prepare("SELECT b.court_id, b.start_time, b.end_time, b.status FROM Bookings b WHERE b.booking_date = ? AND ({$active_sql}) ORDER BY b.start_time ASC");
    $bs->execute([$view_date]);
    foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $b) $existing_bookings_by_court[$b['court_id']][] = $b;
} catch (PDOException $e) {}

// Photos uploaded by admins (court_status.php) for the "view court" popup.
$court_images = [];
try {
    foreach ($pdo->query("SELECT court_id, image_path FROM court_images ORDER BY court_id ASC, sort_order ASC, image_id ASC")->fetchAll(PDO::FETCH_ASSOC) as $im) {
        $court_images[(int)$im['court_id']][] = '/img/courts/' . basename($im['image_path']);
    }
} catch (PDOException $e) {}

$hours = range(9, 21);
$current_hour=(int)date('H'); $current_minute=(int)date('i');
$grid = [];
foreach ($courts as $court) {
    $cid = $court['court_id'];
    $c_price = ($has_price_col && (float)($court['price_per_hour'] ?? 0) > 0) ? (float)$court['price_per_hour'] : $DEFAULT_RATE;
    $grid[$cid] = ['id'=>$cid, 'name'=>$court['name'], 'status'=>$court['status']??'Available', 'price'=>$c_price, 'open_count'=>0, 'total_count'=>count($hours), 'slots'=>[]];
    foreach ($hours as $h) {
        $ts = sprintf('%02d:00:00',$h);
        $grid[$cid]['slots'][$ts] = ['s'=>'open','md'=>22-$h];
    }
    if (isset($existing_bookings_by_court[$cid])) {
        foreach ($existing_bookings_by_court[$cid] as $b) {
            $sh=(int)date('H',strtotime($b['start_time'])); $eh=(int)date('H',strtotime($b['end_time']));
            $state = ($b['status'] === 'Confirmed') ? 'booked' : 'held';
            for($h=$sh;$h<$eh;$h++){ $ts=sprintf('%02d:00:00',$h); if(isset($grid[$cid]['slots'][$ts])) $grid[$cid]['slots'][$ts]=['s'=>$state,'md'=>0,'bn'=>'']; }
        }
    }
    foreach($hours as $h){
        $ts=sprintf('%02d:00:00',$h);
        // Slots that already started earlier today can no longer be booked
        if($is_today && ($h < $current_hour || ($h === $current_hour && $current_minute > 0)) && $grid[$cid]['slots'][$ts]['s']==='open'){
            $grid[$cid]['slots'][$ts]['s']='past';
            $grid[$cid]['slots'][$ts]['md']=0;
            continue;
        }
        if($grid[$cid]['slots'][$ts]['s']==='open'){
            $mx=0;
            for($nh=$h;$nh<22;$nh++){ $ns=sprintf('%02d:00:00',$nh); if(in_array($grid[$cid]['slots'][$ns]['s'], ['booked','held'], true)) break; $mx++; }
            $grid[$cid]['slots'][$ts]['md']=$mx;
            $grid[$cid]['open_count']++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- courtbooking build: 2026-10-27 timeline-v3 (Asia/Manila, merged-slot timeline) -->
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#080f17" />
    <title>Court Timeline | HI-POWER Badminton Court</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <style>
      /* All page styles are prefixed with "cb-" so they can't clash with nav_bar / footer / Tailwind. */
      .cb-body {
        --cb-orange: #ff5722;
        --cb-orange-dark: #ed4817;
        --cb-navy: #14264a;
        --cb-ink: #0e1b3d;
        --cb-muted: #64748b;
        --cb-line: #e2e8f0;
        --cb-col: 250px;
        --cb-pad: 12px;
        --cb-row-h: 76px;
        --cb-head-h: 56px;
        --cb-now: #ff4d2d;
        --cb-ok-bg: #d4f1e2;   --cb-ok-ink: #0b7a52;
        --cb-bk-bg: #f9d1d1;   --cb-bk-ink: #b32d2d;
        --cb-hold-bg: #fdefb9; --cb-hold-ink: #8a5a06;
        --cb-cell: #f1f4f8;

        margin: 0;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        font-family: "Manrope", sans-serif;
        color: var(--cb-navy);
        background: #f4f7fb;
        line-height: 1.55;
      }
      .cb-main { flex: 1; padding-bottom: 40px; }
      .cb-main, .cb-main *, .cb-panel, .cb-panel * { box-sizing: border-box; }
      .cb-main a { color: inherit; text-decoration: none; }
      .cb-main button, .cb-main input, .cb-panel button, .cb-panel input, .cb-panel select { font: inherit; }
      .cb-main button, .cb-panel button { cursor: pointer; }
      .cb-main :focus-visible, .cb-panel :focus-visible { outline: 2px solid var(--cb-orange); outline-offset: 2px; }
      .cb-wrap { width: min(1240px, calc(100% - 48px)); margin-inline: auto; }

      /* ---------- Notices ---------- */
      .cb-notice { display: flex; align-items: center; gap: 12px; margin-top: 24px; padding: 14px 16px; border-radius: 14px; font-size: 13px; font-weight: 700; }
      .cb-notice a { text-decoration: underline; font-weight: 800; }
      .cb-notice.warn { background: #fffbeb; border: 1px solid #fde68a; color: #b45309; }
      .cb-notice.err  { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }

      /* ---------- Page head ---------- */
      .cb-head-row { padding: 50px 0 20px; display: flex; align-items: flex-end; justify-content: space-between; gap: 28px; flex-wrap: wrap; }
      .cb-eyebrow { color: var(--cb-orange); font-size: 11px; font-weight: 800; letter-spacing: 2.6px; text-transform: uppercase; }
      .cb-title { margin: 6px 0 8px; font-size: clamp(34px, 5vw, 44px); line-height: 1.05; letter-spacing: -1.4px; font-weight: 800; color: var(--cb-ink); }
      .cb-sub { margin: 0; max-width: 470px; color: var(--cb-muted); font-size: 14px; line-height: 1.6; }

      .cb-date-controls { display: flex; gap: 16px; flex-wrap: wrap; }
      .cb-date-nav, .cb-date-pick { background: #fff; border-radius: 14px; box-shadow: 0 10px 28px rgba(15,23,42,.07); border: 1px solid #edf1f6; }
      .cb-date-nav { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; min-width: 260px; }
      .cb-nav-btn { border: 0; background: transparent; color: #3b4a66; width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; font-size: 13px; transition: background .15s; }
      .cb-nav-btn:hover { background: #f0f3f8; }
      .cb-date-center { text-align: center; line-height: 1.25; }
      .cb-date-center .cb-month { display: block; font-size: 10px; letter-spacing: 1.6px; text-transform: uppercase; font-weight: 800; color: var(--cb-orange); }
      .cb-date-center strong { display: block; font-size: 17px; font-weight: 800; color: var(--cb-ink); letter-spacing: -.3px; }
      .cb-date-center small { display: block; font-size: 11px; color: #7b8aa3; }
      .cb-date-pick { padding: 10px; display: flex; align-items: center; }
      .cb-date-pick label { display: flex; align-items: center; gap: 10px; border: 1px solid #dfe6ef; border-radius: 10px; padding: 0 14px; min-height: 40px; min-width: 150px; font-size: 13px; font-weight: 600; color: #1d2b4a; background: #fff; }
      .cb-date-pick i { color: #3a4b6d; }
      .cb-date-pick input { border: 0; outline: none; background: transparent; color: inherit; font-weight: 600; width: 100%; min-width: 0; padding: 0; }
      .cb-date-pick label:focus-within { border-color: var(--cb-orange); box-shadow: 0 0 0 3px rgba(255,87,34,.12); }

      /* ---------- Legend ---------- */
      .cb-legend { display: flex; flex-wrap: wrap; gap: 8px 26px; align-items: center; margin: 4px 0 20px; font-size: 12px; font-weight: 600; color: #2a3a58; }
      .cb-legend span { display: inline-flex; align-items: center; gap: 8px; }
      .cb-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
      .cb-dot.ok { background: #3dd68c; box-shadow: 0 0 0 3px #d9f6e8; }
      .cb-dot.bk { background: #f58a8a; box-shadow: 0 0 0 3px #fbe1e1; }
      .cb-dot.hold { background: #f7d154; box-shadow: 0 0 0 3px #fcf0c4; }
      .cb-dot.maint { background: #e3e8ef; border-radius: 4px; }
      .cb-dash { width: 18px; height: 3px; border-radius: 2px; background: var(--cb-now); display: inline-block; }

      /* ---------- Timeline ---------- */
      .cb-card { background: #fff; border-radius: 18px; border: 1px solid #edf1f6; box-shadow: 0 12px 34px rgba(15,23,42,.06); overflow: hidden; }
      .cb-scroller { overflow-x: auto; overscroll-behavior-x: contain; }
      .cb-tl { position: relative; min-width: 1240px; }
      .cb-head, .cb-row { display: grid; grid-template-columns: var(--cb-col) 1fr; }
      .cb-head { height: var(--cb-head-h); border-bottom: 1px solid #eef2f6; }
      .cb-row { height: var(--cb-row-h); border-top: 1px solid #f1f4f8; }
      .cb-head + .cb-row { border-top: 0; }
      .cb-col { position: sticky; left: 0; z-index: 6; background: #fff; display: flex; align-items: center; gap: 12px; padding: 0 16px 0 14px; overflow: hidden; }
      .cb-col-label { font-size: 12px; font-weight: 800; letter-spacing: 1.6px; text-transform: uppercase; color: #6c7b95; }

      .cb-badge { flex: none; width: 42px; height: 42px; border-radius: 11px; color: #fff; display: grid; place-items: center; font-size: 20px; font-weight: 800; box-shadow: 0 6px 14px rgba(20,38,74,.16); }
      .cb-court { min-width: 0; flex: 1; line-height: 1.25; }
      .cb-court strong { display: block; font-size: 13px; font-weight: 700; color: #111c3a; }
      .cb-court small { display: flex; flex-wrap: wrap; align-items: center; gap: 0 6px; margin-top: 5px; font-size: 11px; color: #7b8aa3; font-weight: 500; }

      .cb-track { position: relative; margin: 0 var(--cb-pad); height: 100%; }
      .cb-row .cb-track::before {
        content: ""; position: absolute; inset: 15px 0; border-radius: 8px; pointer-events: none;
        background-image: repeating-linear-gradient(90deg, var(--cb-cell) 0, var(--cb-cell) calc(100% / var(--n) - 3px), transparent calc(100% / var(--n) - 3px), transparent calc(100% / var(--n)));
      }
      .cb-hour { position: absolute; top: 50%; transform: translate(-50%, -50%); font-size: 11px; font-weight: 600; color: #8a98ae; white-space: nowrap; }

      .cb-slot {
        position: absolute; top: 15px; bottom: 15px; border-radius: 8px; overflow: hidden;
        font-size: 10.5px; line-height: 1.3; white-space: nowrap;
      }
      .cb-slot .cb-label { position: absolute; inset: 0; padding: 0 11px; display: flex; flex-direction: column; justify-content: center; gap: 1px; pointer-events: none; text-overflow: ellipsis; overflow: hidden; }
      .cb-slot.one .cb-label { padding: 0 7px; }
      .cb-slot b { font-weight: 700; font-size: 10.5px; overflow: hidden; text-overflow: ellipsis; }
      .cb-slot span { font-weight: 500; opacity: .8; overflow: hidden; text-overflow: ellipsis; }
      .cb-slot.ok { background: var(--cb-ok-bg); color: var(--cb-ok-ink); box-shadow: inset 0 0 0 1px rgba(8,127,91,.12); }
      .cb-slot.bk { background: var(--cb-bk-bg); color: var(--cb-bk-ink); cursor: not-allowed; }
      .cb-slot.hold { background: var(--cb-hold-bg); color: var(--cb-hold-ink); cursor: not-allowed; }

      /* Each available block is split into 1-hour hit areas so a start hour can be picked. */
      .cb-segs { position: absolute; inset: 0; display: flex; z-index: 2; }
      .cb-seg { flex: 1; min-width: 0; border: 0; padding: 0; background: transparent; transition: background .12s ease; }
      .cb-seg + .cb-seg { box-shadow: inset 1px 0 0 rgba(8,127,91,.18); }
      .cb-seg:hover { background: rgba(8,127,91,.16); }
      .cb-seg:focus-visible { outline-offset: -2px; }
      .cb-seg { position: relative; }
      .cb-seg::after { content: ""; position: absolute; inset: 0; display: grid; place-items: center; font-size: 11px; font-weight: 800; color: var(--cb-ok-ink); opacity: 0; transition: opacity .12s ease; pointer-events: none; }
      .cb-seg:hover::after { content: "Book"; opacity: 1; }
      .cb-seg.in-range::after { content: "\2713"; opacity: 1; color: var(--cb-orange-dark); }
      .cb-slot.ok:hover .cb-label { opacity: .25; }
      .cb-seg.in-range, .cb-seg.in-range:hover { background: rgba(255,87,34,.22); box-shadow: inset 0 -3px 0 var(--cb-orange); }

      .cb-maint { position: absolute; inset: 11px 0; border-radius: 10px; display: flex; align-items: center; justify-content: center; gap: 12px; color: #7b8aa3; background: repeating-linear-gradient(135deg, #f1f4f8 0 8px, #e8edf3 8px 16px); }
      .cb-maint i { font-size: 17px; }
      .cb-maint b { display: block; font-size: 11px; letter-spacing: .6px; text-transform: uppercase; font-weight: 700; }
      .cb-maint span { display: block; font-size: 11px; font-weight: 500; }

      .cb-now-layer { position: absolute; z-index: 4; top: 0; bottom: 0; left: calc(var(--cb-col) + var(--cb-pad)); right: var(--cb-pad); pointer-events: none; }
      .cb-shade { position: absolute; top: var(--cb-head-h); bottom: 0; left: 0; width: 0; background: rgba(244,247,251,.62); }
      .cb-now { position: absolute; top: 0; bottom: 0; width: 2px; margin-left: -1px; background: var(--cb-now); box-shadow: 0 0 0 3px rgba(255,77,45,.1); }
      .cb-now-tag { position: absolute; top: 12px; left: 50%; transform: translateX(-50%); background: var(--cb-now); color: #fff; font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 7px; white-space: nowrap; box-shadow: 0 4px 10px rgba(255,77,45,.35); }

      .cb-hint { display: none; padding: 8px 14px; font-size: 11px; color: #7b8aa3; border-top: 1px solid #eef2f6; background: #fafbfd; }

      /* ---------- Booking panel ---------- */
      .cb-panel { position: fixed; left: 50%; bottom: 24px; z-index: 70; width: min(440px, calc(100% - 24px)); transform: translate(-50%, 24px); opacity: 0; pointer-events: none; transition: transform .25s ease, opacity .25s ease; color: var(--cb-navy); font-family: "Manrope", sans-serif; }
      .cb-panel.show { transform: translate(-50%, 0); opacity: 1; pointer-events: auto; }
      .cb-panel-inner { background: #fff; border: 1px solid #edf1f6; border-radius: 18px; box-shadow: 0 24px 60px rgba(8,15,23,.28); padding: 16px; }
      .cb-panel-top { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
      .cb-panel-icon { flex: none; width: 38px; height: 38px; border-radius: 11px; background: linear-gradient(135deg, #ff6b3d, #f0382f); color: #fff; display: grid; place-items: center; font-size: 16px; }
      .cb-panel-top > div { flex: 1; min-width: 0; line-height: 1.3; }
      .cb-panel-top strong { display: block; font-size: 14px; font-weight: 800; color: var(--cb-ink); }
      .cb-panel-top small { display: block; font-size: 11px; color: var(--cb-muted); }
      .cb-close { flex: none; width: 30px; height: 30px; border: 0; border-radius: 9px; background: transparent; color: #7b8aa3; display: grid; place-items: center; }
      .cb-close:hover { background: #f0f3f8; }
      .cb-fields { display: flex; gap: 8px; margin-bottom: 14px; }
      .cb-fields select, .cb-fields input { background: #f7f9fc; border: 1px solid #dfe6ef; border-radius: 10px; padding: 9px 12px; font-size: 12px; font-weight: 700; color: var(--cb-ink); outline: none; }
      .cb-fields select { flex: 1; min-width: 0; }
      .cb-fields input { width: 72px; text-align: center; }
      .cb-fields select:focus, .cb-fields input:focus { border-color: var(--cb-orange); box-shadow: 0 0 0 3px rgba(255,87,34,.12); }
      .cb-panel-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
      .cb-total { font-size: 22px; font-weight: 800; letter-spacing: -.5px; color: var(--cb-orange); }
      .cb-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; border-radius: 11px; padding: 11px 20px; font-size: 12px; font-weight: 800; color: #fff; background: var(--cb-orange); box-shadow: 0 6px 16px rgba(255,87,34,.22); transition: background .2s, transform .2s; text-decoration: none; }
      .cb-btn:hover { background: var(--cb-orange-dark); transform: translateY(-1px); }
      .cb-btn:disabled { background: #cbd5e1; color: #64748b; box-shadow: none; cursor: not-allowed; transform: none; }

      /* ---------- Court view button + modal ---------- */
      .cb-rate { color: var(--cb-orange); font-weight: 800; }
      .cb-rate:not(.cb-rate-only)::before { content: "·"; color: #b4bfd0; margin-right: 6px; font-weight: 700; }
      .cb-view-btn { flex: none; width: 34px; height: 34px; padding: 0; border: 0; border-radius: 10px; background: #f0f3f8; color: #3b4a66; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; transition: background .15s, color .15s; cursor: pointer; }
      .cb-view-btn:hover { background: var(--cb-orange); color: #fff; }
      .cb-modal { position: fixed; inset: 0; z-index: 100; display: none; align-items: center; justify-content: center; padding: 20px; }
      .cb-modal.show { display: flex; }
      .cb-modal-backdrop { position: absolute; inset: 0; background: rgba(8,15,23,.7); backdrop-filter: blur(4px); }
      .cb-modal-card { position: relative; z-index: 1; background: #fff; border-radius: 18px; width: min(720px, 100%); max-height: 90vh; overflow: auto; padding: 20px; box-shadow: 0 24px 60px rgba(8,15,23,.35); }
      .cb-modal-close { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border: 0; border-radius: 9px; background: #f0f3f8; color: #3b4a66; display: grid; place-items: center; font-size: 14px; cursor: pointer; }
      .cb-modal-close:hover { background: #fee2e2; color: #dc2626; }
      .cb-modal-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; padding-right: 44px; }
      .cb-modal-head strong { display: block; font-size: 16px; font-weight: 800; color: var(--cb-ink); }
      .cb-modal-head small { display: block; font-size: 12px; color: var(--cb-muted); font-weight: 600; margin-top: 2px; }
      .cb-modal-gallery { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
      .cb-modal-gallery img { width: 100%; height: 220px; object-fit: cover; border-radius: 12px; background: #f1f4f8; }
      .cb-modal-gallery img:only-child { grid-column: 1 / -1; height: 300px; }
      .cb-modal-gallery:empty { display: none; }
      .cb-modal-empty { margin: 0 0 14px; padding: 28px 16px; text-align: center; border: 1px dashed #dfe6ef; border-radius: 12px; background: #f7f9fc; color: var(--cb-muted); font-size: 13px; font-weight: 600; }
      @media (max-width: 560px) { .cb-modal-details { grid-template-columns: 1fr 1fr; } .cb-modal-gallery { grid-template-columns: 1fr; } .cb-modal-gallery img, .cb-modal-gallery img:only-child { height: 200px; } }
      .cb-modal-details { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
      .cb-modal-details > div { background: #f7f9fc; border: 1px solid #eef2f6; border-radius: 12px; padding: 12px 14px; }
      .cb-modal-label { display: block; font-size: 10px; letter-spacing: 1.4px; text-transform: uppercase; font-weight: 800; color: var(--cb-muted); margin-bottom: 4px; }
      .cb-modal-details strong { font-size: 14px; font-weight: 800; color: var(--cb-ink); }
      .cb-modal-gallery img { cursor: zoom-in; transition: filter .15s ease; }
      .cb-modal-gallery img:hover { filter: brightness(.94); }
      .cb-zoom-row { display: none; align-items: center; gap: 12px; flex-wrap: wrap; margin: -4px 0 14px; }
      .cb-zoom-row span { font-size: 11px; font-weight: 600; color: var(--cb-muted); }
      .cb-zoom-btn { display: inline-flex; align-items: center; gap: 8px; border: 0; border-radius: 10px; padding: 9px 14px; background: var(--cb-orange); color: #fff; font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; box-shadow: 0 6px 16px rgba(255,87,34,.22); transition: background .2s; }
      .cb-zoom-btn:hover { background: var(--cb-orange-dark); }

      /* ---------- Photo zoom viewer ---------- */
      .cb-lightbox { position: fixed; inset: 0; z-index: 120; display: none; background: rgba(5,10,18,.95); color: #fff; font-family: "Manrope", sans-serif; }
      .cb-lightbox.show { display: block; }
      .cb-lb-stage { position: absolute; inset: 0; display: grid; place-items: center; overflow: hidden; touch-action: none; user-select: none; -webkit-user-select: none; cursor: zoom-in; }
      .cb-lb-stage.zoomed { cursor: grab; }
      .cb-lb-stage.zoomed:active { cursor: grabbing; }
      .cb-lb-stage img { max-width: 92vw; max-height: 80vh; object-fit: contain; border-radius: 8px; transform-origin: center; will-change: transform; pointer-events: none; -webkit-user-drag: none; user-select: none; }
      .cb-lb-bar { position: absolute; z-index: 2; top: calc(14px + env(safe-area-inset-top, 0px)); left: 50%; transform: translateX(-50%); display: flex; align-items: center; gap: 4px; padding: 5px; border-radius: 14px; background: rgba(255,255,255,.14); backdrop-filter: blur(8px); }
      .cb-lb-btn { width: 40px; height: 40px; border: 0; border-radius: 10px; background: transparent; color: #fff; display: grid; place-items: center; font: inherit; font-size: 15px; cursor: pointer; transition: background .15s; }
      .cb-lb-btn:hover { background: rgba(255,255,255,.2); }
      .cb-lb-btn:disabled { opacity: .35; cursor: default; background: transparent; }
      .cb-lb-level { min-width: 54px; text-align: center; font-size: 12px; font-weight: 800; }
      .cb-lb-close { position: absolute; z-index: 3; top: calc(14px + env(safe-area-inset-top, 0px)); right: 14px; width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,.14); backdrop-filter: blur(8px); }
      .cb-lb-nav { position: absolute; z-index: 2; top: 50%; transform: translateY(-50%); width: 46px; height: 46px; border-radius: 50%; background: rgba(255,255,255,.14); backdrop-filter: blur(8px); }
      .cb-lb-nav.prev { left: 14px; } .cb-lb-nav.next { right: 14px; }
      .cb-lb-count { position: absolute; z-index: 2; bottom: calc(18px + env(safe-area-inset-bottom, 0px)); left: 50%; transform: translateX(-50%); padding: 5px 12px; border-radius: 999px; background: rgba(255,255,255,.14); font-size: 12px; font-weight: 700; }
      .cb-lb-hint { position: absolute; z-index: 1; bottom: calc(54px + env(safe-area-inset-bottom, 0px)); left: 50%; transform: translateX(-50%); font-size: 11px; font-weight: 600; color: rgba(255,255,255,.6); white-space: nowrap; pointer-events: none; }
      @media (max-width: 560px) { .cb-lb-nav { width: 40px; height: 40px; } .cb-lb-hint { display: none; } }

      @media (max-width: 900px) {
        .cb-body { --cb-col: 224px; }
        .cb-hint { display: block; }
      }
      @media (max-width: 767px) {
        .cb-panel { bottom: 84px; } /* clear the mobile bottom nav */
      }
      @media (max-width: 680px) {
        .cb-wrap { width: min(100% - 30px, 560px); }
        .cb-head-row { padding: 34px 0 14px; }
        .cb-date-controls { width: 100%; }
        .cb-date-nav, .cb-date-pick { flex: 1 1 100%; }
        .cb-legend { gap: 8px 18px; }
      }
      @media (prefers-reduced-motion: reduce) {
        .cb-main *, .cb-panel { transition: none !important; }
      }
    </style>
</head>
<body class="cb-body">

<script>
const gridData=<?php echo json_encode($grid); ?>;
const hoursList=<?php echo json_encode($hours); ?>;
const isToday=<?php echo $is_today?'true':'false'; ?>;
const curH=<?php echo $current_hour; ?>;
const curM=<?php echo $current_minute; ?>;
const courtImages=<?php echo json_encode($court_images, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
</script>

<?php include __DIR__ . '/includes/nav_bar.php'; ?>

<?php
// ---- View helpers (display only) ----
$H0 = $hours[0];                  // first hour shown (9)
$N  = count($hours);              // number of 1-hour columns (13 -> 9 AM to 10 PM)
$H1 = $H0 + $N;                   // end of the timeline (22)
$palette = [
    '#ff6b3d,#f0382f', '#4f6df0,#2f4fd6', '#ff8a2b,#f56a0f', '#9b8cf5,#7b6be0',
    '#1fcab6,#0aa597', '#8b6bf8,#6a4be8', '#ff6f8e,#f0476e', '#5a4de0,#3d31c2',
];
$fmt_h = function ($h) { return (($h % 12) ?: 12) . ':00 ' . ($h >= 12 ? 'PM' : 'AM'); };
$fmt_short = function ($a, $b) {
    $ma = $a >= 12 ? 'PM' : 'AM'; $mb = $b >= 12 ? 'PM' : 'AM';
    $ha = ($a % 12) ?: 12;        $hb = ($b % 12) ?: 12;
    return $ma === $mb ? "{$ha}–{$hb} {$mb}" : "{$ha} {$ma}–{$hb} {$mb}";
};
$fmt_price = function ($p) { return '₱' . number_format($p, ((float)$p == floor($p)) ? 0 : 2); };
$pct = function ($h) use ($H0, $N) { return number_format((($h - $H0) / $N) * 100, 4, '.', ''); };
?>

<main class="cb-main">
  <div class="cb-wrap">

    <?php if ($view_date < date('Y-m-d')): ?>
        <div class="cb-notice warn">
            <i class="fa-regular fa-calendar-xmark"></i>
            <p style="margin:0">You are viewing a past date (<?php echo date('M j, Y', strtotime($view_date)); ?>). Booking is disabled for past dates. <a href="courtbooking">Go to today</a></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="cb-notice err" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <p style="margin:0"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="cb-head-row">
        <div>
            <div class="cb-eyebrow">Schedule</div>
            <h1 class="cb-title">Court Timeline</h1>
            <p class="cb-sub">Tap any available slot to book. Each court's hourly rate is shown beside its name. Times shown in Asia/Manila (GMT+8).</p>
        </div>
        <div class="cb-date-controls">
            <div class="cb-date-nav">
                <a class="cb-nav-btn" href="?date=<?php echo $prev_date; ?>" aria-label="Previous day"><i class="fa-solid fa-chevron-left"></i></a>
                <div class="cb-date-center">
                    <span class="cb-month"><?php echo date('M Y', strtotime($view_date)); ?></span>
                    <strong><?php echo date('D, M j', strtotime($view_date)); ?></strong>
                    <small id="nowClock">Now: <?php echo date('g:i A'); ?> (PHT)</small>
                </div>
                <a class="cb-nav-btn" href="?date=<?php echo $next_date; ?>" aria-label="Next day"><i class="fa-solid fa-chevron-right"></i></a>
            </div>
            <div class="cb-date-pick">
                <label>
                    <i class="fa-regular fa-calendar"></i>
                    <input type="date" id="courtDatePicker" aria-label="Choose a date"
                           value="<?php echo $view_date; ?>" min="<?php echo date('Y-m-d'); ?>" onchange="goDate(this)">
                </label>
            </div>
        </div>
    </div>

    <!-- Legend -->
    <div class="cb-legend" aria-label="Legend">
        <span><i class="cb-dot ok"></i>Available</span>
        <span><i class="cb-dot bk"></i>Booked</span>
        <span><i class="cb-dot hold"></i>On hold</span>
        <span><i class="cb-dot maint"></i>Maintenance</span>
        <?php if ($is_today): ?><span><i class="cb-dash"></i>Now</span><?php endif; ?>
    </div>

    <!-- Timeline -->
    <div class="cb-card">
      <div class="cb-scroller" id="tlScroll">
        <div class="cb-tl" id="tlGrid" style="--n:<?php echo $N; ?>">

            <div class="cb-head">
                <div class="cb-col cb-col-label">Court</div>
                <div class="cb-track">
                    <?php for ($h = $H0; $h < $H1; $h++): ?>
                        <span class="cb-hour" style="left:<?php echo $pct($h + 0.5); ?>%"><?php echo (($h % 12) ?: 12) . ($h >= 12 ? 'PM' : 'AM'); ?></span>
                    <?php endfor; ?>
                </div>
            </div>

            <?php foreach ($grid as $cid => $c):
                $bg       = $palette[(((int)$cid - 1) % 8 + 8) % 8];
                $court_label = 'Court No. ' . (int)$cid;   // type (Standard/Premium) is shown in the photo popup
                $is_maint = ($c['status'] === 'Maintenance');

                // Merge consecutive hours that share a state into one block.
                // Hours that already passed today are skipped (they stay empty cells).
                $runs = [];
                if (!$is_maint) {
                    foreach ($hours as $h) {
                        $ts = sprintf('%02d:00:00', $h);
                        $st = $c['slots'][$ts]['s'] ?? 'booked';
                        if ($st === 'past') continue;
                        $n = count($runs);
                        if ($n && $runs[$n - 1]['s'] === $st && $runs[$n - 1]['e'] === $h) {
                            $runs[$n - 1]['e'] = $h + 1;
                            $runs[$n - 1]['hrs'][] = $h;
                        } else {
                            $runs[] = ['s' => $st, 'sh' => $h, 'e' => $h + 1, 'hrs' => [$h]];
                        }
                    }
                }
            ?>
            <div class="cb-row">
                <div class="cb-col">
                    <button type="button" class="cb-view-btn" onclick="viewCourt(<?php echo (int)$cid; ?>, <?php echo htmlspecialchars(json_encode($court_label), ENT_QUOTES); ?>)" aria-label="View court <?php echo (int)$cid; ?> photos" title="View court photos">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                    <span class="cb-badge" style="background:linear-gradient(135deg,<?php echo $bg; ?>)"><?php echo (int)$cid; ?></span>
                    <div class="cb-court">
                        <strong><?php echo htmlspecialchars($court_label); ?></strong>
                        <small><?php if ($is_maint): ?><i class="fa-solid fa-lock"></i>Closed<?php else: ?><span class="cb-rate cb-rate-only"><?php echo $fmt_price($c['price']); ?>/hr</span><?php endif; ?></small>
                    </div>
                </div>

                <div class="cb-track">
                <?php if ($is_maint): ?>
                    <div class="cb-maint">
                        <i class="fa-solid fa-screwdriver-wrench"></i>
                        <div><b>Maintenance</b><span>Unavailable for booking</span></div>
                    </div>
                <?php else: foreach ($runs as $r):
                    $style = 'left:' . $pct($r['sh']) . '%;width:' . number_format((($r['e'] - $r['sh']) / $N) * 100, 4, '.', '') . '%';
                    $one   = count($r['hrs']) === 1;
                    $range = $one ? $fmt_short($r['sh'], $r['e']) : ($fmt_h($r['sh']) . ' – ' . $fmt_h($r['e']));
                    $full  = $fmt_h($r['sh']) . ' – ' . $fmt_h($r['e']);

                    if ($r['s'] === 'booked' || $r['s'] === 'held'):
                        $cls = $r['s'] === 'booked' ? 'bk' : 'hold';
                        $lbl = $r['s'] === 'booked' ? 'Booked' : 'On hold';
                ?>
                    <div class="cb-slot <?php echo $cls; ?><?php echo $one ? ' one' : ''; ?>" style="<?php echo $style; ?>" title="<?php echo $full . ' · ' . $lbl; ?>">
                        <div class="cb-label"><b><?php echo $range; ?></b><span><?php echo $lbl; ?></span></div>
                    </div>
                <?php else: ?>
                    <div class="cb-slot ok<?php echo $one ? ' one' : ''; ?>" style="<?php echo $style; ?>" title="<?php echo $full . ' · Available'; ?>">
                        <div class="cb-label"><b><?php echo $range; ?></b><span>Available</span></div>
                        <div class="cb-segs">
                            <?php foreach ($r['hrs'] as $h):
                                $ts = sprintf('%02d:00:00', $h);
                                $md = (int)$c['slots'][$ts]['md'];
                            ?>
                                <button type="button" class="cb-seg"
                                        data-cid="<?php echo (int)$cid; ?>" data-cname="<?php echo htmlspecialchars($court_label); ?>"
                                        data-ts="<?php echo $ts; ?>" data-h="<?php echo $h; ?>" data-md="<?php echo $md; ?>" data-price="<?php echo (float)$c['price']; ?>"
                                        title="Book <?php echo $fmt_h($h) . ' – ' . $fmt_h($h + 1); ?>"
                                        aria-label="<?php echo htmlspecialchars($court_label) . ', ' . $fmt_h($h) . ' to ' . $fmt_h($h + 1) . ', available'; ?>"
                                        onclick="pickSlot(this)"></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; endforeach; endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($is_today): ?>
                <!-- Now line + past shading: positioned in % by JS against the venue clock. -->
                <div class="cb-now-layer" aria-hidden="true">
                    <div class="cb-shade" id="nowShade"></div>
                    <div class="cb-now" id="nowLine" style="left:0;display:none;"
                         data-hour="<?php echo $current_hour; ?>" data-minute="<?php echo $current_minute; ?>">
                        <span class="cb-now-tag" id="nowTag"><?php echo date('g:i A'); ?></span>
                    </div>
                </div>
            <?php endif; ?>

        </div>
      </div>
      <div class="cb-hint"><i class="fa-solid fa-arrows-left-right"></i> Swipe sideways to see other times</div>
    </div>

  </div>
</main>

<!-- ========================================== -->
<!-- BOOKING PANEL                              -->
<!-- ========================================== -->
<div class="cb-panel" id="bookPanel" role="region" aria-label="Book this slot">
    <div class="cb-panel-inner">
        <div class="cb-panel-top">
            <span class="cb-panel-icon"><i class="fa-regular fa-calendar-check"></i></span>
            <div>
                <strong id="bpCourt">Court</strong>
                <small id="bpTime">--</small>
            </div>
            <button type="button" class="cb-close" onclick="clearPick()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="cb-fields">
            <select id="bpDur" aria-label="Duration"></select>
            <input type="number" id="bpPlayers" min="1" max="20" value="2" placeholder="Pax" aria-label="Number of players">
        </div>
        <div class="cb-panel-foot">
            <span class="cb-total" id="bpTotal">₱0</span>
            <?php if (!$is_logged_in): ?>
                <a class="cb-btn" href="/authentication/login"><i class="fa-solid fa-right-to-bracket"></i> Login to Book</a>
            <?php elseif ($view_date < date('Y-m-d')): ?>
                <button type="button" class="cb-btn" disabled><i class="fa-solid fa-bolt"></i> Book &amp; Pay</button>
            <?php else: ?>
                <button type="button" class="cb-btn" onclick="doBook()"><i class="fa-solid fa-bolt"></i> Book &amp; Pay</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Hidden form -->
<form id="bkForm" method="POST" action="?date=<?php echo $view_date; ?>" class="hidden" style="display:none">
    <?php echo get_csrf_input(); ?>
    <input type="hidden" name="action" value="book_court">
    <input type="hidden" id="fCid" name="court_id">
    <input type="hidden" id="fTime" name="start_time">
    <input type="hidden" id="fDur" name="duration">
    <input type="hidden" id="fPly" name="player_count">
</form>

<!-- Court image modal -->
<div class="cb-modal" id="courtModal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="cb-modal-backdrop" onclick="closeCourtModal()"></div>
  <div class="cb-modal-card">
    <button type="button" class="cb-modal-close" onclick="closeCourtModal()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="cb-modal-head">
      <span class="cb-badge" id="cmBadge" style="background:linear-gradient(135deg,#ff6b3d,#f0382f)">1</span>
      <div>
        <strong id="cmName">Court</strong>
        <small id="cmType">Premium Court</small>
      </div>
    </div>
    <div class="cb-modal-gallery" id="cmGallery"></div>
    <p class="cb-modal-empty" id="cmEmpty" style="display:none"></p>
    <div class="cb-zoom-row" id="cmZoomRow">
      <button type="button" class="cb-zoom-btn" id="cmZoomBtn"><i class="fa-solid fa-magnifying-glass-plus"></i> Zoom photos</button>
      <span>or tap a photo · pinch or scroll to zoom</span>
    </div>
    <div class="cb-modal-details">
      <div><span class="cb-modal-label">Type</span><strong id="cmTypeDetail">Premium</strong></div>
      <div><span class="cb-modal-label">Rate</span><strong id="cmRate">—</strong></div>
      <div><span class="cb-modal-label">Net</span><strong>Viktor Net</strong></div>
    </div>
  </div>
</div>

<!-- Photo zoom viewer -->
<div class="cb-lightbox" id="cbLightbox" role="dialog" aria-modal="true" aria-label="Court photo viewer" aria-hidden="true">
  <div class="cb-lb-bar">
    <button type="button" class="cb-lb-btn" id="lbOut" aria-label="Zoom out" title="Zoom out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
    <span class="cb-lb-level" id="lbLevel">100%</span>
    <button type="button" class="cb-lb-btn" id="lbIn" aria-label="Zoom in" title="Zoom in"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
    <button type="button" class="cb-lb-btn" id="lbReset" aria-label="Reset zoom" title="Reset"><i class="fa-solid fa-expand"></i></button>
  </div>
  <button type="button" class="cb-lb-btn cb-lb-close" id="lbClose" aria-label="Close viewer" title="Close"><i class="fa-solid fa-xmark"></i></button>
  <button type="button" class="cb-lb-btn cb-lb-nav prev" id="lbPrev" aria-label="Previous photo"><i class="fa-solid fa-chevron-left"></i></button>
  <button type="button" class="cb-lb-btn cb-lb-nav next" id="lbNext" aria-label="Next photo"><i class="fa-solid fa-chevron-right"></i></button>
  <div class="cb-lb-stage" id="lbStage"><img id="lbImg" alt="" draggable="false"></div>
  <div class="cb-lb-hint">Scroll or pinch to zoom · drag to move · double-tap to toggle</div>
  <div class="cb-lb-count" id="lbCount"></div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>

<script>
let chosen=null;
let chosenRate=300;
const MAX_HOURS=6; // mirrors the server-side limit (1-6 hours)
const peso=n=>'₱'+Number(n).toLocaleString(undefined,{maximumFractionDigits:2});

function pickSlot(el){
    if(!el||el.classList.contains('past'))return;
    chosen=el;
    chosenRate=parseFloat(el.dataset.price)||300;

    const h=parseInt(el.dataset.h);
    const ampm=h>=12?'PM':'AM';
    const dh=h%12||12;
    const eh=h+1; const eA=eh>=12?'PM':'AM'; const dEh=eh%12||12;
    document.getElementById('bpCourt').textContent=el.dataset.cname;
    document.getElementById('bpTime').textContent=`${dh}:00 ${ampm} – ${dEh}:00 ${eA} • <?php echo date('D, M j', strtotime($view_date)); ?>`;

    const md=Math.min(parseInt(el.dataset.md)||1,MAX_HOURS);
    const sel=document.getElementById('bpDur');
    sel.innerHTML='';
    for(let i=1;i<=md;i++){
        const xh=h+i; const xA=xh>=12?'PM':'AM'; const xdh=xh%12||12;
        sel.innerHTML+=`<option value="${i}">${i} hr — until ${xdh}:00 ${xA} (${peso(i*chosenRate)})</option>`;
    }
    sel.onchange=()=>{updTotal();markRange();};
    updTotal(); markRange();

    document.getElementById('bookPanel').classList.add('show');
}

// Highlight the hours that the chosen start time + duration will cover.
function markRange(){
    document.querySelectorAll('.cb-seg.in-range').forEach(e=>e.classList.remove('in-range'));
    if(!chosen)return;
    const dur=parseInt(document.getElementById('bpDur').value)||1;
    const h=parseInt(chosen.dataset.h);
    document.querySelectorAll('.cb-seg[data-cid="'+chosen.dataset.cid+'"]').forEach(s=>{
        const x=parseInt(s.dataset.h);
        if(x>=h&&x<h+dur) s.classList.add('in-range');
    });
}

function updTotal(){
    const dur=parseInt(document.getElementById('bpDur').value)||1;
    document.getElementById('bpTotal').textContent=peso(dur*chosenRate);
}

function clearPick(){
    document.querySelectorAll('.cb-seg.in-range').forEach(e=>e.classList.remove('in-range'));
    chosen=null;
    document.getElementById('bookPanel').classList.remove('show');
}

function doBook(){
    if(!chosen)return;
    document.getElementById('fCid').value=chosen.dataset.cid;
    document.getElementById('fTime').value=chosen.dataset.ts;
    document.getElementById('fDur').value=document.getElementById('bpDur').value;
    document.getElementById('fPly').value=document.getElementById('bpPlayers').value;
    document.getElementById('bkForm').submit();
}

function goDate(inp){
    if(!inp.value)return;
    const min=inp.getAttribute('min');
    if(inp.value<min){
        alert('Cannot book a court for a past date.');
        inp.value=min;
        window.location.href='courtbooking?date='+min;
        return;
    }
    window.location.href='courtbooking?date='+inp.value;
}

function courtType(id){ return (id===3||id===4) ? 'Standard' : 'Premium'; }

// Browsers other than Safari can't display .heic, so try web formats first and HEIC last.
const COURT_IMG_DIR = '/img/courts/';
const COURT_IMG_EXTS = ['jpg','jpeg','png','webp','heic'];
let galleryToken = 0;

function addCourtPhoto(gallery, base, alt, done){
  const img = document.createElement('img');
  img.alt = alt;
  img.style.display = 'none';
  gallery.appendChild(img);
  let i = 0;
  img.onload  = () => { img.style.display = ''; done(true); };
  img.onerror = () => {
    i++;
    if(i < COURT_IMG_EXTS.length){ img.src = COURT_IMG_DIR + base + '.' + COURT_IMG_EXTS[i]; }
    else { img.remove(); done(false); }
  };
  img.src = COURT_IMG_DIR + base + '.' + COURT_IMG_EXTS[0];
}

function loadCourtGallery(id){
  const gallery = document.getElementById('cmGallery');
  const empty   = document.getElementById('cmEmpty');
  const token   = ++galleryToken;
  gallery.innerHTML = '';
  document.getElementById('cmZoomRow').style.display = 'none';

  // Photos uploaded by the admin (Court Management page) come first.
  const uploaded = courtImages[id] || [];
  if(uploaded.length){
    empty.style.display = 'none';
    uploaded.forEach((url, n) => {
      const img = document.createElement('img');
      img.src = url; img.alt = 'Court ' + id + ' photo ' + (n + 1); img.loading = 'lazy';
      img.onerror = () => { img.remove(); if(!gallery.children.length){ empty.style.display = 'block'; empty.textContent = 'No photos available for this court yet.'; } };
      gallery.appendChild(img);
    });
    return;
  }

  // Otherwise fall back to the old court{id}.jpg / court{id}_2.jpg naming.
  empty.style.display = 'block';
  empty.textContent = 'Loading photos…';
  const bases = ['court' + id, 'court' + id + '_2'];
  let pending = bases.length, found = 0;
  bases.forEach((b, n) => addCourtPhoto(gallery, b, 'Court ' + id + ' photo ' + (n + 1), ok => {
    if(token !== galleryToken) return;
    if(ok) found++;
    if(--pending === 0){
      if(found){ empty.style.display = 'none'; return; }
      // Last resort: the shared default photo /img/courts/court.jpg
      addCourtPhoto(gallery, 'court', 'Court photo', ok2 => {
        if(token !== galleryToken) return;
        if(ok2){ empty.style.display = 'none'; }
        else { empty.textContent = 'No photos available for this court yet.'; }
      });
    }
  }));
}

function viewCourt(id, name){
  const modal = document.getElementById('courtModal');
  if(!modal) return;
  const type = courtType(id);
  loadCourtGallery(id);
  document.getElementById('cmBadge').textContent = id;
  document.getElementById('cmName').textContent = name || ('Court No. ' + id);
  document.getElementById('cmType').textContent = type + ' Court';
  document.getElementById('cmTypeDetail').textContent = type;
  const gc = (typeof gridData!=='undefined') ? gridData[id] : null;
  document.getElementById('cmRate').textContent = gc ? peso(gc.price) + ' / hr' : '—';
  modal.classList.add('show');
  modal.setAttribute('aria-hidden','false');
  document.body.style.overflow = 'hidden';
}
function closeCourtModal(){
  const modal = document.getElementById('courtModal');
  if(!modal) return;
  modal.classList.remove('show');
  modal.setAttribute('aria-hidden','true');
  document.body.style.overflow = '';
}

// ---- Photo zoom viewer (wheel / pinch / drag / buttons) ----
const LB_MIN=1, LB_MAX=6;
const lb={open:false,list:[],i:0,s:1,x:0,y:0,pts:new Map(),pinch:null,tap:null,lastTap:0};
const lbBox=document.getElementById('cbLightbox'), lbStage=document.getElementById('lbStage'), lbImg=document.getElementById('lbImg');
const lbLevel=document.getElementById('lbLevel'), lbCount=document.getElementById('lbCount');
const lbPrev=document.getElementById('lbPrev'), lbNext=document.getElementById('lbNext');
const lbInBtn=document.getElementById('lbIn'), lbOutBtn=document.getElementById('lbOut'), lbResetBtn=document.getElementById('lbReset');
const cmGallery=document.getElementById('cmGallery');

function lbGalleryImgs(){
  return [...cmGallery.querySelectorAll('img')].filter(i=>i.style.display!=='none' && i.complete && i.naturalWidth>0);
}
function updateZoomRow(){
  document.getElementById('cmZoomRow').style.display = lbGalleryImgs().length ? 'flex' : 'none';
}
// load/error don't bubble, so listen in the capture phase; wait a tick so display/remove has happened.
['load','error'].forEach(ev=>cmGallery.addEventListener(ev,()=>setTimeout(updateZoomRow,0),true));

function lbClamp(){
  const w=lbImg.offsetWidth*lb.s, h=lbImg.offsetHeight*lb.s;
  const mx=Math.max(0,(w-lbStage.clientWidth)/2), my=Math.max(0,(h-lbStage.clientHeight)/2);
  lb.x=Math.min(mx,Math.max(-mx,lb.x)); lb.y=Math.min(my,Math.max(-my,lb.y));
}
function lbApply(){
  lbClamp();
  lbImg.style.transform='translate('+lb.x+'px,'+lb.y+'px) scale('+lb.s+')';
  lbLevel.textContent=Math.round(lb.s*100)+'%';
  lbStage.classList.toggle('zoomed',lb.s>1);
  lbOutBtn.disabled = lb.s<=LB_MIN+0.001;
  lbInBtn.disabled  = lb.s>=LB_MAX-0.001;
  lbResetBtn.disabled = lb.s<=LB_MIN+0.001;
}
// Zoom toward a point (client coords); defaults to the centre of the viewer.
function lbZoomAt(ns,cx,cy){
  ns=Math.min(LB_MAX,Math.max(LB_MIN,ns));
  const r=lbStage.getBoundingClientRect();
  const px=(cx===undefined?r.left+r.width/2:cx)-(r.left+r.width/2);
  const py=(cy===undefined?r.top+r.height/2:cy)-(r.top+r.height/2);
  const k=ns/lb.s;
  lb.x=px-(px-lb.x)*k; lb.y=py-(py-lb.y)*k; lb.s=ns;
  if(ns<=LB_MIN){lb.x=0;lb.y=0;}
  lbApply();
}
function lbShow(i){
  const n=lb.list.length;
  lb.i=((i%n)+n)%n; lb.s=1; lb.x=0; lb.y=0;
  lbImg.src=lb.list[lb.i];
  lbImg.alt='Court photo '+(lb.i+1);
  lbCount.textContent=(lb.i+1)+' / '+n;
  lbCount.style.display = n>1 ? '' : 'none';
  lbPrev.style.display = lbNext.style.display = n>1 ? '' : 'none';
  lbApply();
}
function openLightbox(i){
  const imgs=lbGalleryImgs();
  if(!imgs.length) return;
  lb.list=imgs.map(im=>im.currentSrc||im.src);
  lb.open=true;
  lbBox.classList.add('show'); lbBox.setAttribute('aria-hidden','false');
  lbShow(Math.min(Math.max(0,i||0),lb.list.length-1));
}
function closeLightbox(){
  if(!lb.open) return;
  lb.open=false; lb.pts.clear(); lb.pinch=null;
  lbBox.classList.remove('show'); lbBox.setAttribute('aria-hidden','true');
  lbImg.removeAttribute('src');
}

lbImg.addEventListener('load',lbApply);
window.addEventListener('resize',()=>{ if(lb.open) lbApply(); });
cmGallery.addEventListener('click',e=>{
  const img=e.target.closest('img'); if(!img) return;
  openLightbox(Math.max(0,lbGalleryImgs().indexOf(img)));
});
document.getElementById('cmZoomBtn').addEventListener('click',()=>openLightbox(0));
document.getElementById('lbClose').addEventListener('click',closeLightbox);
lbInBtn.addEventListener('click',()=>lbZoomAt(lb.s*1.4));
lbOutBtn.addEventListener('click',()=>lbZoomAt(lb.s/1.4));
lbResetBtn.addEventListener('click',()=>lbZoomAt(1));
lbPrev.addEventListener('click',()=>lbShow(lb.i-1));
lbNext.addEventListener('click',()=>lbShow(lb.i+1));

lbStage.addEventListener('wheel',e=>{
  e.preventDefault();
  lbZoomAt(lb.s*Math.exp(-e.deltaY*0.002),e.clientX,e.clientY);
},{passive:false});

lbStage.addEventListener('pointerdown',e=>{
  try{lbStage.setPointerCapture(e.pointerId);}catch(_){}
  lb.pts.set(e.pointerId,{x:e.clientX,y:e.clientY});
  if(lb.pts.size===2){
    const [a,b]=[...lb.pts.values()];
    lb.pinch={d:Math.hypot(a.x-b.x,a.y-b.y)||1,s:lb.s};
    lb.tap=null;
  } else if(lb.pts.size===1){
    lb.tap={t:Date.now(),x:e.clientX,y:e.clientY};
  }
});
lbStage.addEventListener('pointermove',e=>{
  const prev=lb.pts.get(e.pointerId); if(!prev) return;
  const cur={x:e.clientX,y:e.clientY};
  lb.pts.set(e.pointerId,cur);
  if(lb.tap && Math.hypot(cur.x-lb.tap.x,cur.y-lb.tap.y)>8) lb.tap=null;
  if(lb.pts.size===2 && lb.pinch){
    const [a,b]=[...lb.pts.values()];
    const d=Math.hypot(a.x-b.x,a.y-b.y)||1;
    lbZoomAt(lb.pinch.s*d/lb.pinch.d,(a.x+b.x)/2,(a.y+b.y)/2);
  } else if(lb.pts.size===1 && lb.s>1){
    lb.x+=cur.x-prev.x; lb.y+=cur.y-prev.y; lbApply();
  }
});
function lbPointerEnd(e){
  const wasTap = lb.tap && lb.pts.size===1 && (Date.now()-lb.tap.t)<300;
  lb.pts.delete(e.pointerId);
  lb.pinch=null;
  if(wasTap && e.type==='pointerup'){
    const now=Date.now();
    if(now-lb.lastTap<320){ lb.lastTap=0; lbZoomAt(lb.s>1.01?1:2.5,e.clientX,e.clientY); }
    else lb.lastTap=now;
  }
  lb.tap=null;
}
lbStage.addEventListener('pointerup',lbPointerEnd);
lbStage.addEventListener('pointercancel',lbPointerEnd);

document.addEventListener('keydown',e=>{
  if(lb.open){
    if(e.key==='Escape') closeLightbox();
    else if(e.key==='+'||e.key==='=') lbZoomAt(lb.s*1.4);
    else if(e.key==='-'||e.key==='_') lbZoomAt(lb.s/1.4);
    else if(e.key==='0') lbZoomAt(1);
    else if(e.key==='ArrowRight') lbShow(lb.i+1);
    else if(e.key==='ArrowLeft')  lbShow(lb.i-1);
    return;
  }
  if(e.key==='Escape'){
    clearPick();
    closeCourtModal();
  }
});

// ---- Clock + NOW line (venue timezone: Asia/Manila, independent of the visitor's device) ----
function venueNowParts(){
    try{
        const parts=new Intl.DateTimeFormat('en-GB',{
            timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',hour12:false
        }).formatToParts(new Date());
        let hh=parseInt(parts.find(p=>p.type==='hour').value,10);
        const mm=parseInt(parts.find(p=>p.type==='minute').value,10);
        if(hh===24)hh=0;
        if(!Number.isNaN(hh)&&!Number.isNaN(mm)) return {hour:hh,minute:mm};
    }catch(e){}
    return null;
}

function venueClockLabel(){
    try{
        return new Intl.DateTimeFormat('en-US',{
            timeZone:'Asia/Manila',hour:'numeric',minute:'2-digit',hour12:true
        }).format(new Date());
    }catch(e){return '';}
}

function updateNowClock(){
    const clock=document.getElementById('nowClock');
    if(!clock)return;
    const label=venueClockLabel();
    if(label) clock.textContent='Now: '+label+' (PHT)';
}

// The track is N one-hour columns starting at hoursList[0], so everything is a percentage.
function positionNowLine(){
    const line=document.getElementById('nowLine');
    const shade=document.getElementById('nowShade');
    if(!line)return;
    const now=venueNowParts();
    const hour=now?now.hour:parseInt(line.dataset.hour,10);
    const minute=now?now.minute:(parseInt(line.dataset.minute,10)||0);
    const H0=hoursList[0], N=hoursList.length;
    const t=hour+minute/60;

    // Shade every hour that can no longer be booked (matches the server rule).
    const pastEnd=Math.min(Math.max((minute>0?hour+1:hour),H0),H0+N);
    if(shade) shade.style.width=((pastEnd-H0)/N*100)+'%';

    if(t<H0||t>=H0+N){line.style.display='none';return;}
    line.style.display='block';
    line.style.left=((t-H0)/N*100)+'%';
    const tag=document.getElementById('nowTag');
    if(tag){const l=venueClockLabel(); if(l) tag.textContent=l;}
}

function centerNowLine(){
    const sc=document.getElementById('tlScroll');
    const line=document.getElementById('nowLine');
    if(!sc||!line||line.style.display==='none')return;
    if(sc.scrollWidth<=sc.clientWidth)return;
    const layer=line.parentElement;
    sc.scrollLeft=Math.max(0,layer.offsetLeft+line.offsetLeft-sc.clientWidth/3);
}

document.addEventListener('DOMContentLoaded',()=>{
    updateNowClock();
    positionNowLine();
    centerNowLine();
    // Keep the clock/marker fresh without a page reload.
    setInterval(()=>{updateNowClock();positionNowLine();},30000);
});
</script>
<script src="//code.tidio.co/xrwsq4cmchufdxjlebbrzzag1vlabwsq.js" async></script>
</body>
</html>