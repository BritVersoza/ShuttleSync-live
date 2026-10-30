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
// AI CONFIGURATION (TC-AS-055 -> 060)
// ==========================================
$AI_DEFAULTS = ['confidence_threshold' => 0.450, 'angle_tolerance' => 45.00];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf_token();

    if ($_POST['action'] === 'save_ai_config') {
        try {
            $conf  = $_POST['confidence_threshold'] ?? '';
            $angle = $_POST['angle_tolerance'] ?? '';

            // Range validation (TC-AS-058)
            if (!is_numeric($conf) || (float)$conf < 0 || (float)$conf > 1) {
                throw new Exception("Confidence threshold must be a number between 0.00 and 1.00.");
            }
            if (!is_numeric($angle) || (float)$angle < 0 || (float)$angle > 90) {
                throw new Exception("Angle tolerance must be a number between 0 and 90 degrees.");
            }

            $conf  = round((float)$conf, 3);
            $angle = round((float)$angle, 2);

            try {
                $pdo->prepare("INSERT INTO ai_config (config_id, confidence_threshold, angle_tolerance, updated_at, updated_by)
                               VALUES (1, ?, ?, NOW(), ?)
                               ON DUPLICATE KEY UPDATE confidence_threshold = VALUES(confidence_threshold),
                                                       angle_tolerance = VALUES(angle_tolerance),
                                                       updated_at = NOW(),
                                                       updated_by = VALUES(updated_by)")
                    ->execute([$conf, $angle, $admin_name]);
            } catch (PDOException $e) {
                throw new Exception("AI configuration table is missing. Run database_admin_upgrade.sql first.");
            }

            $success_message = "AI configuration saved. New analyses will apply these thresholds.";
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }

    if ($_POST['action'] === 'reset_ai_config') {
        try {
            $pdo->prepare("UPDATE ai_config SET confidence_threshold = ?, angle_tolerance = ?, updated_at = NOW(), updated_by = ? WHERE config_id = 1")
                ->execute([$AI_DEFAULTS['confidence_threshold'], $AI_DEFAULTS['angle_tolerance'], $admin_name]);
            $success_message = "AI configuration reset to system defaults.";
        } catch (PDOException $e) {
            $error_message = "AI configuration table is missing. Run database_admin_upgrade.sql first.";
        }
    }
}

// Load current configuration (TC-AS-056)
$ai_config = $AI_DEFAULTS + ['updated_at' => null, 'updated_by' => null];
$ai_config_ready = true;
try {
    $cs = $pdo->query("SELECT confidence_threshold, angle_tolerance, updated_at, updated_by FROM ai_config WHERE config_id = 1");
    $row = $cs->fetch(PDO::FETCH_ASSOC);
    if ($row) $ai_config = $row;
} catch (PDOException $e) {
    $ai_config_ready = false;
}

// ==========================================
// FETCH REAL METRICS FOR AI LAB
// ==========================================

// 1. Total distinct players who have used the AI tool
$ai_users_stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM AI_Analysis_Logs");
$active_ai_users = (int)$ai_users_stmt->fetchColumn();

// 2. Total registered players
$players_stmt = $pdo->query("SELECT COUNT(*) FROM Users WHERE role = 'Player'");
$total_players = (int)$players_stmt->fetchColumn() ?: 1; // Fallback to 1 to prevent division by zero

// 3. Real Engagement Rate
$engagement_rate = round(($active_ai_users / $total_players) * 100, 1);

// 4. Fetch Top Performers (Highest Average Accuracy)
$leaderboard_stmt = $pdo->query("
    SELECT u.full_name, u.skill_level, ROUND(AVG(a.accuracy_score)) as avg_score, COUNT(a.log_id) as total_scans 
    FROM AI_Analysis_Logs a 
    JOIN Users u ON a.user_id = u.user_id 
    GROUP BY u.user_id 
    ORDER BY avg_score DESC 
    LIMIT 3
");
$top_performers = $leaderboard_stmt->fetchAll();

// 5. Fetch Heatmap Data (Most analyzed strokes)
$heatmap_stmt = $pdo->query("
    SELECT stroke_type, COUNT(*) as count 
    FROM AI_Analysis_Logs 
    GROUP BY stroke_type 
    ORDER BY count DESC
");
$stroke_stats_raw = $heatmap_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Calculate total scans to get percentages for the heatmap
$total_scans_stmt = $pdo->query("SELECT COUNT(*) FROM AI_Analysis_Logs");
$total_scans = (int)$total_scans_stmt->fetchColumn() ?: 1;

// Define default categories to ensure the UI boxes are always populated
$default_categories = ['Smash', 'Net Play', 'Cross Drop', 'Serve', 'Clear', 'Drive', 'Lift', 'Other'];
$heatmap_data = [];

foreach ($default_categories as $cat) {
    $count = $stroke_stats_raw[$cat] ?? 0;
    $pct = round(($count / $total_scans) * 100, 1);
    $heatmap_data[$cat] = $pct;
}

// Ensure any rogue "Other" categories get bundled into 'Other'
foreach ($stroke_stats_raw as $key => $count) {
    if (!in_array($key, $default_categories)) {
        $heatmap_data['Other'] += round(($count / $total_scans) * 100, 1);
    }
}

// Sort heatmap by percentage descending
arsort($heatmap_data);

// Full leaderboard is shared by the page, details view, and CSV export.
$full_leaderboard_stmt = $pdo->query("
    SELECT u.full_name, u.skill_level, ROUND(AVG(a.accuracy_score), 1) AS avg_score,
           COUNT(a.log_id) AS total_scans
    FROM AI_Analysis_Logs a
    JOIN Users u ON a.user_id = u.user_id
    GROUP BY u.user_id, u.full_name, u.skill_level
    ORDER BY avg_score DESC, total_scans DESC, u.full_name ASC
");
$full_leaderboard = $full_leaderboard_stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hi-power-ai-leaderboard-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Rank', 'Player', 'Skill Level', 'Average Accuracy', 'Total Scans']);
    foreach ($full_leaderboard as $idx => $player) {
        fputcsv($out, [$idx + 1, $player['full_name'], $player['skill_level'] ?? 'Player', $player['avg_score'], $player['total_scans']]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>AI Lab Engagement Insights | HI-POWER</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <style>
        /* Fill the viewport beside the fixed 260px admin sidebar. */
        html, body { min-height: 100%; }
        body { overflow-x: hidden; }
        #ai-lab-main {
            box-sizing: border-box;
            width: calc(100% - 260px);
            max-width: none !important;
            margin-left: 260px;
            margin-right: 0;
            min-width: 0;
            padding: clamp(16px, 2vw, 32px);
        }
        #ai-lab-main > * { min-width: 0; }
        #ai-lab-main .hp-page-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        #ai-lab-main .hp-ai-recommendation {
            background: linear-gradient(120deg, #080F17 0%, #101C29 72%, #172B3D 100%) !important;
            color: #FFFFFF !important;
            border: 1px solid rgba(255,255,255,.09);
        }
        #ai-lab-main .hp-ai-recommendation h2,
        #ai-lab-main .hp-ai-recommendation h4 { color: #FFFFFF !important; }
        #ai-lab-main .hp-ai-recommendation .hp-ai-description {
            color: #D8E2EF !important;
            line-height: 1.7;
        }
        #ai-lab-main .hp-ai-recommendation .hp-ai-impact {
            background: rgba(255,255,255,.07) !important;
            border: 1px solid rgba(255,255,255,.13);
            color: #FFFFFF !important;
        }
        #ai-lab-main .hp-ai-recommendation .hp-ai-muted { color: #AFC0D2 !important; }
        #ai-lab-main .hp-ai-recommendation .hp-ai-secondary {
            color: #FFFFFF !important;
            border-color: rgba(255,255,255,.35) !important;
            background: rgba(255,255,255,.04) !important;
        }
        #ai-lab-main .hp-ai-recommendation .hp-ai-secondary:hover {
            background: rgba(255,255,255,.12) !important;
            border-color: #FF5722 !important;
        }
        #ai-lab-main .hp-ai-recommendation .text-primary-fixed-dim { color: #FFB199 !important; }
        #ai-lab-main .hp-ai-recommendation .text-primary { color: #FF5722 !important; }
        #ai-lab-main .hp-ai-recommendation a.bg-primary { background: #FF5722 !important; color: #FFFFFF !important; }
        #ai-lab-main .grid, #ai-lab-main section, #ai-lab-main article { min-width: 0; }
        #ai-lab-main svg { max-width: 100%; }
        #ai-lab-main table { border-collapse: collapse; }
        #aiDetailAnalytics, #fullLeaderboard { scroll-margin-top: 24px; }
        @media (max-width: 1279px) {
            #ai-lab-main .hp-ai-recommendation { gap: 24px; }
            #ai-lab-main .hp-ai-impact { min-height: 180px; }
        }
        @media (max-width: 767px) {
            #ai-lab-main {
                width: 100%;
                margin-left: 0;
                padding: 76px 16px 24px;
            }
            #ai-lab-main > header { align-items: stretch; }
            #ai-lab-main .hp-page-actions { width: 100%; }
            #ai-lab-main .hp-page-actions > * { flex: 1 1 auto; justify-content: center; }
            #ai-lab-main .hp-ai-recommendation { padding: 22px !important; align-items: stretch; }
            #ai-lab-main .hp-ai-recommendation .hp-ai-impact { width: 100%; }
            #ai-lab-main .heatmap-cell { padding: 12px; }
            #ai-lab-main .heatmap-cell .text-2xl { font-size: 1.25rem; }
            #ai-lab-main footer { flex-direction: column; gap: 12px; }
        }
    </style>
