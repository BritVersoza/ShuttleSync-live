<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/database_connect.php';
require_once __DIR__ . '/includes/csrf_helper.php';

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;
$full_name = $is_logged_in ? $_SESSION['full_name'] : 'Guest';

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

$active_tab = $_GET['tab'] ?? 'contact';
$valid_tabs = ['contact', 'help', 'terms', 'privacy'];
if (!in_array($active_tab, $valid_tabs)) $active_tab = 'contact';

// ==========================================
// HANDLE SUPPORT CONTACT SUBMISSIONS
// ==========================================
$support_success = false;
$support_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_contact') {
    verify_csrf_token();

    $c_name  = trim($_POST['name'] ?? '');
    $c_email = trim($_POST['email'] ?? '');
    $c_subj = $_POST['subject'] ?? 'general';
    $c_msg   = trim($_POST['message'] ?? '');

    $valid_subjects = ['general', 'booking', 'payment', 'ai', 'account', 'feedback'];
    if ($c_name === '' || $c_email === '' || $c_msg === '') {
        $support_error = 'Please fill in your name, email and message before sending.';
    } elseif (!filter_var($c_email, FILTER_VALIDATE_EMAIL)) {
        $support_error = 'Please enter a valid email address.';
    } elseif (!in_array($c_subj, $valid_subjects, true)) {
        $support_error = 'Please choose a valid subject.';
    } else {
        try {
            $ins = $pdo->prepare("INSERT INTO Support_Tickets (user_id, subject, category, message, status) VALUES (?, ?, ?, ?, 'Open')");
            $ins->execute([$user_id ?? 0, $c_subj, $c_subj, $c_msg]);
            $support_success = true;
        } catch (PDOException $e) {
            $support_error = 'We could not send your message right now. Please try again later.';
        }
    }
}

