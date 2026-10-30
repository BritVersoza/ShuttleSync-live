<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/database_connect.php';
require_once __DIR__ . '/includes/csrf_helper.php';

// 1. Determine login state (Guests are allowed!)
$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;
$full_name = $is_logged_in ? $_SESSION['full_name'] : 'Guest';

// Fetch cart count for the navigation badge
$cart_count = 0;
if ($is_logged_in) {
    try {
        $cart_stmt = $pdo->prepare("SELECT SUM(quantity) FROM Cart_Items WHERE user_id = ?");
        $cart_stmt->execute([$user_id]);
        $cart_count = $cart_stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        $cart_count = 0;
    }
}

// Fetch recent analyses for logged-in users
$recent_analyses = [];
if ($is_logged_in) {
    try {
        $log_stmt = $pdo->prepare("SELECT stroke_type, accuracy_score, feedback, analyzed_at FROM AI_Analysis_Logs WHERE user_id = ? ORDER BY analyzed_at DESC LIMIT 5");
        $log_stmt->execute([$user_id]);
        $recent_analyses = $log_stmt->fetchAll();
    } catch (PDOException $e) {
        $recent_analyses = [];
    }
}

// ==========================================
// HANDLE AJAX REQUEST: IMAGE ANALYSIS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_image') {
    verify_csrf_token();

    header('Content-Type: application/json');

    try {
        if (!isset($_FILES['image_file']) || $_FILES['image_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please upload a valid image file.");
        }

        $file = $_FILES['image_file'];
        
        // STRICT BACKEND LIMIT: 10MB (10 * 1024 * 1024 bytes)
        if ($file['size'] > 10485760) {
            throw new Exception("File is too large! Maximum allowed size is 10MB.");
        }

        // CHECK EXTENSION FOR IMAGES ONLY
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            throw new Exception("Only JPG, PNG, or WEBP images are supported.");
        }

        $shot_type = $_POST['shot_type'] ?? 'General Play';
        
        // Save uploaded file to temp
        $tmp_dir = sys_get_temp_dir() . '/shuttlesync_ai';
        if (!is_dir($tmp_dir)) {
            mkdir($tmp_dir, 0755, true);
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safe_ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) ? $ext : 'jpg';
        $tmp_path = $tmp_dir . '/upload_' . bin2hex(random_bytes(8)) . '.' . $safe_ext;
        
        if (!move_uploaded_file($file['tmp_name'], $tmp_path)) {
            throw new Exception("Failed to save uploaded image.");
        }
        
        // Get AI config from .env
        require_once __DIR__ . '/includes/config.php';
        $ai_enabled = Config::get('AI_ENABLED', 'true');
        $python_path = Config::get('PYTHON_PATH', '/www/server/project/venv/bin/python');
        $script_path = __DIR__ . '/' . Config::get('AI_SCRIPT_PATH', 'ai/analysis_engine.py');

        // Admin-configured AI thresholds (AI Lab). Fall back to engine defaults.
        $conf_threshold  = 0.45;
        $angle_tolerance = 45.00;
        try {
            $cfg = $pdo->query("SELECT confidence_threshold, angle_tolerance FROM ai_config WHERE config_id = 1")->fetch(PDO::FETCH_ASSOC);
            if ($cfg) {
                $conf_threshold  = (float)$cfg['confidence_threshold'];
                $angle_tolerance = (float)$cfg['angle_tolerance'];
            }
        } catch (Exception $e) { /* ai_config not migrated yet - use defaults */ }

        if ($ai_enabled === 'true' && file_exists($script_path)) {
            // Call Python AI engine
            $cmd = escapeshellcmd($python_path);
            $args = escapeshellarg($script_path) . ' ' . escapeshellarg($tmp_path) . ' ' . escapeshellarg($shot_type)
                  . ' ' . escapeshellarg((string)$conf_threshold) . ' ' . escapeshellarg((string)$angle_tolerance);
            $full_cmd = "$cmd $args 2>/dev/null";
            
            $output = [];
            $return_code = 0;
            exec($full_cmd, $output, $return_code);
            
            @unlink($tmp_path); // Clean up temp file
            
            if ($return_code === 0 && !empty($output)) {
                $json_str = implode("\n", $output);
                $result = json_decode($json_str, true);
                
                if ($result && isset($result['success']) && $result['success']) {
                    // Log to AI_Analysis_Logs
                    if (session_status() === PHP_SESSION_NONE) session_start();
                    $log_uid = $_SESSION['user_id'] ?? null;
                    if ($log_uid) {
                        try {
                            require_once __DIR__ . '/includes/database_connect.php';
                            $acc = $result['data']['accuracy'] ?? 0;
                            $stroke = $result['data']['stroke_type'] ?? $shot_type;
                            $feedback = $result['data']['feedback'] ?? '';
                            $pdo->prepare("INSERT INTO AI_Analysis_Logs (user_id, stroke_type, accuracy_score, feedback, analyzed_at) VALUES (?, ?, ?, ?, NOW())")->execute([$log_uid, $stroke, $acc, $feedback]);
                        } catch (Exception $e) {}
                    }
                    echo json_encode($result);
                    exit;
                } else {
                    // AI returned error, fall through to fallback
                    $ai_error = $result['error'] ?? 'Unknown AI error';
                }
            } else {
                $ai_error = "Python script failed with code: $return_code";
            }
        } else {
            @unlink($tmp_path);
            $ai_error = "AI engine not available (AI_ENABLED=$ai_enabled)";
        }
        
        // Fallback: If AI engine fails, use basic image analysis
        $fallback_accuracy = 50;
        $fallback_feedback = "The AI analysis engine is currently unavailable. Please try again later or contact support.";
        
        echo json_encode([
            "success" => true,
            "data" => [
                "stroke_type" => $shot_type,
                "verdict" => "Needs Work",
                "accuracy" => $fallback_accuracy,
                "feedback" => $fallback_feedback,
                "similarity_score" => 0,
                "sub_scores" => ["footwork" => 0, "posture" => 0, "swing" => 0],
                "reference_skeleton" => null,
                "detected_skeleton" => null,
                "quality_score" => 0,
                "quality_warnings" => ["AI engine unavailable. Analysis could not be completed."],
                "detected_shot_type" => $shot_type,
                "detection_confidence" => 0,
            ]
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>AI Coach | HI-POWER</title>
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <style>
        .ai-body {
            margin: 0;
            background: linear-gradient(120deg, #f8fbff, #edf5fc);
            color: #122b43;
        }
        .ai-page {
            max-width: 1500px;
            margin: 0 auto;
            padding: 96px 2.6% 40px;
        }
        .ai-hero {
            min-height: 250px;
            border-radius: 15px;
            overflow: hidden;
            display: flex;
            align-items: center;
            padding: 40px;
            background: linear-gradient(90deg, #041522 0%, #041522e8 42%, #04152220 100%),
                        url('https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?auto=format&fit=crop&w=1800&q=85') center 43% / cover;
            color: #fff;
        }
        .ai-eyebrow {
            color: #ff5722;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .ai-hero h1 {
            font-size: clamp(32px, 4vw, 48px);
            line-height: 1.05;
            margin: 10px 0;
            color: #fff;
        }
        .ai-hero h1 span { color: #ff5722; }
        .ai-hero p { margin: 10px 0; color: #e0eaf3; }
        .ai-orange-line {
            height: 4px;
            width: 120px;
            background: linear-gradient(90deg, #ff5722, transparent);
            margin-top: 22px;
        }
        .ai-panel {
            margin-top: 18px;
            background: #ffffffd9;
            border: 1px solid #dfe9f5;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 8px 25px #1b3b5607;
        }
        .ai-label {
            font-size: 12px;
            color: #2c5276;
            font-weight: 800;
            letter-spacing: .8px;
            margin-bottom: 9px;
            text-transform: uppercase;
        }
        .ai-select {
            width: 100%;
            padding: 13px;
            border: 1px solid #cfdeef;
            background: #f8fbff;
            border-radius: 9px;
            color: #122b43;
            font-size: 15px;
            font-weight: 600;
            font-family: inherit;
            outline: none;
        }
        .ai-select:focus { border-color: #ff5722; box-shadow: 0 0 0 3px #ff572222; }
        .ai-uploadrow {
            display: grid;
            grid-template-columns: 1.2fr .9fr;
            gap: 22px;
            margin-top: 20px;
        }
        .ai-video {
            min-height: 320px;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
            background: #061723 url('https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?auto=format&fit=crop&w=1200&q=85') center / cover;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .ai-video::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(0deg, #041522c7, #0415221f 55%);
            z-index: 0;
            pointer-events: none;
        }
        .ai-badge {
            position: absolute;
            z-index: 3;
            top: 15px;
            left: 15px;
            background: #061723dd;
            border: 1px solid #ff6a35;
            color: #fff;
            border-radius: 20px;
            padding: 7px 12px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .04em;
        }
        .ai-badge.ready { border-color: #35c98b; }
        .ai-scan {
            position: absolute;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, #ff5722, transparent);
            animation: aiScanMove 2s ease-in-out infinite;
            z-index: 3;
        }
        @keyframes aiScanMove {
            0% { top: 0; }
            50% { top: 100%; }
            100% { top: 0; }
        }
        .ai-uploadstate {
            position: relative;
            z-index: 2;
            width: 100%;
            padding: 30px 20px;
            text-align: center;
        }
        .ai-uploadstate:not(.hidden) {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .ai-uploadstate canvas {
            width: 190px;
            height: auto;
            max-height: 230px;
            opacity: .9;
            border-radius: 18px;
            background: radial-gradient(circle at center, #041522b3 0%, #04152240 62%, transparent 78%);
            filter: drop-shadow(0 6px 18px #0006);
        }
        .ai-ref-caption {
            display: inline-block;
            margin-top: 6px;
            color: #e8f1f8;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .16em;
            text-transform: uppercase;
            background: #061723b8;
            border: 1px solid #ffffff2e;
            border-radius: 20px;
            padding: 5px 10px;
        }
        .ai-preview {
            position: absolute;
            inset: 0;
            padding: 12px;
            z-index: 2;
        }
        .ai-preview img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 8px;
        }
        .ai-preview canvas {
            position: absolute;
            inset: 12px;
            width: calc(100% - 24px);
            height: calc(100% - 24px);
            border-radius: 8px;
            pointer-events: none;
        }
        .ai-clear {
            position: absolute;
            top: 18px;
            right: 18px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffffd9;
            border: 1px solid #ffffff;
            border-radius: 50%;
            color: #122b43;
            cursor: pointer;
            z-index: 4;
            transition: all .2s ease;
        }
        .ai-clear:hover { background: #dc2626; color: #fff; }
        .ai-clear .material-symbols-outlined { font-size: 18px; }
        .ai-processing {
            position: absolute;
            inset: 0;
            z-index: 5;
            background: #f4f6f8f2;
            border-radius: 10px;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
        }
        .ai-processing:not(.hidden) { display: flex; }
        .ai-processing h3 { font-size: 17px; margin: 16px 0 4px; color: #122b43; }
        .ai-processing p { font-size: 12px; color: #6f84a0; margin: 0; }
        .ai-spinner {
            width: 58px;
            height: 58px;
            border: 4px solid #ff572226;
            border-top-color: #ff5722;
            border-radius: 50%;
            animation: aiSpin 1s linear infinite;
        }
        @keyframes aiSpin { to { transform: rotate(360deg); } }
        @keyframes aiFadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .ai-uploadbox {
            border: 1px solid #dfe9f5;
            background: #f7faff;
            border-radius: 10px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .ai-uploadbox h3 { font-size: 15px; margin: 12px 0 6px; color: #122b43; }
        .ai-uploadbox p { font-size: 12px; color: #6f84a0; line-height: 1.6; margin: 0 0 12px; }
        .ai-buttons { display: flex; gap: 12px; margin-top: 6px; }
        .ai-btn {
            padding: 13px 18px;
            border-radius: 8px;
            border: 1px solid #c9d9eb;
            background: #fff;
            color: #122b43;
            font-weight: 700;
            cursor: pointer;
            flex: 1;
            font-family: inherit;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all .2s ease;
        }
        .ai-btn:hover { border-color: #ff5722; color: #ff5722; }
        .ai-btn.primary { background: #ff5722; border-color: #ff5722; color: #fff; }
        .ai-btn.primary:hover { background: #ed4817; color: #fff; }
        .ai-btn .material-symbols-outlined { font-size: 18px; }
        .ai-filename {
            font-size: 11px;
            color: #2c5276;
            margin: 10px 0 0;
            word-break: break-word;
        }
        .ai-filename:empty { display: none; }
        .ai-tips {
            margin-top: 18px;
            padding: 18px 20px;
            border-radius: 10px;
            background: #f0f6fd;
            border: 1px solid #e1ebf7;
        }
        .ai-tiptitle {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .8px;
            color: #2d5579;
            margin-bottom: 13px;
            text-transform: uppercase;
        }
        .ai-tiptitle span { color: #ff5722; }
        .ai-tipitems {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            font-size: 11px;
            color: #3d5b78;
            line-height: 1.45;
        }
        .ai-tipitems span::before {
            content: '\2713';
            display: inline-grid;
            place-items: center;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            border: 1px solid #8aa8c6;
            margin-right: 6px;
            color: #3978a9;
            font-size: 9px;
        }
        .ai-results {
            margin-top: 18px;
            background: #fff;
            border: 1px solid #dfe9f5;
            border-radius: 13px;
            overflow: hidden;
        }
        .ai-scoretop {
            display: grid;
            grid-template-columns: 180px 1fr;
            align-items: center;
            padding: 22px 28px;
            border-bottom: 1px solid #dfe9f5;
            gap: 22px;
        }
        .ai-ring {
            width: 130px;
            height: 130px;
            border-radius: 50%;
            background: conic-gradient(#e4ebf3 0% 100%);
            display: grid;
            place-items: center;
            text-align: center;
            margin: auto;
            color: #315675;
            font-size: 11px;
            position: relative;
        }
        .ai-ring::before {
            content: '';
            position: absolute;
            inset: 10px;
            background: #fff;
            border-radius: 50%;
        }
        .ai-ring > div { position: relative; z-index: 1; }
        .ai-ring strong {
            font-size: 24px;
            color: #ff5722;
            display: block;
            line-height: 1.1;
        }
        .ai-ring span {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 800;
            color: #6f84a0;
        }
        .ai-scoretop h2 {
            font-size: 18px;
            margin: 0 0 8px;
            color: #122b43;
            text-transform: uppercase;
            letter-spacing: .02em;
        }
        .ai-scoretop p { margin: 0; color: #6f84a0; font-size: 13px; line-height: 1.6; }
        .ai-scoretop p b { color: #ff5722; }
        .ai-similarity {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            font-size: 11px;
            color: #6f84a0;
            flex-wrap: wrap;
        }
        .ai-simbar {
            width: 120px;
            height: 7px;
            background: #e5ebf3;
            border-radius: 10px;
            overflow: hidden;
        }
        .ai-simbar span {
            display: block;
            height: 100%;
            width: 0;
            background: #ff5722;
            border-radius: 10px;
            transition: width .5s ease;
        }
        .ai-similarity b { color: #ff5722; font-size: 11px; }
        .ai-resultbottom {
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            padding: 22px 28px;
            gap: 25px;
        }
        .ai-metrics h3 {
            font-size: 12px;
            margin: 0 0 14px;
            letter-spacing: .6px;
            text-transform: uppercase;
            color: #122b43;
        }
        .ai-metric { margin: 13px 0; }
        .ai-metrichead {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            margin-bottom: 6px;
            gap: 10px;
            color: #122b43;
            font-weight: 600;
        }
        .ai-metrichead span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ai-metrichead .material-symbols-outlined { font-size: 16px; color: #94a3b8; }
        .ai-metrichead b { color: #ff5722; }
        .ai-bar {
            height: 6px;
            border-radius: 10px;
            background: #e5ebf3;
            overflow: hidden;
        }
        .ai-bar span {
            display: block;
            height: 100%;
            width: 0;
            background: #ff5722;
            border-radius: 10px;
            transition: width .5s ease;
        }
        .ai-metricnote {
            margin: 14px 0 0;
            font-size: 10.5px;
            line-height: 1.6;
            color: #6f84a0;
        }
        .ai-warnings { margin-top: 12px; }
        .ai-warn {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 8px 10px;
            margin-top: 6px;
        }
        .ai-warn .material-symbols-outlined { font-size: 15px; color: #d97706; margin-top: 1px; }
        .ai-warn > span:last-child { font-size: 11px; color: #92400e; line-height: 1.4; }
        .ai-feedback {
            border: 1px solid #ffd1bd;
            background: #fff7f2;
            border-radius: 10px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .ai-feedback h3 {
            font-size: 13px;
            color: #ff5722;
            margin: 0 0 10px;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .ai-feedback-body { font-size: 12px; line-height: 1.7; color: #526c86; }
        .ai-feedback-text { font-size: 12px; line-height: 1.7; color: #526c86; margin: 0; }
        .ai-detected {
            background: #fff;
            border: 1px solid #dfe9f5;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
        }
        .ai-detected-label {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: #94a3b8;
        }
        .ai-detected-name {
            font-size: 14px;
            font-weight: 800;
            color: #122b43;
            margin: 4px 0 0;
        }
        .ai-detected-name small { font-size: 10px; font-weight: 400; color: #6f84a0; }
        .ai-history { margin-top: 28px; }
        .ai-history h2 {
            font-size: 20px;
            margin: 0 0 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #122b43;
        }
        .ai-history h2 .material-symbols-outlined { color: #ff5722; font-size: 24px; }
        .ai-history-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
        }
        .ai-history-card {
            background: #fff;
            border: 1px solid #dfe9f5;
            border-radius: 12px;
            padding: 14px;
            transition: all .2s ease;
        }
        .ai-history-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px #00000014;
        }
        .ai-history-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 8px;
        }
        .ai-history-stroke {
            font-size: 11px;
            font-weight: 700;
            color: #122b43;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ai-history-score {
            font-size: 10px;
            font-weight: 800;
            padding: 3px 7px;
            border-radius: 999px;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .ai-history-bar {
            height: 5px;
            background: #e5ebf3;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        .ai-history-bar span {
            display: block;
            height: 100%;
            border-radius: 10px;
        }
        .ai-history-date { font-size: 10px; color: #94a3b8; margin: 0; }
        @media (max-width: 1100px) {
            .ai-history-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 850px) {
            .ai-uploadrow { grid-template-columns: 1fr; }
            .ai-video { min-height: 260px; }
            .ai-tipitems { grid-template-columns: repeat(2, 1fr); }
            .ai-resultbottom { grid-template-columns: 1fr; }
            .ai-scoretop { grid-template-columns: 120px 1fr; padding: 20px; }
            .ai-ring { width: 100px; height: 100px; }
            .ai-ring strong { font-size: 20px; }
        }
        @media (max-width: 600px) {
            .ai-page { padding: 84px 15px 30px; }
            .ai-hero { min-height: 220px; padding: 24px; background-position: 60% center; }
            .ai-hero h1 { font-size: 33px; }
            .ai-panel { padding: 15px; }
            .ai-uploadrow { gap: 12px; }
            .ai-video { min-height: 220px; }
            .ai-buttons { flex-direction: column; }
            .ai-scoretop { grid-template-columns: 1fr; gap: 18px; text-align: center; }
            .ai-scoretop p { margin: 0 auto; }
            .ai-similarity { justify-content: center; }
            .ai-resultbottom { padding: 18px; }
            .ai-history-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body class="ai-body">

<?php include __DIR__ . '/includes/nav_bar.php'; ?>

<main class="ai-page">

    <section class="ai-hero">
        <div>
            <div class="ai-eyebrow">AI Coach</div>
            <h1>AI Biomechanical<br><span>Coach</span></h1>
            <p>Powered by MediaPipe Pose Detection &middot; Real-time skeletal analysis</p>
            <div class="ai-orange-line"></div>
        </div>
    </section>

    <section class="ai-panel">
        <label class="ai-label" for="shotSelection">Shot Type</label>
        <select id="shotSelection" class="ai-select">
            <option value="Smash">Jump Smash</option>
            <option value="Serve">Short / Flick Serve</option>
            <option value="Cross Drop">Cross Court Drop</option>
            <option value="Net Play">Net Kill / Spin</option>
            <option value="General Play">General Footwork Base</option>
        </select>

        <div class="ai-uploadrow">
            <div id="dropZone" class="ai-video" role="button" tabindex="0" aria-label="Upload or drop a badminton action photo">
                <span id="aiBadge" class="ai-badge">&#9679; READY FOR ANALYSIS</span>
                <div id="scanLine" class="ai-scan hidden"></div>
                <input type="file" id="imageInput" accept="image/png, image/jpeg, image/webp" class="hidden">

                <div id="uploadState" class="ai-uploadstate">
                    <canvas id="refSkeletonCanvas" width="240" height="280" aria-hidden="true"></canvas>
                    <span class="ai-ref-caption">Reference Pose</span>
                </div>

                <div id="previewState" class="ai-preview hidden">
                    <img id="previewImage" alt="Selected badminton action preview">
                    <canvas id="detectedSkeletonCanvas" aria-hidden="true"></canvas>
                    <button type="button" id="clearPreview" class="ai-clear" aria-label="Remove selected photo">
                        <span class="material-symbols-outlined" aria-hidden="true">close</span>
                    </button>
                </div>

                <div id="processingState" class="ai-processing hidden">
                    <div class="ai-spinner"></div>
                    <h3>Analyzing Posture...</h3>
                    <p>Detecting keypoints &middot; Measuring angles</p>
                </div>
            </div>

            <div class="ai-uploadbox">
                <div class="ai-label"><span id="refShotLabel">Ideal: Jump Smash</span></div>
                <h3>Drop your action shot</h3>
                <p>Match the reference pose on the left &middot; JPG, PNG, WEBP &middot; Max 10MB</p>
                <div class="ai-buttons">
                    <button type="button" id="browseBtn" class="ai-btn primary">
                        <span class="material-symbols-outlined" aria-hidden="true">folder_open</span> Browse
                    </button>
                    <button type="button" id="cameraBtn" class="ai-btn">
                        <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span> Camera
                    </button>
                </div>
                <p id="filename" class="ai-filename" aria-live="polite"></p>
            </div>
        </div>

        <div class="ai-tips">
            <div class="ai-tiptitle">&#10003; Tips for <span>Best Results</span></div>
            <div class="ai-tipitems">
                <span>Full body visible head-to-toe</span>
                <span>Slightly 45&deg; angle works best</span>
                <span>Good lighting, minimal background</span>
                <span>High resolution photo (1024&times;768+)</span>
                <span>Match the reference pose shown above</span>
            </div>
        </div>

        <section class="ai-results" aria-label="Analysis results">
            <div class="ai-scoretop">
                <div class="ai-ring" id="mainGauge">
                    <div>
                        <strong id="overallScore">--</strong>
                        <span id="overallVerdict">Pending</span>
                    </div>
                </div>
                <div>
                    <h2>Performance Score</h2>
                    <p>Your form will be auto-detected and scored against the <b id="refShotName">Jump Smash</b>.</p>
                    <div class="ai-similarity">
                        <span>Reference match</span>
                        <div class="ai-simbar"><span id="similarityBar"></span></div>
                        <b id="similarityScore">--%</b>
                    </div>
                </div>
            </div>

            <div class="ai-resultbottom">
                <div class="ai-metrics">
                    <h3>Kinematic Metrics</h3>

                    <div class="ai-metric">
                        <div class="ai-metrichead">
                            <span><span class="material-symbols-outlined" aria-hidden="true">directions_run</span> Footwork Base</span>
                            <b id="scoreFootwork">--%</b>
                        </div>
                        <div class="ai-bar"><span id="barFootwork"></span></div>
                    </div>

                    <div class="ai-metric">
                        <div class="ai-metrichead">
                            <span><span class="material-symbols-outlined" aria-hidden="true">accessibility_new</span> Body Posture</span>
                            <b id="scorePosture">--%</b>
                        </div>
                        <div class="ai-bar"><span id="barPosture"></span></div>
                    </div>

                    <div class="ai-metric">
                        <div class="ai-metrichead">
                            <span><span class="material-symbols-outlined" aria-hidden="true">sports_martial_arts</span> Swing Angle</span>
                            <b id="scoreSwing">--%</b>
                        </div>
                        <div class="ai-bar"><span id="barSwing"></span></div>
                    </div>

                    <p class="ai-metricnote">Scores are based on the pose in this photo. Footwork Base is your stance at that moment, not live movement.</p>

                    <div id="qualityWarnings" class="hidden ai-warnings"></div>
                </div>

                <div class="ai-feedback">
                    <h3>Coach Feedback</h3>
                    <div id="aiFeedbackText" class="ai-feedback-body">
                        <p class="ai-feedback-text">Upload an image of your stroke. The AI will auto-detect your shot type and score it against professional form.</p>
                    </div>
                </div>
            </div>
        </section>
    </section>

    <?php if ($is_logged_in && !empty($recent_analyses)): ?>
    <section class="ai-history">
        <h2><span class="material-symbols-outlined" aria-hidden="true">history</span> Recent Analyses</h2>
        <div class="ai-history-grid">
            <?php foreach ($recent_analyses as $analysis):
                $score = $analysis['accuracy_score'];
                $scoreColor = $score > 75 ? 'text-green-600 bg-green-50 border-green-200' : ($score > 50 ? 'text-amber-600 bg-amber-50 border-amber-200' : 'text-red-600 bg-red-50 border-red-200');
                $barColor = $score > 75 ? 'bg-green-500' : ($score > 50 ? 'bg-amber-500' : 'bg-red-500');
            ?>
            <div class="ai-history-card">
                <div class="ai-history-top">
                    <span class="ai-history-stroke"><?php echo htmlspecialchars($analysis['stroke_type']); ?></span>
                    <span class="ai-history-score <?php echo $scoreColor; ?>"><?php echo $score; ?>%</span>
                </div>
                <div class="ai-history-bar">
                    <span class="<?php echo $barColor; ?>" style="width:<?php echo $score; ?>%"></span>
                </div>
                <p class="ai-history-date"><?php echo date('M d, g:i A', strtotime($analysis['analyzed_at'])); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</main>

<!-- Camera Modal -->
<div id="cameraModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 backdrop-blur-sm p-4">
    <div class="bg-surface rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-outline-variant/30">
        <div class="flex items-center justify-between px-5 py-4 border-b border-outline-variant/30">
            <h3 class="text-base font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[20px]">photo_camera</span> Capture Pose
            </h3>
            <button id="closeCameraModal" aria-label="Close camera" class="w-8 h-8 rounded-full bg-surface-variant/50 flex items-center justify-center hover:bg-error hover:text-white transition-all">
                <span class="material-symbols-outlined text-[18px]">close</span>
            </button>
        </div>
        <div class="relative bg-black aspect-[4/3]">
            <video id="cameraFeed" autoplay playsinline class="w-full h-full object-contain"></video>
            <canvas id="cameraCanvas" class="hidden"></canvas>
            <div id="captureFlash" class="absolute inset-0 bg-white opacity-0 pointer-events-none transition-opacity duration-150"></div>
            <div class="absolute inset-0 pointer-events-none flex items-center justify-center">
                <div class="border-2 border-dashed border-white/25 rounded-2xl w-[60%] h-[85%]"></div>
            </div>
        </div>
        <div class="flex items-center justify-center gap-4 px-5 py-4 bg-surface-container-lowest">
            <button id="captureBtn" aria-label="Capture photo" class="w-14 h-14 rounded-full bg-accent hover:bg-accent-dark text-white flex items-center justify-center shadow-lg shadow-accent/30 transition-all hover:scale-105 active:scale-95">
                <span class="material-symbols-outlined text-[28px]">photo_camera</span>
            </button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>

<script>
    const dropZone = document.getElementById('dropZone');
    const imageInput = document.getElementById('imageInput');
    const shotSelection = document.getElementById('shotSelection');

    const uploadState = document.getElementById('uploadState');
    const previewState = document.getElementById('previewState');
    const previewImage = document.getElementById('previewImage');
    const clearPreview = document.getElementById('clearPreview');
    const processingState = document.getElementById('processingState');
    const scanLine = document.getElementById('scanLine');

    const overallScore = document.getElementById('overallScore');
    const overallVerdict = document.getElementById('overallVerdict');
    const aiFeedbackText = document.getElementById('aiFeedbackText');
    const mainGauge = document.getElementById('mainGauge');
    const refShotLabel = document.getElementById('refShotLabel');
    const refShotName = document.getElementById('refShotName');
    const filenameEl = document.getElementById('filename');
    const aiBadge = document.getElementById('aiBadge');

    // ==========================================
    // REFERENCE SKELETON DRAWING
    // ==========================================
    const REFERENCE_POSES = {
        "Smash": { landmarks: {0:[0.48,0.08],11:[0.38,0.22],12:[0.58,0.20],13:[0.30,0.18],14:[0.68,0.10],15:[0.25,0.25],16:[0.72,0.03],23:[0.42,0.45],24:[0.54,0.44],25:[0.38,0.65],26:[0.58,0.60],27:[0.35,0.85],28:[0.62,0.78]} },
        "Serve": { landmarks: {0:[0.48,0.15],11:[0.40,0.30],12:[0.56,0.30],13:[0.32,0.38],14:[0.62,0.40],15:[0.28,0.48],16:[0.55,0.52],23:[0.43,0.52],24:[0.53,0.52],25:[0.40,0.70],26:[0.55,0.68],27:[0.37,0.88],28:[0.58,0.87]} },
        "Cross Drop": { landmarks: {0:[0.48,0.10],11:[0.40,0.24],12:[0.56,0.24],13:[0.34,0.22],14:[0.64,0.20],15:[0.30,0.28],16:[0.70,0.15],23:[0.43,0.46],24:[0.53,0.46],25:[0.40,0.65],26:[0.56,0.63],27:[0.37,0.85],28:[0.59,0.83]} },
        "Net Play": { landmarks: {0:[0.45,0.12],11:[0.38,0.26],12:[0.54,0.26],13:[0.32,0.30],14:[0.65,0.22],15:[0.28,0.35],16:[0.78,0.18],23:[0.42,0.48],24:[0.52,0.48],25:[0.36,0.62],26:[0.60,0.58],27:[0.32,0.80],28:[0.65,0.72]} },
        "General Play": { landmarks: {0:[0.48,0.10],11:[0.40,0.24],12:[0.56,0.24],13:[0.34,0.32],14:[0.62,0.32],15:[0.30,0.38],16:[0.66,0.38],23:[0.43,0.46],24:[0.53,0.46],25:[0.40,0.64],26:[0.56,0.64],27:[0.37,0.84],28:[0.59,0.84]} },
    };
    const SKELETON_CONNECTIONS = [[11,12],[11,13],[13,15],[12,14],[14,16],[11,23],[12,24],[23,24],[23,25],[24,26],[25,27],[26,28],[0,11],[0,12]];

    function drawReferenceSkeleton(shotType) {
        const canvas = document.getElementById('refSkeletonCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const w = canvas.width, h = canvas.height;
        ctx.clearRect(0, 0, w, h);

        const ref = REFERENCE_POSES[shotType] || REFERENCE_POSES["General Play"];
        const pad = 20;

        // Draw connections
        ctx.strokeStyle = 'rgba(255,87,34,0.25)';
        ctx.lineWidth = 2;
        ctx.setLineDash([4, 4]);
        SKELETON_CONNECTIONS.forEach(([a, b]) => {
            const la = ref.landmarks[a], lb = ref.landmarks[b];
            if (la && lb) {
                ctx.beginPath();
                ctx.moveTo(pad + la[0] * (w - 2*pad), pad + la[1] * (h - 2*pad));
                ctx.lineTo(pad + lb[0] * (w - 2*pad), pad + lb[1] * (h - 2*pad));
                ctx.stroke();
            }
        });
        ctx.setLineDash([]);

        // Draw joints
        Object.entries(ref.landmarks).forEach(([idx, pos]) => {
            const x = pad + pos[0] * (w - 2*pad);
            const y = pad + pos[1] * (h - 2*pad);
            ctx.beginPath();
            ctx.arc(x, y, 4, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(255,87,34,0.35)';
            ctx.fill();
            ctx.strokeStyle = 'rgba(255,87,34,0.5)';
            ctx.lineWidth = 1.5;
            ctx.stroke();
        });

        // Label joints
        const labels = {0:'Head',11:'L',12:'R',15:'L',16:'R',27:'L',28:'R'};
        const labelNames = {0:'',11:'Shoulder',12:'Shoulder',15:'Wrist',16:'Wrist',27:'Ankle',28:'Ankle'};
        ctx.font = '9px sans-serif';
        ctx.fillStyle = 'rgba(255,87,34,0.5)';
        Object.entries(labels).forEach(([idx, side]) => {
            const pos = ref.landmarks[idx];
            if (pos) {
                const x = pad + pos[0] * (w - 2*pad);
                const y = pad + pos[1] * (h - 2*pad);
                ctx.fillText(side + ' ' + (labelNames[idx]||''), x + 7, y + 3);
            }
        });
    }

    let lastDetectedSkeleton = null;
    let analysisRequestId = 0;

    function clearDetectedSkeleton() {
        lastDetectedSkeleton = null;
        const canvas = document.getElementById('detectedSkeletonCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
    }

    function paintDetectedSkeleton(canvas, img, detected) {
        const rect = canvas.getBoundingClientRect();
        if (rect.width < 1 || rect.height < 1) return;

        // Keep the drawing buffer sharp on high-DPI screens.
        const dpr = window.devicePixelRatio || 1;
        const bufferWidth = Math.round(rect.width * dpr);
        const bufferHeight = Math.round(rect.height * dpr);
        if (canvas.width !== bufferWidth) canvas.width = bufferWidth;
        if (canvas.height !== bufferHeight) canvas.height = bufferHeight;

        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, rect.width, rect.height);

        // The preview uses object-fit: contain, so the visible photo can be
        // letterboxed inside the canvas. Map landmarks to the photo, not the box.
        const boxW = rect.width, boxH = rect.height;
        const naturalW = img.naturalWidth || 0, naturalH = img.naturalHeight || 0;
        let drawW = boxW, drawH = boxH, offsetX = 0, offsetY = 0;
        if (naturalW > 0 && naturalH > 0) {
            const scale = Math.min(boxW / naturalW, boxH / naturalH);
            drawW = naturalW * scale;
            drawH = naturalH * scale;
            offsetX = (boxW - drawW) / 2;
            offsetY = (boxH - drawH) / 2;
        }

        const lms = detected.landmarks;
        const conns = detected.connections;
        const px = (lm) => offsetX + lm.x * drawW;
        const py = (lm) => offsetY + lm.y * drawH;

        ctx.save();
        ctx.beginPath();
        ctx.rect(offsetX, offsetY, drawW, drawH);
        ctx.clip();

        // Draw connections
        ctx.strokeStyle = 'rgba(22,163,74,0.7)';
        ctx.lineWidth = 2.5;
        conns.forEach(([a, b]) => {
            const la = lms[String(a)], lb = lms[String(b)];
            if (la && lb && la.visibility > 0.3 && lb.visibility > 0.3) {
                ctx.beginPath();
                ctx.moveTo(px(la), py(la));
                ctx.lineTo(px(lb), py(lb));
                ctx.stroke();
            }
        });

        // Draw joints
        Object.entries(lms).forEach(([idx, lm]) => {
            if (lm.visibility > 0.3) {
                const x = px(lm), y = py(lm);
                ctx.beginPath();
                ctx.arc(x, y, 4, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(22,163,74,0.8)';
                ctx.fill();
                ctx.strokeStyle = '#fff';
                ctx.lineWidth = 1.5;
                ctx.stroke();
            }
        });

        ctx.restore();
    }

    function drawDetectedSkeleton(detected) {
        const canvas = document.getElementById('detectedSkeletonCanvas');
        const img = document.getElementById('previewImage');
        if (!canvas || !img || !detected) return;

        lastDetectedSkeleton = detected;
        const paintWhenReady = () => {
            // Ignore a load event from an older photo.
            if (lastDetectedSkeleton !== detected) return;
            paintDetectedSkeleton(canvas, img, detected);
        };

        if (img.complete && img.naturalWidth > 0) {
            paintWhenReady();
        } else {
            img.addEventListener('load', paintWhenReady, { once: true });
        }
    }

    window.addEventListener('resize', () => {
        if (lastDetectedSkeleton && !previewState.classList.contains('hidden')) {
            drawDetectedSkeleton(lastDetectedSkeleton);
        }
    });

    // Draw reference on load and shot change
    function updateRefSkeleton() {
        const shot = shotSelection.value;
        drawReferenceSkeleton(shot);
        const labels = {"Smash":"Jump Smash","Serve":"Short / Flick Serve","Cross Drop":"Cross Court Drop","Net Play":"Net Kill / Spin","General Play":"General Footwork Base"};
        if (refShotLabel) refShotLabel.textContent = 'Ideal: ' + (labels[shot] || shot);
        if (refShotName) refShotName.textContent = labels[shot] || shot;
    }
    shotSelection.addEventListener('change', updateRefSkeleton);
    updateRefSkeleton();

    // Drag & Drop
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => dropZone.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); }));
    dropZone.addEventListener('dragover', () => dropZone.classList.add('dragover'));
    ['dragleave', 'drop'].forEach(evt => dropZone.addEventListener(evt, () => dropZone.classList.remove('dragover')));

    dropZone.addEventListener('drop', (e) => handleFiles(e.dataTransfer.files));
    dropZone.addEventListener('click', (e) => { if (!e.target.closest('#clearPreview')) imageInput.click(); });
    dropZone.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            imageInput.click();
        }
    });
    imageInput.addEventListener('change', function() { handleFiles(this.files); });

    clearPreview.addEventListener('click', (e) => {
        e.stopPropagation();
        imageInput.value = '';
        previewState.classList.add('hidden');
        uploadState.classList.remove('hidden');
        dropZone.classList.remove('has-image');
        if (filenameEl) filenameEl.textContent = '';
        if (aiBadge) {
            aiBadge.textContent = '\u25CF READY FOR ANALYSIS';
            aiBadge.classList.remove('ready');
        }
        analysisRequestId++;
        processingState.classList.add('hidden');
        processingState.classList.remove('flex');
        scanLine.classList.add('hidden');
        clearDetectedSkeleton();
    });

    // ==========================================
    // CAMERA CAPTURE
    // ==========================================
    const cameraModal = document.getElementById('cameraModal');
    const cameraFeed = document.getElementById('cameraFeed');
    const cameraCanvas = document.getElementById('cameraCanvas');
    const captureBtn = document.getElementById('captureBtn');
    const closeCameraModal = document.getElementById('closeCameraModal');
    const captureFlash = document.getElementById('captureFlash');
    const cameraBtn = document.getElementById('cameraBtn');
    const browseBtn = document.getElementById('browseBtn');
    let cameraStream = null;

    browseBtn.addEventListener('click', (e) => { e.stopPropagation(); imageInput.click(); });

    cameraBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openCamera();
    });

    async function openCamera() {
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 960 } }
            });
            cameraFeed.srcObject = cameraStream;
            cameraModal.classList.remove('hidden');
            cameraModal.classList.add('flex');
        } catch (err) {
            alert("Camera access denied. Please allow camera permissions and try again.");
        }
    }

    function closeCamera() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(t => t.stop());
            cameraStream = null;
        }
        cameraFeed.srcObject = null;
        cameraModal.classList.add('hidden');
        cameraModal.classList.remove('flex');
    }

    closeCameraModal.addEventListener('click', closeCamera);
    cameraModal.addEventListener('click', (e) => { if (e.target === cameraModal) closeCamera(); });

    captureBtn.addEventListener('click', () => {
        const ctx = cameraCanvas.getContext('2d');
        cameraCanvas.width = cameraFeed.videoWidth;
        cameraCanvas.height = cameraFeed.videoHeight;
        ctx.drawImage(cameraFeed, 0, 0);

        // Flash effect
        captureFlash.style.opacity = '0.8';
        setTimeout(() => { captureFlash.style.opacity = '0'; }, 150);

        cameraCanvas.toBlob((blob) => {
            if (!blob) return;
            const file = new File([blob], 'capture_' + Date.now() + '.jpg', { type: 'image/jpeg' });
            closeCamera();

            // Show preview
            const reader = new FileReader();
            reader.onload = (ev) => {
                clearDetectedSkeleton();
                previewImage.src = ev.target.result;
                uploadState.classList.add('hidden');
                previewState.classList.remove('hidden');
                dropZone.classList.add('has-image');
                if (filenameEl) filenameEl.textContent = file.name + ' \u00B7 ' + (file.size/1024/1024).toFixed(2) + ' MB';
                if (aiBadge) {
                    aiBadge.textContent = '\u25CF FILE READY';
                    aiBadge.classList.add('ready');
                }
            };
            reader.readAsDataURL(file);
            uploadImage(file);
        }, 'image/jpeg', 0.92);
    });

    function handleFiles(files) {
        if (files.length > 0) {
            const file = files[0];
            if (file.size > 10485760) {
                alert("File is too large! Maximum allowed size is 10MB.");
                imageInput.value = "";
                return;
            }
            if (filenameEl) filenameEl.textContent = file.name + ' \u00B7 ' + (file.size/1024/1024).toFixed(2) + ' MB';
            if (aiBadge) {
                aiBadge.textContent = '\u25CF FILE READY';
                aiBadge.classList.add('ready');
            }
            const reader = new FileReader();
            reader.onload = (e) => {
                clearDetectedSkeleton();
                previewImage.src = e.target.result;
                uploadState.classList.add('hidden');
                previewState.classList.remove('hidden');
                dropZone.classList.add('has-image');
            };
            reader.readAsDataURL(file);
            uploadImage(file);
        }
    }

    function uploadImage(file) {
        clearDetectedSkeleton();
        const requestId = ++analysisRequestId;
        processingState.classList.remove('hidden');
        processingState.classList.add('flex');
        scanLine.classList.remove('hidden');

        let formData = new FormData();
        formData.append('action', 'analyze_image');
        formData.append('image_file', file);
        formData.append('shot_type', shotSelection.value);
        formData.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');

        fetch('/aimovementanalysis', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (requestId !== analysisRequestId) return;
            processingState.classList.remove('flex');
            processingState.classList.add('hidden');
            scanLine.classList.add('hidden');

            if (data.success) {
                const acc = data.data.accuracy;
                overallScore.textContent = acc;
                overallVerdict.textContent = data.data.verdict;

                const color = acc > 75 ? '#16a34a' : (acc > 50 ? '#d97706' : '#dc2626');
                mainGauge.style.background = `conic-gradient(${color} 0% ${acc}%, #e5eeff ${acc}% 100%)`;

                // Show detected shot type
                const detectedType = data.data.detected_shot_type || data.data.stroke_type;
                const detConf = data.data.detection_confidence || 0;
                const shotLabels = {"Smash":"Jump Smash","Serve":"Short / Flick Serve","Cross Drop":"Cross Court Drop","Net Play":"Net Kill / Spin","General Play":"General Footwork Base"};

                // Auto-switch dropdown to detected type
                const shotSelect = document.getElementById('shotSelection');
                if (shotSelect) {
                    for (let opt of shotSelect.options) {
                        if (opt.value === detectedType) { opt.selected = true; break; }
                    }
                    updateRefSkeleton();
                }

                // Similarity score
                const sim = data.data.similarity_score || 0;
                const simBar = document.getElementById('similarityBar');
                const simScore = document.getElementById('similarityScore');
                if (simBar) simBar.style.width = sim + '%';
                if (simScore) simScore.textContent = sim + '%';

                // Real sub-scores from AI engine
                const subs = data.data.sub_scores || {};
                const fW = subs.footwork || 0;
                const pW = subs.posture || 0;
                const sW = subs.swing || 0;

                document.getElementById('scoreFootwork').textContent = fW + '%';
                document.getElementById('barFootwork').style.width = fW + '%';

                document.getElementById('scorePosture').textContent = pW + '%';
                document.getElementById('barPosture').style.width = pW + '%';

                document.getElementById('scoreSwing').textContent = sW + '%';
                document.getElementById('barSwing').style.width = sW + '%';

                // Quality warnings
                const warnings = data.data.quality_warnings || [];
                const warnContainer = document.getElementById('qualityWarnings');
                if (warnings.length > 0 && warnContainer) {
                    warnContainer.classList.remove('hidden');
                    warnContainer.innerHTML = warnings.map(w =>
                        `<div class="ai-warn">
                            <span class="material-symbols-outlined">warning</span>
                            <span>${w}</span>
                        </div>`
                    ).join('');
                } else if (warnContainer) {
                    warnContainer.classList.add('hidden');
                }

                // Draw detected skeleton overlay
                if (data.data.detected_skeleton) {
                    drawDetectedSkeleton(data.data.detected_skeleton);
                }

                aiFeedbackText.innerHTML = `
                    <div class="ai-detected">
                        <span class="ai-detected-label">Detected Stroke</span>
                        <p class="ai-detected-name">${shotLabels[detectedType] || detectedType} <small>(${detConf}% confidence)</small></p>
                    </div>
                    <p class="ai-feedback-text">${data.data.feedback}</p>
                `;
            } else {
                alert(data.error);
            }
        }).catch(error => {
            if (requestId !== analysisRequestId) return;
            processingState.classList.remove('flex');
            processingState.classList.add('hidden');
            scanLine.classList.add('hidden');
            alert("Network error. Could not connect to AI engine.");
        });
    }
</script>

</body>
</html>