</head>
<body class="bg-background text-on-surface min-h-screen">

<?php include __DIR__ . '/includes/admin_header.php'; ?>

    <main id="ai-lab-main" class="ml-0 md:ml-64 flex-grow p-gutter overflow-x-hidden">
        
        <header class="mb-margin-md pt-4 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
            <div>
                <h1 class="text-headline-xl font-headline-xl text-on-surface mb-2">AI Lab Insights</h1>
                <p class="text-body-lg font-body-lg text-on-surface-variant max-w-2xl">
                    Monitoring precision and performance analytics for the AI Coach ecosystem. 
                </p>
            </div>
            <div class="hp-page-actions no-print">
                <a href="ai_lab.php?export=csv" class="hp-action hp-action--primary"><span class="material-symbols-outlined">download</span> Export CSV</a>
                <button type="button" onclick="window.print()" class="hp-action"><span class="material-symbols-outlined">print</span> Print</button>
                <button type="button" onclick="document.getElementById('aiDetailAnalytics').scrollIntoView({behavior:'smooth'})" class="hp-action"><span class="material-symbols-outlined">query_stats</span> Detail Analytics</button>
            </div>
        </header>

        <?php if (!empty($success_message)): ?>
            <div class="mb-6 p-4 bg-success/10 border border-success/30 rounded-xl flex items-center gap-3">
                <span class="material-symbols-outlined text-success">check_circle</span>
                <p class="text-success text-sm font-semibold"><?php echo htmlspecialchars($success_message); ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="mb-6 p-4 bg-danger/10 border border-danger/30 rounded-xl flex items-center gap-3">
                <span class="material-symbols-outlined text-danger">error</span>
                <p class="text-danger text-sm font-semibold"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <!-- AI Configuration (TC-AS-055 -> 060) -->
        <section class="mb-gutter bg-surface-container-lowest kinetic-border rounded-xl p-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary">tune</span>
                    </div>
                    <div>
                        <h2 class="text-headline-md font-headline-md text-on-surface">Model Configuration</h2>
                        <p class="text-label-md font-label-md text-on-surface-variant">Detection thresholds applied to every new swing analysis.</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[11px] font-bold text-outline uppercase tracking-widest">Last updated</p>
                    <p class="text-sm font-semibold text-on-surface">
                        <?php echo !empty($ai_config['updated_at']) ? date('M d, Y H:i', strtotime($ai_config['updated_at'])) : 'System default'; ?>
                        <?php echo !empty($ai_config['updated_by']) ? ' · ' . htmlspecialchars($ai_config['updated_by']) : ''; ?>
                    </p>
                </div>
            </div>

            <?php if (!$ai_config_ready): ?>
                <div class="p-4 bg-warning/10 border border-warning/30 rounded-xl text-sm text-warning">
                    <strong>Not configured.</strong> Run <code class="bg-warning/20 px-1.5 py-0.5 rounded">database_admin_upgrade.sql</code> once via phpMyAdmin to enable AI configuration.
                </div>
            <?php else: ?>
                <form method="POST" action="ai_lab" class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end">
                    <?php echo get_csrf_input(); ?>
                    <input type="hidden" name="action" value="save_ai_config">

                    <div class="md:col-span-4">
                        <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-widest mb-2">Confidence Threshold</label>
                        <input type="number" name="confidence_threshold" step="0.001" min="0" max="1"
                               value="<?php echo htmlspecialchars(number_format((float)$ai_config['confidence_threshold'], 3, '.', '')); ?>"
                               class="w-full h-11 px-4 bg-surface border border-outline-variant/60 rounded-xl text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <p class="text-[11px] text-outline mt-1.5">0.00 – 1.00. Lower values accept more landmarks (noisier); higher values are stricter.</p>
                    </div>

                    <div class="md:col-span-4">
                        <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-widest mb-2">Angle Tolerance (°)</label>
                        <input type="number" name="angle_tolerance" step="0.1" min="0" max="90"
                               value="<?php echo htmlspecialchars(number_format((float)$ai_config['angle_tolerance'], 2, '.', '')); ?>"
                               class="w-full h-11 px-4 bg-surface border border-outline-variant/60 rounded-xl text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <p class="text-[11px] text-outline mt-1.5">0 – 90°. Allowed joint-angle deviation before a motion is flagged.</p>
                    </div>

                    <div class="md:col-span-4 flex gap-3">
                        <button type="submit" class="flex-1 h-11 bg-primary text-on-primary rounded-xl font-bold text-sm flex items-center justify-center gap-2 hover:brightness-110 transition-all">
                            <span class="material-symbols-outlined text-[18px]">save</span> Save Configuration
                        </button>
                    </div>
                </form>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-5 pt-5 border-t border-outline-variant/30">
                    <p class="text-[12px] text-on-surface-variant">
                        Defaults: confidence <strong><?php echo number_format($AI_DEFAULTS['confidence_threshold'], 3); ?></strong>,
                        angle tolerance <strong><?php echo number_format($AI_DEFAULTS['angle_tolerance'], 2); ?>°</strong>.
                    </p>
                    <form method="POST" action="ai_lab" onsubmit="return confirm('Reset AI thresholds to system defaults?');">
                        <?php echo get_csrf_input(); ?>
                        <input type="hidden" name="action" value="reset_ai_config">
                        <button type="submit" class="h-10 px-4 border border-outline-variant/60 rounded-xl text-xs font-bold text-on-surface hover:bg-surface-container-high transition-all flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px]">restart_alt</span> Reset to Default
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-12 gap-gutter mb-gutter min-w-0">
            
            <div class="xl:col-span-4 bg-surface-container-lowest kinetic-border rounded-xl p-6 relative overflow-hidden group min-w-0">
                <div class="absolute top-0 right-0 p-4 opacity-10 transition-opacity group-hover:opacity-20 text-primary">
                    <span class="material-symbols-outlined !text-6xl">show_chart</span>
                </div>
                <p class="text-label-md font-label-md text-on-surface-variant uppercase tracking-wider mb-1">Coach Engagement Rate</p>
                <div class="flex items-baseline gap-2 mb-4">
                    <span class="text-headline-xl font-headline-xl text-primary"><?php echo $engagement_rate; ?>%</span>
                    <span class="text-label-sm font-label-sm text-success flex items-center bg-success/10 px-2 py-0.5 rounded-full">
                        <span class="material-symbols-outlined text-[14px]">trending_up</span> Live
                    </span>
                </div>
                <div class="w-full bg-surface-container h-2 rounded-full mb-6 overflow-hidden">
                    <div class="bg-primary h-full rounded-full transition-all duration-1000" style="width: <?php echo $engagement_rate; ?>%; box-shadow: 0 0 12px rgba(0, 102, 138, 0.4);"></div>
                </div>
                <p class="text-label-sm font-label-sm text-outline">
                    <strong><?php echo $active_ai_users; ?></strong> out of <strong><?php echo $total_players; ?></strong> registered players are actively utilizing AI swing analysis.
                </p>
            </div>

            <div class="xl:col-span-8 bg-surface-container-lowest kinetic-border rounded-xl p-6 min-w-0">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <p class="text-label-md font-label-md text-on-surface-variant uppercase tracking-wider">AI Coach Feedback Loops</p>
                        <h3 class="text-headline-md font-headline-md">Total Completed Analyses</h3>
                    </div>
                    <div class="flex gap-2">
                        <span class="flex items-center gap-1 text-label-sm font-label-sm text-primary font-bold">
                            <span class="w-3 h-3 rounded-full bg-primary"></span> Live Usage Trend
                        </span>
                    </div>
                </div>
                <div class="h-48 w-full relative">
                    <svg class="w-full h-full" preserveaspectratio="none" viewbox="0 0 1000 200">
                        <defs>
                            <lineargradient id="chartGradient" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#00668a" stop-opacity="0.2"></stop>
                                <stop offset="100%" stop-color="#00668a" stop-opacity="0"></stop>
                            </lineargradient>
                        </defs>
                        <path d="M0,180 Q50,170 100,160 T200,140 T300,150 T400,110 T500,120 T600,80 T700,70 T800,40 T900,50 T1000,20 L1000,200 L0,200 Z" fill="url(#chartGradient)"></path>
                        <path d="M0,180 Q50,170 100,160 T200,140 T300,150 T400,110 T500,120 T600,80 T700,70 T800,40 T900,50 T1000,20" fill="none" stroke="#00668a" stroke-linecap="round" stroke-width="3"></path>
                    </svg>
                    <div class="absolute inset-0 flex justify-between items-end px-2 pointer-events-none opacity-20">
                        <div class="h-full w-px bg-outline-variant"></div>
                        <div class="h-full w-px bg-outline-variant"></div>
                        <div class="h-full w-px bg-outline-variant"></div>
                        <div class="h-full w-px bg-outline-variant"></div>
                        <div class="h-full w-px bg-outline-variant"></div>
                        <div class="h-full w-px bg-outline-variant"></div>
                        <div class="h-full w-px bg-outline-variant"></div>
                    </div>
                </div>
                <div class="flex justify-between mt-4 text-label-sm font-label-sm text-on-surface-variant uppercase font-bold tracking-widest">
                    <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span>
                </div>
            </div>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-12 gap-gutter min-w-0">
            
            <div class="xl:col-span-7 bg-surface-container-lowest kinetic-border rounded-xl p-6 min-w-0">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h3 class="text-headline-md font-headline-md text-on-surface">Movement Analysis Heatmap</h3>
                        <p class="text-label-md font-label-md text-on-surface-variant">Popularity density of biomechanical movement tracking</p>
                    </div>
                    <div class="bg-primary-container/20 text-primary border border-primary/30 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest flex items-center gap-1">
                        <span class="w-2 h-2 bg-primary rounded-full animate-pulse"></span> DB Synced
                    </div>
                </div>
                
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <?php 
                    $colors = [
                        'bg-primary text-white shadow-lg',
                        'bg-primary/80 text-white',
                        'bg-primary/70 text-white',
                        'bg-primary/60 text-white',
                        'bg-surface-variant text-on-surface-variant',
                        'bg-surface-variant/70 text-on-surface-variant',
                        'bg-surface-container text-outline',
                        'bg-surface-container text-outline'
                    ];
                    $i = 0;
                    foreach ($heatmap_data as $category => $pct): 
                        $color_class = $colors[$i] ?? 'bg-surface-container text-outline';
                        $i++;
                    ?>
                    <div class="heatmap-cell <?php echo $color_class; ?> min-h-[120px] rounded-lg p-4 flex flex-col justify-end cursor-default border border-outline-variant/30">
                        <span class="text-[10px] font-bold uppercase tracking-widest break-words"><?php echo htmlspecialchars($category); ?></span>
                        <span class="text-2xl font-bold mt-1"><?php echo $pct; ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="xl:col-span-5 bg-surface-container-lowest kinetic-border rounded-xl p-6 overflow-hidden flex flex-col min-w-0">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-headline-md font-headline-md text-on-surface">Top AI Lab Performers</h3>
                    <span class="material-symbols-outlined text-outline">emoji_events</span>
                </div>
                
                <div class="space-y-4 flex-grow">
                    <?php if(empty($top_performers)): ?>
                        <div class="text-center py-10 text-on-surface-variant italic border border-dashed border-outline-variant rounded-xl">
                            No AI Lab data yet. Test the system to generate leaders.
                        </div>
                    <?php else: ?>
                        <?php foreach($top_performers as $index => $player): 
                            $rank = $index + 1;
                            // Generate a consistent dummy avatar based on user ID or name
                            $seed = crc32($player['full_name']);
                            $avatar = "https://picsum.photos/seed/{$seed}/100/100";
                            
                            // Visuals for 1st, 2nd, 3rd
                            if($rank == 1) { $rank_bg = "bg-primary/10 text-primary"; $border = "border-2 border-primary"; }
                            elseif($rank == 2) { $rank_bg = "bg-outline-variant/20 text-outline"; $border = "border border-outline-variant"; }
                            else { $rank_bg = "bg-outline-variant/20 text-outline"; $border = "border border-outline-variant"; }
                        ?>
                        <div class="flex items-center justify-between p-3 rounded-lg hover:bg-surface-container-low transition-colors group border border-transparent hover:border-outline-variant/50">
                            <div class="flex items-center gap-4">
                                <div class="w-8 h-8 rounded-full <?php echo $rank_bg; ?> flex items-center justify-center font-bold text-sm">
                                    <?php echo $rank; ?>
                                </div>
                                <div class="w-10 h-10 rounded-full overflow-hidden <?php echo $border; ?>">
                                    <img alt="Athlete Avatar" class="w-full h-full object-cover" src="<?php echo $avatar; ?>"/>
                                </div>
                                <div>
                                    <p class="font-bold text-on-surface font-body-md truncate max-w-[150px]"><?php echo htmlspecialchars($player['full_name']); ?></p>
                                    <p class="text-[10px] text-primary uppercase font-bold tracking-widest"><?php echo htmlspecialchars($player['skill_level'] ?? 'Player'); ?></p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-headline-md text-primary font-bold text-xl"><?php echo $player['avg_score']; ?> pts</p>
                                <p class="text-[10px] text-outline uppercase font-bold tracking-widest"><?php echo $player['total_scans']; ?> Scans</p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <button type="button" onclick="document.getElementById('fullLeaderboard').classList.toggle('hidden'); document.getElementById('fullLeaderboard').scrollIntoView({behavior:'smooth',block:'start'});" class="w-full mt-6 py-3 border border-outline-variant/50 rounded-lg text-label-md font-bold text-on-surface hover:bg-surface-bright transition-all active:scale-95 shadow-sm">View Full Leaderboard <span aria-hidden="true">→</span></button>
            </div>
        </section>

        <section class="hp-ai-recommendation mt-gutter rounded-2xl p-margin-md flex flex-col md:flex-row items-center gap-8 relative overflow-hidden shadow-xl">
            <div class="absolute inset-0 opacity-5 pointer-events-none">
                <svg height="100%" width="100%" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <pattern height="40" id="grid" patternunits="userSpaceOnUse" width="40">
                            <path d="M 40 0 L 0 0 0 40" fill="none" stroke="white" stroke-width="1"></path>
                        </pattern>
                    </defs>
                    <rect fill="url(#grid)" height="100%" width="100%"></rect>
                </svg>
            </div>
            
            <div class="relative z-10 flex-1">
                <div class="inline-flex items-center gap-2 px-3 py-1 bg-primary/20 border border-primary/30 rounded-full text-primary-fixed-dim text-[10px] font-bold uppercase tracking-widest mb-4">
                    <span class="material-symbols-outlined text-[14px] animate-pulse-soft">auto_awesome</span> AI Recommended Action
                </div>
                <h2 class="text-headline-lg font-headline-lg mb-4">Improve "Smash" Precision</h2>
                <p class="hp-ai-description text-body-lg max-w-xl mb-6">
                    System analytics indicate a 22% drop in smash accuracy for members during evening sessions. Consider deploying an automated "Power Hour" notification with swing correction tips for users booking 6 PM – 9 PM slots.
                </p>
                <div class="flex flex-col sm:flex-row gap-4">
                    <a href="announcements.php" class="bg-primary text-on-primary px-8 py-3 rounded-lg font-bold hover:brightness-110 active:scale-95 transition-all shadow-md text-center">Execute Campaign</a>
                    <button type="button" onclick="document.getElementById('aiDetailAnalytics').scrollIntoView({behavior:'smooth',block:'start'});" class="hp-ai-secondary bg-transparent border px-8 py-3 rounded-lg hover:bg-white/10 transition-all font-bold">View Detail Analytics</button>
                </div>
            </div>
            
            <div class="hp-ai-impact relative z-10 w-full md:w-1/3 min-h-[230px] rounded-xl p-6 backdrop-blur-md flex flex-col justify-center items-center text-center">
                <span class="material-symbols-outlined !text-6xl text-primary mb-4">batch_prediction</span>
                <h4 class="text-xl font-bold mb-1">Projected Impact</h4>
                <p class="text-headline-xl font-headline-xl text-primary-fixed-dim font-black">+14.2%</p>
                <p class="hp-ai-muted text-xs uppercase tracking-widest font-bold mt-2">Estimated user retention lift</p>
            </div>
        </section>
        
        <footer class="mt-margin-md pt-8 border-t border-outline-variant flex justify-between text-[12px] font-bold tracking-widest text-outline uppercase pb-8">
            <p>© 2024 HI-POWER Technologies.</p>
            <div class="flex gap-6">
                <a class="hover:text-primary transition-colors" href="analytics.php">System Status</a>
                <a class="hover:text-primary transition-colors" href="/support.php?tab=contact">Support</a>
            </div>
        </footer>

    
        <section id="aiDetailAnalytics" class="mt-8 bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
                <div><p class="text-xs font-bold uppercase tracking-widest text-slate-500">Performance breakdown</p>
                <h2 class="text-xl font-bold text-slate-900 mt-1">Detailed AI Analytics</h2>
                <p class="text-sm text-slate-500 mt-1">Live figures calculated from AI_Analysis_Logs.</p></div>
                <span class="inline-flex items-center rounded-full bg-orange-50 text-orange-700 px-3 py-1 text-xs font-bold">All time</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-xs text-slate-500 font-semibold">Total AI scans</p><p class="text-2xl font-bold text-[#FF5722] mt-1"><?php echo number_format($total_scans); ?></p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-xs text-slate-500 font-semibold">Players using AI</p><p class="text-2xl font-bold text-[#FF5722] mt-1"><?php echo number_format($active_ai_users); ?></p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-xs text-slate-500 font-semibold">Player engagement</p><p class="text-2xl font-bold text-[#FF5722] mt-1"><?php echo number_format($engagement_rate, 1); ?>%</p></div>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-left min-w-[420px]"><thead><tr><th class="p-3">Stroke type</th><th class="p-3">Scans</th><th class="p-3">Share</th></tr></thead><tbody>
            <?php foreach ($heatmap_data as $category => $pct): $count = (int)($stroke_stats_raw[$category] ?? 0); ?>
                <tr class="border-t border-slate-100"><td class="p-3 font-semibold"><?php echo htmlspecialchars($category); ?></td><td class="p-3"><?php echo number_format($count); ?></td><td class="p-3"><div class="flex items-center gap-3"><div class="h-2 w-28 bg-slate-100 rounded-full overflow-hidden"><div class="h-full rounded-full bg-[#FF5722]" style="width:<?php echo min(100, max(0, $pct)); ?>%"></div></div><span class="text-sm font-semibold"><?php echo number_format($pct, 1); ?>%</span></div></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section id="fullLeaderboard" class="hidden mt-8 bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
                <div><h2 class="text-xl font-bold text-slate-900">Full AI Leaderboard</h2><p class="text-sm text-slate-500 mt-1">Ranked by average accuracy, then total scans.</p></div>
                <a href="ai_lab.php?export=csv" class="hp-action hp-action--primary no-print"><span class="material-symbols-outlined">download</span> Export CSV</a>
            </div>
            <div class="overflow-x-auto"><table class="w-full text-left min-w-[620px]"><thead><tr><th class="p-3">Rank</th><th class="p-3">Player</th><th class="p-3">Skill level</th><th class="p-3">Average accuracy</th><th class="p-3">Scans</th></tr></thead><tbody>
            <?php if (!$full_leaderboard): ?><tr><td colspan="5" class="p-6 text-center text-slate-500">No leaderboard data available yet.</td></tr><?php endif; ?>
            <?php foreach ($full_leaderboard as $idx => $player): ?><tr class="border-t border-slate-100"><td class="p-3 font-bold text-[#FF5722]">#<?php echo $idx + 1; ?></td><td class="p-3 font-semibold"><?php echo htmlspecialchars($player['full_name']); ?></td><td class="p-3"><?php echo htmlspecialchars($player['skill_level'] ?? 'Player'); ?></td><td class="p-3 font-bold"><?php echo number_format((float)$player['avg_score'], 1); ?> pts</td><td class="p-3"><?php echo number_format((int)$player['total_scans']); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>

</main>
</div>

<script>
    document.querySelectorAll('button').forEach(btn => {
        btn.addEventListener('mousedown', () => btn.classList.add('scale-95'));
        btn.addEventListener('mouseup', () => btn.classList.remove('scale-95'));
        btn.addEventListener('mouseleave', () => btn.classList.remove('scale-95'));
    });
</script>

</body>
</html>