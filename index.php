<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/database_connect.php';

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_name    = $is_logged_in ? ($_SESSION['full_name'] ?? '') : '';
$is_admin     = $is_logged_in && in_array($_SESSION['role'] ?? '', ['Admin', 'Head Manager'], true);

$total_sessions = 0;
$registered_players = 0;
$courts = [];
if (isset($pdo)) {
    try {
        $total_sessions = (int) $pdo->query("SELECT COUNT(*) FROM Bookings WHERE status IN ('Confirmed','Completed')")->fetchColumn();
    } catch (PDOException $e) {
        $total_sessions = 0;
    }
    try {
        $registered_players = (int) $pdo->query("SELECT COUNT(*) FROM Users WHERE role = 'Player'")->fetchColumn();
    } catch (PDOException $e) {
        $registered_players = 0;
    }
    try {
        $courts = $pdo->query("SELECT name, status FROM Courts ORDER BY court_id ASC LIMIT 8")->fetchAll();
    } catch (PDOException $e) {
        $courts = [];
    }
}
if (empty($courts)) {
    for ($i = 1; $i <= 8; $i++) {
        $courts[] = ['name' => "Court {$i} - " . ($i === 1 ? 'Premium' : 'Standard'), 'status' => 'Available'];
    }
}

// ---------------------------------------------------------------
// Homepage photos: picked at random (a different set on every visit)
// from the pictures uploaded to /img/courts. Large landscape photos
// (1200px+ wide) are preferred so the hero stays sharp; anything else
// is only used if there aren't enough big ones.
// ---------------------------------------------------------------
function pick_site_images(string $dir, string $urlBase, int $count = 3): array {
    if (!is_dir($dir)) return [];
    $names = @scandir($dir) ?: [];
    shuffle($names);
    $good = [];
    $any  = [];
    foreach ($names as $n) {
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $n)) continue;
        $path = $dir . '/' . $n;
        if (!is_file($path)) continue;
        $info = @getimagesize($path);
        if (!$info) continue;
        $url = $urlBase . rawurlencode($n);
        $any[] = $url;
        if ($info[0] >= 1200 && $info[0] >= $info[1]) $good[] = $url;
        if (count($good) >= $count) break;
    }
    return array_slice(array_values(array_unique(array_merge($good, $any))), 0, $count);
}
$site_imgs   = pick_site_images(__DIR__ . '/img/courts', '/img/courts/', 3);
$hero_img    = $site_imgs[0] ?? 'badminton-hero.png';
$feature_img = $site_imgs[1] ?? 'badminton-detail.png';
$cta_img     = $site_imgs[2] ?? ($site_imgs[0] ?? 'badminton-hero.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="theme-color" content="#080f17" />
  <title>HI-POWER Badminton Court</title>
  <link rel="preload" as="image" href="<?php echo htmlspecialchars($hero_img, ENT_QUOTES); ?>" fetchpriority="high">
  <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
  <style>
    :root {
      --orange: #ff5722;
      --orange-dark: #ed4817;
      --dark: #080f17;
      --dark-2: #101c29;
      --navy: #14264a;
      --white: #fff;
      --page: #f4f6f8;
      --muted: #64748b;
      --line: #e2e8f0;
      --green: #087f5b;
      --amber: #f59e0b;
      --radius: 13px;
      --shadow: 0 8px 24px rgba(15, 23, 42, .06);
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      margin: 0;
      font-family: "Manrope", sans-serif;
      color: var(--navy);
      background: var(--page);
      line-height: 1.55;
    }
    a { color: inherit; text-decoration: none; }
    button, input { font: inherit; }
    button, .btn { cursor: pointer; }
    .container { width: min(1120px, calc(100% - 48px)); margin-inline: auto; }
    /* Footer brand (kept: the footer still uses it) */
    .brand { display: inline-flex; align-items: center; gap: 10px; }
    .brand-mark { color: var(--orange); font-size: 34px; transform: skew(-12deg); }
    .brand-copy { line-height: 1.02; }
    .brand-copy strong { display: block; font-size: 20px; font-weight: 800; letter-spacing: -.5px; }
    .brand-copy small { display: block; font-size: 8px; letter-spacing: 2px; font-weight: 700; margin-top: 3px; }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 9px; border-radius: 10px; padding: 10px 18px; font-size: 12px; font-weight: 700; border: 1px solid transparent; transition: .2s ease; white-space: nowrap; }
    .btn-orange { background: var(--orange); color: white; box-shadow: 0 5px 14px rgba(255,87,34,.14); }
    .btn-orange:hover { background: var(--orange-dark); transform: translateY(-1px); }
    .btn-outline { color: white; background: rgba(255,255,255,.02); border-color: rgba(255,255,255,.45); }
    .btn-outline:hover { border-color: white; background: rgba(255,255,255,.08); }

    /* Hero: a real <img> (not a CSS background) so the browser renders the photo at full sharpness. */
    .hero {
      min-height: 360px; color: white; position: relative; display: flex; align-items: center; overflow: hidden;
      isolation: isolate; background: #101c29;
    }
    .hero-bg {
      position: absolute; inset: 0; width: 100%; height: 100%; z-index: -2;
      object-fit: cover; object-position: center 56%;
      pointer-events: none;
    }
    .hero::after {
      content: ""; position: absolute; inset: 0; z-index: -1; pointer-events: none;
      background:
        linear-gradient(90deg, rgba(4,12,20,.94) 0%, rgba(4,12,20,.78) 33%, rgba(4,12,20,.16) 74%, rgba(4,12,20,.06) 100%),
        linear-gradient(0deg, rgba(3,12,18,.2), rgba(3,12,18,.06));
    }
    .hero::before { content: ""; position: absolute; inset: 0; background: linear-gradient(115deg, transparent 0 75%, rgba(255,87,34,.06) 75% 76%, transparent 76%); pointer-events: none; }
    .hero-content { position: relative; z-index: 1; padding: 45px 0 50px; max-width: 470px; }
    .eyebrow { color: var(--orange); font-size: 10px; font-weight: 800; letter-spacing: 1.7px; text-transform: uppercase; }
    .hero h1 { margin: 10px 0 14px; font-size: clamp(36px, 4.7vw, 48px); line-height: 1.06; letter-spacing: -1.8px; font-weight: 800; color: #fff; }
    .hero h1 span { color: var(--orange); }
    .hero p { max-width: 390px; color: #e1e7ed; font-size: 13px; line-height: 1.55; margin: 0 0 17px; }
    .hero-actions { display: flex; gap: 14px; flex-wrap: wrap; }
    .hero-actions .btn { min-width: 146px; }
    .stats { background: #fff; border-bottom: 1px solid #e8edf2; }
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); padding: 27px 0 24px; }
    .stat { text-align: center; position: relative; }
    .stat:not(:last-child)::after { content: ""; position: absolute; right: 0; top: 6px; height: 62px; width: 1px; background: #d6dee8; }
    .stat i { font-size: 22px; color: var(--dark); margin-bottom: 4px; }
    .stat strong { display: block; font-size: 25px; line-height: 1.25; font-weight: 800; color: #0d2146; }
    .stat span { font-size: 12px; color: #425574; }
    section { padding: 28px 0; }
    .section-kicker { display: flex; align-items: center; gap: 9px; color: var(--orange); font-size: 10px; font-weight: 800; letter-spacing: 1.3px; text-transform: uppercase; margin-bottom: 5px; }
    .section-kicker::before { content: ""; width: 27px; height: 2px; background: var(--orange); }
    .section-title { font-size: clamp(23px, 3vw, 27px); letter-spacing: -.8px; line-height: 1.2; margin: 0 0 17px; color: #111827; font-weight: 800; }
    .features-layout { display: grid; grid-template-columns: 1fr 230px; gap: 30px; align-items: end; }
    .feature-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .feature-card { background: white; border: 1px solid #e4e9ef; border-radius: var(--radius); padding: 18px 19px; min-height: 184px; box-shadow: var(--shadow); }
    .feature-icon { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 50%; background: #f0f2f7; color: var(--navy); font-size: 20px; margin-bottom: 9px; }
    .feature-icon.orange { color: var(--orange); background: #fff0ea; }
    .feature-card h3 { font-size: 14px; margin: 0 0 3px; color: #111827; }
    .feature-card p { font-size: 11px; line-height: 1.5; color: #53647e; margin: 0 0 13px; }
    .text-link { font-size: 11px; font-weight: 700; color: var(--orange); }
    .feature-photo { min-height: 204px; height: 100%; border-radius: 13px; overflow: hidden; background: #101c29; position: relative; }
    .feature-photo img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; image-rendering: auto; }
    .feature-photo::before { content: ""; position: absolute; inset: 0; z-index: 1; pointer-events: none; background: linear-gradient(135deg, rgba(8,15,23,.06), rgba(8,15,23,.16)); }
    .feature-photo::after { content: ""; position: absolute; inset: 0; z-index: 2; border: 1px solid rgba(255,255,255,.12); border-radius: inherit; pointer-events: none; }
    .benefits-availability { padding-top: 0; }
    .panel { background: linear-gradient(135deg, #f9fafb, #eef2f5); border-radius: 14px; padding: 22px 26px; display: grid; grid-template-columns: .9fr 1.1fr; gap: 30px; }
    .panel .section-title { color: #102348; font-size: 22px; max-width: 280px; margin-bottom: 17px; }
    .benefit-list { display: grid; gap: 14px; }
    .benefit { display: grid; grid-template-columns: 44px 1fr; gap: 13px; align-items: start; }
    .benefit-icon { width: 39px; height: 39px; border-radius: 50%; background: #f4e9e4; color: #182333; display: grid; place-items: center; font-size: 17px; }
    .benefit:nth-child(2) .benefit-icon { color: var(--orange); }
    .benefit:nth-child(3) .benefit-icon { background: #e8edff; color: #3047a6; }
    .benefit:nth-child(4) .benefit-icon { background: #f0e7f8; color: #7e22ce; }
    .benefit h3 { font-size: 12px; margin: 1px 0 3px; color: #111827; }
    .benefit p { font-size: 10px; color: #596a82; margin: 0; max-width: 240px; line-height: 1.55; }
    .availability { background: #f1f4f6; border-radius: 11px; padding: 13px; align-self: center; }
    .availability-heading { display: flex; align-items: center; gap: 8px; font-size: 9px; letter-spacing: 1px; text-transform: uppercase; color: #475569; margin: 0 0 10px 6px; font-weight: 800; }
    .availability-heading::before { content: ""; width: 7px; height: 7px; background: var(--green); border-radius: 50%; }
    .court-row { display: grid; grid-template-columns: minmax(100px, 1fr) auto 14px; align-items: center; gap: 12px; min-height: 32px; padding: 6px 12px; background: white; border-bottom: 1px solid #edf0f3; font-size: 10px; }
    .court-row:first-of-type { border-radius: 6px 6px 0 0; }
    .court-row:last-child { border-radius: 0 0 6px 6px; border-bottom: 0; }
    .court-name { color: #17243c; font-weight: 600; }
    .status { display: inline-flex; align-items: center; gap: 6px; color: #47706b; white-space: nowrap; }
    .status::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--green); }
    .status.maintenance { color: #b9770e; }
    .status.maintenance::before { background: var(--amber); }
    .court-row i { color: #8190a5; font-size: 10px; }
    .cta { padding-top: 0; }
    .cta-banner { min-height: 92px; border-radius: 12px; color: white; padding: 18px 30px; display: flex; align-items: center; justify-content: space-between; gap: 24px; background:
      linear-gradient(90deg, rgba(8,15,23,.97), rgba(8,15,23,.82)),
      var(--cta-img, url("badminton-hero.png")) center 65% / cover no-repeat, var(--dark); }
    .cta-banner h2 { font-size: 21px; margin: 0 0 3px; letter-spacing: -.5px; }
    .cta-banner p { font-size: 10px; color: #cbd5e1; max-width: 350px; margin: 0; line-height: 1.5; }
    .cta-actions { display: flex; gap: 12px; flex-shrink: 0; }
    footer { margin-top: 0; background: var(--dark); color: #e5e7eb; padding: 24px 0 16px; }
    .footer-grid { display: grid; grid-template-columns: 1.2fr .8fr .9fr 1.2fr; gap: 40px; padding-bottom: 20px; }
    .footer-brand .brand { min-width: 0; gap: 8px; }
    .footer-brand .brand-mark { font-size: 29px; }
    .footer-brand .brand-copy strong { font-size: 17px; }
    .footer-brand .brand-copy small { font-size: 6px; }
    .footer-brand p { color: #aab4c2; font-size: 10px; max-width: 180px; line-height: 1.6; margin: 11px 0; }
    .socials { display: flex; gap: 13px; font-size: 13px; }
    .socials a:hover { color: var(--orange); }
    .footer-col h3 { color: white; font-size: 11px; margin: 3px 0 9px; }
    .footer-col a { display: block; color: #b5bfcc; font-size: 10px; margin: 5px 0; }
    .footer-col a:hover { color: var(--orange); }
    .footer-col p { color: #aab4c2; font-size: 10px; margin: 0 0 8px; }
    .copyright { border-top: 1px solid #2a3542; padding-top: 12px; color: #aab4c2; font-size: 9px; }
    .chat-widget { position: fixed; z-index: 60; bottom: 20px; right: 22px; border: 0; background: white; color: #111827; border-radius: 28px; padding: 11px 16px; box-shadow: 0 5px 22px rgba(0,0,0,.2); font-size: 11px; font-weight: 800; display: flex; align-items: center; gap: 9px; }
    .chat-widget i { color: var(--dark); }
    .chat-dot { position: absolute; right: -2px; top: -3px; width: 10px; height: 10px; background: var(--orange); border-radius: 50%; }
    .toast { position: fixed; left: 50%; bottom: 24px; transform: translate(-50%, 20px); opacity: 0; pointer-events: none; background: var(--dark); color: white; padding: 10px 16px; border-radius: 8px; font-size: 12px; z-index: 100; transition: .25s; box-shadow: var(--shadow); }
    .toast.show { opacity: 1; transform: translate(-50%, 0); }
    @media (max-width: 900px) {
      .features-layout { grid-template-columns: 1fr; }
      .feature-photo { display: none; }
      .panel { gap: 20px; padding: 20px; }
      .footer-grid { gap: 25px; }
    }
    @media (max-width: 680px) {
      .container { width: min(100% - 30px, 520px); }
      .hero { min-height: 410px; }
      .hero-bg { object-position: 60% center; }
      .hero-content { padding: 48px 0; max-width: 360px; }
      .hero h1 { font-size: clamp(35px, 10vw, 45px); }
      .hero p { font-size: 12px; max-width: 320px; }
      .stats-grid { padding: 19px 0; grid-template-columns: repeat(2, 1fr); row-gap: 22px; }
      .stat:nth-child(2)::after { display: none; }
      .stat:not(:last-child)::after { height: 55px; }
      .stat strong { font-size: 22px; }
      .section { padding: 25px 0; }
      .feature-cards { grid-template-columns: 1fr; gap: 12px; }
      .feature-card { min-height: 0; padding: 16px; }
      .feature-card p { max-width: 300px; }
      .feature-icon { width: 38px; height: 38px; }
      .panel { grid-template-columns: 1fr; padding: 18px 14px; gap: 22px; }
      .panel .section-title { max-width: 100%; }
      .availability { padding: 9px; }
      .court-row { grid-template-columns: minmax(90px, 1fr) auto 10px; gap: 6px; padding: 7px 8px; font-size: 9px; }
      .status { gap: 4px; font-size: 9px; }
      .cta-banner { padding: 22px 18px; align-items: flex-start; flex-direction: column; gap: 15px; }
      .cta-actions { flex-wrap: wrap; }
      .cta-actions .btn { padding: 10px 12px; }
      .footer-grid { grid-template-columns: repeat(2, 1fr); gap: 24px 18px; }
      .footer-brand { grid-column: 1 / -1; }
      .chat-widget { bottom: 12px; right: 12px; padding: 10px 13px; }
    }
  </style>
</head>
<body>
  <?php include __DIR__ . '/includes/nav_bar.php'; ?>

  <main>
    <section class="hero" id="home">
      <video class="hero-bg" autoplay muted loop playsinline preload="auto" aria-hidden="true">
        <source src="/assets/videos/video_background.mp4?v=<?php echo file_exists(__DIR__ . '/assets/videos/video_background.mp4') ? filemtime(__DIR__ . '/assets/videos/video_background.mp4') : time(); ?>" type="video/mp4">
      </video>
      <div class="container">
        <div class="hero-content">
          <div class="eyebrow">HI-POWER BADMINTON COURT</div>
          <h1>Book courts,<br><span>play better.</span></h1>
          <p>Real-time court reservations, AI-powered performance analysis, and everything you need to elevate your badminton game.</p>
          <div class="hero-actions">
            <a href="/courtbooking" class="btn btn-orange"><i class="fa-regular fa-calendar-days"></i> Book a Court</a>
            <a href="/aimovementanalysis" class="btn btn-outline"><i class="fa-solid fa-robot"></i> Try AI Coach</a>
          </div>
        </div>
      </div>
    </section>

    <div class="stats" aria-label="Court statistics">
      <div class="container stats-grid">
        <div class="stat"><i class="fa-solid fa-table-cells-large"></i><strong>8</strong><span>Courts</span></div>
        <div class="stat"><i class="fa-solid fa-users"></i><strong><?php echo (int)$registered_players; ?></strong><span>Registered Players</span></div>
        <div class="stat"><i class="fa-regular fa-calendar-check"></i><strong id="sessionCounter" data-target="<?php echo (int)$total_sessions; ?>">0</strong><span>Sessions Booked</span></div>
        <div class="stat"><i class="fa-regular fa-user"></i><strong>100+</strong><span>Daily Players</span></div>
      </div>
    </div>

    <section id="features">
      <div class="container features-layout">
        <div>
          <div class="section-kicker">Our features</div>
          <h2 class="section-title">Everything you need to win.</h2>
          <div class="feature-cards">
            <article class="feature-card">
              <div class="feature-icon"><i class="fa-regular fa-calendar-days"></i></div>
              <h3>Court Booking</h3>
              <p>Reserve your preferred court, date, and time in just a few taps.</p>
              <a class="text-link" href="/courtbooking">Book now <i class="fa-solid fa-arrow-right"></i></a>
            </article>
            <article class="feature-card" id="ai-coach">
              <div class="feature-icon orange"><i class="fa-solid fa-brain"></i></div>
              <h3>AI Coach</h3>
              <p>Get training tips, improve your skills, and level up with AI-powered guidance.</p>
              <a class="text-link" href="/aimovementanalysis">Try it now <i class="fa-solid fa-arrow-right"></i></a>
            </article>
            <article class="feature-card" id="shop">
              <div class="feature-icon"><i class="fa-solid fa-cart-shopping"></i></div>
              <h3>Pro Shop</h3>
              <p>Buy or rent quality apparels, shoes, rackets, and more for your game.</p>
              <a class="text-link" href="/ecommerce">Shop now <i class="fa-solid fa-arrow-right"></i></a>
            </article>
          </div>
        </div>
        <div class="feature-photo" role="img" aria-label="Badminton court photo">
          <img src="<?php echo htmlspecialchars($feature_img, ENT_QUOTES); ?>" alt="" loading="lazy" decoding="async">
        </div>
      </div>
    </section>

    <section class="benefits-availability" id="courts">
      <div class="container panel">
        <div>
          <div class="eyebrow">WHY HI-POWER</div>
          <h2 class="section-title">Built for players who take it seriously.</h2>
          <div class="benefit-list">
            <article class="benefit">
              <div class="benefit-icon"><i class="fa-solid fa-gears"></i></div>
              <div><h3>Centralized Management</h3><p>Manage bookings, gear, and payments in one platform.</p></div>
            </article>
            <article class="benefit">
              <div class="benefit-icon"><i class="fa-solid fa-brain"></i></div>
              <div><h3>AI-Powered Coaching</h3><p>Personalized tips, drills, and support to improve your game.</p></div>
            </article>
            <article class="benefit">
              <div class="benefit-icon"><i class="fa-regular fa-calendar-check"></i></div>
              <div><h3>Live Reservations</h3><p>Check real-time availability and avoid double bookings.</p></div>
            </article>
            <article class="benefit">
              <div class="benefit-icon"><i class="fa-solid fa-shield-halved"></i></div>
              <div><h3>Secure Payments</h3><p>Safe and hassle-free transactions for a smooth experience.</p></div>
            </article>
          </div>
        </div>
        <div class="availability">
          <h3 class="availability-heading">Live availability</h3>
          <?php foreach ($courts as $court):
            $statusClass = ($court['status'] === 'Maintenance') ? 'maintenance' : '';
          ?>
          <div class="court-row">
            <span class="court-name"><?php echo htmlspecialchars($court['name']); ?></span>
            <span class="status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($court['status']); ?></span>
            <a href="/courtbooking" class="text-on-surface-variant hover:text-primary transition-colors" aria-label="View courts"><i class="fa-solid fa-chevron-right"></i></a>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="cta">
      <div class="container">
        <div class="cta-banner" style="--cta-img:url('<?php echo htmlspecialchars($cta_img, ENT_QUOTES); ?>')">
          <div><h2>Ready to play?</h2><p>Join hundreds of players already using HI-POWER to book courts, analyze form, and level up.</p></div>
          <div class="cta-actions">
            <a class="btn btn-orange" href="/authentication/register">Get Started Free</a>
            <a class="btn btn-outline" href="/courtbooking">Browse Courts</a>
          </div>
        </div>
      </div>
    </section>
  </main>

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <?php include __DIR__ . '/includes/mobile_nav.php'; ?>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>

  <script>
    const toast = document.getElementById("toast");
    let toastTimer;
    function showToast(message) {
      toast.textContent = message;
      toast.classList.add("show");
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => toast.classList.remove("show"), 2600);
    }

    document.querySelectorAll("[data-message]").forEach((element) => {
      element.addEventListener("click", (event) => {
        event.preventDefault();
        showToast(element.dataset.message);
      });
    });

    // Session counter animation
    const counter = document.getElementById("sessionCounter");
    if (counter) {
      const target = parseInt(counter.dataset.target) || 0;
      const cObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            const duration = 1800;
            const start = performance.now();
            function update(now) {
              const progress = Math.min((now - start) / duration, 1);
              const eased = 1 - Math.pow(1 - progress, 3);
              counter.textContent = Math.floor(eased * target).toLocaleString();
              if (progress < 1) requestAnimationFrame(update);
            }
            requestAnimationFrame(update);
            cObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.5 });
      cObserver.observe(counter);
    }
  </script>
  <script src="//code.tidio.co/xrwsq4cmchufdxjlebbrzzag1vlabwsq.js" async></script>
</body>
</html>