if ($support_success) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}
if ($support_error !== '') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => $support_error]);
    exit;
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Support Center | HI-POWER</title>
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <style>
        .tab-btn { transition: all 0.2s ease; position: relative; }
        .tab-btn.active { color: #ff5722; }
        .tab-btn.active::after { content: ''; position: absolute; bottom: -2px; left: 0; right: 0; height: 2px; background: #ff5722; border-radius: 1px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .faq-item summary::-webkit-details-marker { display: none; }
        .faq-item summary { list-style: none; }
        .faq-item[open] summary .faq-arrow { transform: rotate(180deg); }
        .faq-arrow { transition: transform 0.2s ease; }
    </style>
</head>
<body class="bg-background text-on-surface min-h-screen flex flex-col">

<?php include __DIR__ . '/includes/nav_bar.php'; ?>

<main class="flex-grow max-w-5xl mx-auto w-full px-4 md:px-8 pt-24 pb-16">

    <!-- Header -->
    <div class="mb-10">
        <h1 class="text-3xl md:text-4xl font-bold text-on-surface tracking-tight mb-2">Support Center</h1>
        <p class="text-on-surface-variant">Get help, read our policies, or reach out to the team.</p>
    </div>

    <!-- Tab Navigation -->
    <div class="flex gap-1 border-b border-outline-variant/30 mb-8 overflow-x-auto">
        <button onclick="switchTab('contact')" class="tab-btn px-5 py-3 text-sm font-bold whitespace-nowrap <?php echo $active_tab === 'contact' ? 'active' : 'text-on-surface-variant'; ?>" data-tab="contact">
            Contact
        </button>
        <button onclick="switchTab('help')" class="tab-btn px-5 py-3 text-sm font-bold whitespace-nowrap <?php echo $active_tab === 'help' ? 'active' : 'text-on-surface-variant'; ?>" data-tab="help">
            Help Center
        </button>
        <button onclick="switchTab('terms')" class="tab-btn px-5 py-3 text-sm font-bold whitespace-nowrap <?php echo $active_tab === 'terms' ? 'active' : 'text-on-surface-variant'; ?>" data-tab="terms">
            Terms of Service
        </button>
        <button onclick="switchTab('privacy')" class="tab-btn px-5 py-3 text-sm font-bold whitespace-nowrap <?php echo $active_tab === 'privacy' ? 'active' : 'text-on-surface-variant'; ?>" data-tab="privacy">
            Privacy Policy
        </button>
    </div>

    <!-- ======================== CONTACT TAB ======================== -->
    <div id="tab-contact" class="tab-content <?php echo $active_tab === 'contact' ? 'active' : ''; ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Contact Form -->
            <div class="bg-surface-container-lowest rounded-2xl p-6 md:p-8 border border-outline-variant/40">
                <h2 class="text-xl font-bold text-on-surface mb-1">Send a Message</h2>
                <p class="text-sm text-on-surface-variant mb-6">We'll get back to you within 24 hours.</p>

                <form id="contactForm" class="space-y-4" action="/support" method="POST">
                    <?php echo get_csrf_input(); ?>
                    <input type="hidden" name="action" value="submit_contact">

                    <div>
                        <label class="text-xs font-bold text-outline uppercase tracking-widest mb-1.5 block">Full Name</label>
                        <input type="text" name="name" required placeholder="Juan Dela Cruz"
                            class="w-full bg-surface border border-outline-variant/50 rounded-xl px-4 py-3 text-sm text-on-surface placeholder-outline focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-outline uppercase tracking-widest mb-1.5 block">Email</label>
                        <input type="email" name="email" required placeholder="you@example.com"
                            class="w-full bg-surface border border-outline-variant/50 rounded-xl px-4 py-3 text-sm text-on-surface placeholder-outline focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-outline uppercase tracking-widest mb-1.5 block">Subject</label>
                        <select name="subject" class="w-full bg-surface border border-outline-variant/50 rounded-xl px-4 py-3 text-sm text-on-surface font-semibold focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all appearance-none cursor-pointer">
                            <option value="general">General Inquiry</option>
                            <option value="booking">Booking Issue</option>
                            <option value="payment">Payment Problem</option>
                            <option value="ai">AI Analysis Help</option>
                            <option value="account">Account Issue</option>
                            <option value="feedback">Feedback & Suggestions</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-outline uppercase tracking-widest mb-1.5 block">Message</label>
                        <textarea name="message" required rows="4" placeholder="How can we help you?"
                            class="w-full bg-surface border border-outline-variant/50 rounded-xl px-4 py-3 text-sm text-on-surface placeholder-outline focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all resize-none"></textarea>
                    </div>
                    <button type="submit" id="contactSubmit" class="w-full bg-primary hover:bg-primary-dark text-white py-3 rounded-xl font-bold text-sm transition-all hover:shadow-lg hover:shadow-primary/20">
                        Send Message
                    </button>
                    <div id="contactError" class="hidden bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700 font-semibold"></div>
                    <div id="contactSuccess" class="hidden bg-green-50 border border-green-200 rounded-xl px-4 py-3 text-sm text-green-700 font-semibold">
                        Message sent! We'll respond within 24 hours.
                    </div>
                </form>
            </div>

            <!-- Contact Info -->
            <div class="space-y-6">
                <div class="bg-surface-container-lowest rounded-2xl p-6 border border-outline-variant/40">
                    <h3 class="text-base font-bold text-on-surface mb-4">Get in Touch</h3>
                    <div class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary text-[20px]">mail</span>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-on-surface">Email</p>
                                <a href="mailto:info@hipowerbc.xyz" class="text-sm text-primary hover:underline">info@hipowerbc.xyz</a>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary text-[20px]">location_on</span>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-on-surface">Location</p>
                                <p class="text-sm text-on-surface-variant">Hi-Power Badminton Center, Philippines</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary text-[20px]">schedule</span>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-on-surface">Operating Hours</p>
                                <p class="text-sm text-on-surface-variant">Mon - Sun: 6:00 AM - 10:00 PM</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-surface-container-lowest rounded-2xl p-6 border border-outline-variant/40">
                    <h3 class="text-base font-bold text-on-surface mb-3">Quick Response</h3>
                    <p class="text-sm text-on-surface-variant leading-relaxed">
                        For urgent booking or payment issues, email us directly at
                        <a href="mailto:support@hipowerbc.xyz" class="text-primary font-semibold hover:underline">support@hipowerbc.xyz</a>
                        with your booking reference number for faster assistance.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================== HELP CENTER TAB ======================== -->
    <div id="tab-help" class="tab-content <?php echo $active_tab === 'help' ? 'active' : ''; ?>">
        <div class="max-w-3xl">
            <!-- Search -->
            <div class="relative mb-8">
                <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline text-[20px]">search</span>
                <input type="text" id="faqSearch" placeholder="Search help topics..."
                    class="w-full bg-surface-container-lowest border border-outline-variant/40 rounded-xl pl-11 pr-4 py-3.5 text-sm text-on-surface placeholder-outline focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
            </div>

            <!-- FAQ Sections -->
            <div class="space-y-3" id="faqList">
                <!-- Booking -->
                <h3 class="text-xs font-bold text-primary uppercase tracking-widest mt-6 mb-2">Booking</h3>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">How do I book a court?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Go to <a href="courtbooking" class="text-primary font-semibold hover:underline">Book a Court</a>, select your preferred date and time slot, then proceed to payment. You'll receive instant confirmation via the dashboard. Pending Payment slots are held temporarily but will be released if not paid.
                    </div>
                </details>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">Can I cancel or reschedule my booking?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Yes. Go to your <a href="playerdashboard" class="text-primary font-semibold hover:underline">Dashboard</a> &rarr; My Bookings tab. You can cancel a confirmed booking up to 2 hours before the scheduled time. Pending Payment bookings can be cancelled anytime.
                    </div>
                </details>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">What happens if I don't pay for a Pending Payment booking?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Pending Payment slots remain available for others until you complete payment or cancel. The slot is not reserved until payment is confirmed. Other users can book the same time slot.
                    </div>
                </details>

                <!-- Payment -->
                <h3 class="text-xs font-bold text-primary uppercase tracking-widest mt-8 mb-2">Payment</h3>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">What payment methods are accepted?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        We accept <strong>GCash</strong> (via PayMongo), <strong>PayPal</strong>, and <strong>Pay at Counter</strong> (cash). For court bookings, GCash and PayPal are processed online. Pay at Counter is available for both bookings and shop purchases.
                    </div>
                </details>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">Is my payment secure?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Yes. All online payments are processed through PayMongo (GCash) and PayPal — PCI-compliant payment gateways. HI-POWER never stores your card or account credentials. Pay at Counter transactions are recorded manually upon verification.
                    </div>
                </details>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">Can I get a refund?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Refunds are handled case-by-case. If you cancelled a booking before the 2-hour window, the pending payment is simply released. For completed payments with valid concerns (e.g., double charge), email <a href="mailto:support@hipowerbc.xyz" class="text-primary font-semibold hover:underline">support@hipowerbc.xyz</a> with your transaction reference.
                    </div>
                </details>

                <!-- AI Analysis -->
                <h3 class="text-xs font-bold text-primary uppercase tracking-widest mt-8 mb-2">AI Analysis</h3>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">How does the AI Coach work?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Upload a full-body photo or capture one using your device camera. The AI uses MediaPipe Pose Detection to extract your body keypoints, compares them against professional badminton poses, and scores your form on footwork, posture, and swing mechanics.
                    </div>
                </details>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">Why does it say "Full body not detected"?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        The AI requires your entire body to be visible from head to toe — including hips, knees, and ankles. Face-only or waist-up photos won't work. Stand back, ensure good lighting, and capture your full body at a side or 45-degree angle for best results.
                    </div>
                </details>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">Do I need an account to use the AI Coach?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        No. The AI Coach is available to everyone, including guests. However, logged-in users get their analysis history saved automatically in the dashboard for tracking progress over time.
                    </div>
                </details>

                <!-- Account -->
                <h3 class="text-xs font-bold text-primary uppercase tracking-widest mt-8 mb-2">Account</h3>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">How do I reset my password?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Click "Forgot Password?" on the <a href="authentication/login" class="text-primary font-semibold hover:underline">login page</a>. Enter your registered email, and we'll send a 6-digit verification code. Use it to set a new password. The code expires in 15 minutes.
                    </div>
                </details>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">Can I sign in with Google?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Yes. HI-POWER supports Google Sign-In. Click the "Sign in with Google" button on the login or register page and authorize with your Google account. Your account will be created automatically on first sign-in.
                    </div>
                </details>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">How do I view my orders and bookings?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Visit your <a href="playerdashboard" class="text-primary font-semibold hover:underline">Dashboard</a>. The "My Bookings" tab shows all court reservations and their status. Shop orders can be tracked through the order confirmation email or by contacting support.
                    </div>
                </details>

                <!-- Shop -->
                <h3 class="text-xs font-bold text-primary uppercase tracking-widest mt-8 mb-2">Shop</h3>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">How do I buy gear from the Pro Shop?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Browse the <a href="ecommerce" class="text-primary font-semibold hover:underline">Pro Shop</a>, add items to your cart, and proceed to checkout. You can pay via GCash, PayPal, or choose Pay at Counter for cash payment upon pickup.
                    </div>
                </details>

                <details class="faq-item bg-surface-container-lowest rounded-xl border border-outline-variant/30 overflow-hidden">
                    <summary class="flex items-center justify-between px-5 py-4 cursor-pointer hover:bg-surface-variant/30 transition-colors">
                        <span class="text-sm font-bold text-on-surface pr-4">What is the return policy for gear?</span>
                        <span class="material-symbols-outlined text-outline text-[18px] faq-arrow shrink-0">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        Items may be returned within 7 days of purchase if unused and in original packaging. Contact <a href="mailto:support@hipowerbc.xyz" class="text-primary font-semibold hover:underline">support@hipowerbc.xyz</a> with your order details to initiate a return.
                    </div>
                </details>
            </div>
        </div>
    </div>

    <!-- ======================== TERMS TAB ======================== -->
    <div id="tab-terms" class="tab-content <?php echo $active_tab === 'terms' ? 'active' : ''; ?>">
        <div class="max-w-3xl">
            <div class="bg-surface-container-lowest rounded-2xl p-6 md:p-8 border border-outline-variant/40">
                <h2 class="text-2xl font-bold text-on-surface mb-2">Terms of Service</h2>
                <p class="text-xs text-on-surface-variant mb-8">Last updated: July 2026</p>

                <div class="space-y-8 text-sm text-on-surface-variant leading-relaxed">
                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">1. Acceptance of Terms</h3>
                        <p>By accessing or using HI-POWER ("the Platform"), you agree to be bound by these Terms of Service. If you do not agree, do not use the Platform. HI-POWER is operated by Hi-Power Badminton Center ("we," "us," or "our").</p>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">2. Eligibility</h3>
                        <p>The Platform is available to users aged 13 and above. By creating an account, you represent that you are at least 13 years old and have the legal capacity to enter into these terms.</p>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">3. Account Registration</h3>
                        <p>You may register via email/password or Google Sign-In. You are responsible for maintaining the confidentiality of your account credentials. You must provide accurate and complete information during registration. One account per person — duplicate accounts may be suspended.</p>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">4. Court Bookings</h3>
                        <ul class="list-disc ml-5 space-y-1">
                            <li>Court reservations are subject to availability and are not confirmed until payment is processed.</li>
                            <li>Pending Payment slots are not reserved and may be booked by other users at any time.</li>
                            <li>Cancellations must be made at least 2 hours before the scheduled time.</li>
                            <li>Repeated no-shows may result in account restrictions.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">5. Payments</h3>
                        <ul class="list-disc ml-5 space-y-1">
                            <li>All payments are processed through third-party gateways (PayMongo for GCash, PayPal). We do not store payment credentials.</li>
                            <li>Prices are in Philippine Pesos (PHP) unless otherwise stated.</li>
                            <li>Pay at Counter transactions must be completed within 30 minutes of booking or the reservation may be released.</li>
                            <li>Refund requests are handled case-by-case and must be submitted within 7 days.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">6. AI Analysis Disclaimer</h3>
                        <ul class="list-disc ml-5 space-y-1">
                            <li>The AI Coach provides educational feedback only and is not a substitute for professional coaching.</li>
                            <li>Analysis results may vary based on image quality, camera angle, and body positioning.</li>
                            <li>We do not guarantee the accuracy of biomechanical assessments.</li>
                            <li>Users assume all risk when performing physical activities based on AI recommendations.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">7. Pro Shop</h3>
                        <ul class="list-disc ml-5 space-y-1">
                            <li>Product availability is subject to stock levels and may change without notice.</li>
                            <li>Orders are processed within 1-2 business days. Delivery times vary by location.</li>
                            <li>Returns are accepted within 7 days of purchase for unused items in original packaging.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">8. Prohibited Conduct</h3>
                        <p>You agree not to: (a) use the Platform for any unlawful purpose; (b) attempt to gain unauthorized access to any part of the Platform; (c) interfere with or disrupt the Platform's infrastructure; (d) create multiple accounts to manipulate bookings; (e) upload harmful, offensive, or inappropriate content.</p>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">9. Intellectual Property</h3>
                        <p>All content on HI-POWER — including design, code, logos, and AI models — is the property of Hi-Power Badminton Center. You may not copy, modify, or distribute any part of the Platform without written permission.</p>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">10. Limitation of Liability</h3>
                        <p>HI-POWER is provided "as is" without warranties of any kind. We are not liable for any indirect, incidental, or consequential damages arising from your use of the Platform. Our total liability shall not exceed the amount you paid in the 12 months preceding the claim.</p>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">11. Modifications</h3>
                        <p>We reserve the right to modify these terms at any material time. Continued use of the Platform after changes constitutes acceptance of the updated terms. We will notify users of significant changes via email or dashboard notification.</p>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">12. Contact</h3>
                        <p>For questions about these Terms, contact us at <a href="mailto:legal@hipowerbc.xyz" class="text-primary font-semibold hover:underline">legal@hipowerbc.xyz</a>.</p>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================== PRIVACY TAB ======================== -->
    <div id="tab-privacy" class="tab-content <?php echo $active_tab === 'privacy' ? 'active' : ''; ?>">
        <div class="max-w-3xl">
            <div class="bg-surface-container-lowest rounded-2xl p-6 md:p-8 border border-outline-variant/40">
                <h2 class="text-2xl font-bold text-on-surface mb-2">Privacy Policy</h2>
                <p class="text-xs text-on-surface-variant mb-8">Last updated: July 2026</p>

                <div class="space-y-8 text-sm text-on-surface-variant leading-relaxed">
                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">1. Information We Collect</h3>
                        <p>We collect the following types of information:</p>
                        <ul class="list-disc ml-5 space-y-1 mt-2">
                            <li><strong>Account Data:</strong> Full name, email address, password (hashed), skill level, and Google account info (if using Google Sign-In).</li>
                            <li><strong>Booking Data:</strong> Court reservations, payment status, and transaction references.</li>
                            <li><strong>Shop Data:</strong> Order history, cart contents, and delivery information.</li>
                            <li><strong>AI Analysis Data:</strong> Uploaded photos and analysis results (accuracy scores, feedback, detected poses). Photos are processed in memory and deleted immediately — we do not store uploaded images.</li>
                            <li><strong>Usage Data:</strong> Pages visited, features used, and interaction patterns within the Platform.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">2. How We Use Your Information</h3>
                        <ul class="list-disc ml-5 space-y-1">
                            <li>To provide and improve court booking, AI analysis, and shop services.</li>
                            <li>To process payments and deliver order confirmations.</li>
                            <li>To send account-related notifications (booking confirmations, password resets, announcements).</li>
                            <li>To display your analysis history and performance trends in your dashboard.</li>
                            <li>To improve the AI model's accuracy using anonymized, aggregated data.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">3. Photo & AI Data</h3>
                        <ul class="list-disc ml-5 space-y-1">
                            <li>Photos uploaded for AI analysis are processed on the server in temporary memory and permanently deleted immediately after analysis.</li>
                            <li>We do not store, cache, or retain any uploaded images.</li>
                            <li>Analysis results (scores, feedback, detected shot type) are saved to your account history if you are logged in.</li>
                            <li>Guest analysis results are discarded after the session.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">4. Data Sharing</h3>
                        <p>We do not sell or rent your personal information. We may share data with:</p>
                        <ul class="list-disc ml-5 space-y-1 mt-2">
                            <li><strong>Payment Processors:</strong> PayMongo (GCash) and PayPal for transaction processing only.</li>
                            <li><strong>Google:</strong> If you use Google Sign-In, basic profile info (name, email) is retrieved per your OAuth consent.</li>
                            <li><strong>Legal Authorities:</strong> When required by law or to protect our rights.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">5. Data Security</h3>
                        <ul class="list-disc ml-5 space-y-1">
                            <li>Passwords are hashed using secure one-way hashing before storage.</li>
                            <li>All data transmission is encrypted via HTTPS/TLS.</li>
                            <li>Payment credentials are never stored on our servers.</li>
                            <li>Access to user data is restricted to authorized personnel only.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">6. Cookies & Session</h3>
                        <p>HI-POWER uses session cookies for authentication and to maintain your login state. We do not use third-party tracking cookies or advertising pixels.</p>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">7. Your Rights</h3>
                        <ul class="list-disc ml-5 space-y-1">
                            <li><strong>Access:</strong> You may request a copy of all personal data we hold about you.</li>
                            <li><strong>Correction:</strong> You may update your profile information at any time via the dashboard.</li>
                            <li><strong>Deletion:</strong> You may request full account deletion by emailing <a href="mailto:privacy@hipowerbc.xyz" class="text-primary font-semibold hover:underline">privacy@hipowerbc.xyz</a>. All your data will be permanently removed within 30 days.</li>
                            <li><strong>Export:</strong> You may request your data in a portable format.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">8. Data Retention</h3>
                        <ul class="list-disc ml-5 space-y-1">
                            <li>Account data is retained until you request deletion.</li>
                            <li>Booking and order records are retained for 2 years for accounting purposes.</li>
                            <li>AI analysis logs are retained for 1 year unless you request earlier deletion.</li>
                            <li>Uploaded photos are never retained — deleted immediately after processing.</li>
                        </ul>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">9. Children's Privacy</h3>
                        <p>The Platform is not intended for children under 13. We do not knowingly collect personal information from children under 13. If we discover such data has been collected, it will be deleted immediately.</p>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">10. Changes to This Policy</h3>
                        <p>We may update this Privacy Policy from time to time. Significant changes will be communicated via email or dashboard notification. The "Last updated" date at the top reflects the most recent revision.</p>
                    </section>

                    <section>
                        <h3 class="text-base font-bold text-on-surface mb-2">11. Contact</h3>
                        <p>For privacy-related inquiries, contact us at <a href="mailto:privacy@hipowerbc.xyz" class="text-primary font-semibold hover:underline">privacy@hipowerbc.xyz</a>.</p>
                    </section>
                </div>
            </div>
        </div>
    </div>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>

<script>
function switchTab(tab) {
    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    history.pushState({}, '', url);

    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        if (btn.dataset.tab === tab) {
            btn.classList.add('active');
            btn.classList.remove('text-on-surface-variant');
        } else {
            btn.classList.remove('active');
            btn.classList.add('text-on-surface-variant');
        }
    });

    // Update tab content
    document.querySelectorAll('.tab-content').forEach(content => {
        if (content.id === 'tab-' + tab) {
            content.classList.add('active');
        } else {
            content.classList.remove('active');
        }
    });

    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// FAQ Search
const faqSearch = document.getElementById('faqSearch');
const faqList = document.getElementById('faqList');
if (faqSearch) {
    faqSearch.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        faqList.querySelectorAll('.faq-item').forEach(item => {
            const text = item.textContent.toLowerCase();
            item.style.display = text.includes(query) || query === '' ? '' : 'none';
        });
    });
}

// Contact form handler
const contactForm = document.getElementById('contactForm');
if (contactForm) {
    const contactError = document.getElementById('contactError');
    contactForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('contactSubmit');
        const succEl = document.getElementById('contactSuccess');
        const errEl = document.getElementById('contactError');
        btn.textContent = 'Sending...';
        btn.disabled = true;
        if (errEl) errEl.classList.add('hidden');

        fetch('/support', { method: 'POST', body: new FormData(contactForm) })
            .then(r => r.json())
            .then(data => {
                btn.textContent = 'Send Message';
                btn.disabled = false;
                if (!data || !data.ok) {
                    if (errEl && data && data.message) errEl.textContent = data.message;
                    if (errEl) errEl.classList.remove('hidden');
                    return;
                }
                if (succEl) succEl.classList.remove('hidden');
                setTimeout(() => { if (succEl) succEl.classList.add('hidden'); }, 6000);
            })
            .catch(() => {
                btn.textContent = 'Send Message';
                btn.disabled = false;
                if (errEl) {
                    errEl.textContent = 'Something went wrong while sending your message. Please try again.';
                    errEl.classList.remove('hidden');
                }
            });
    });
}
</script>

</body>
</html>
