<?php
require_once __DIR__ . '/includes/session_boot.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Terms &amp; Conditions | HI-POWER</title>
    <!-- Google Fonts Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: {
                        brand: { orange: '#FF5722', dark: '#080F17' }
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Poppins', sans-serif !important; }
        .section-box { scroll-margin-top: 90px; }
        .term-num { color: #FF5722; font-weight: 700; }
    </style>
</head>
<body class="bg-[#F4F6F8] min-h-screen text-[#14264A]">
    <!-- Header Section -->
    <header class="sticky top-0 z-40 bg-[#080F17]/95 backdrop-blur border-b border-[#14264A] shadow-md">
        <div class="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <!-- Hi-Power Lightning Bolt Logo -->
                <div class="w-10 h-10 rounded-xl bg-[#FF5722] flex items-center justify-center shadow-md">
                    <svg class="w-6 h-6 text-white fill-current" viewBox="0 0 24 24">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
                    </svg>
                </div>
                <div>
                    <div class="text-lg font-extrabold text-white tracking-wider flex items-center gap-1.5">
                        <span>HI-POWER</span>
                    </div>
                    <p class="text-[10px] text-slate-400 font-bold tracking-widest uppercase leading-none mt-0.5">Badminton Court</p>
                </div>
            </div>
            <a href="/" class="inline-flex items-center gap-1.5 text-sm text-slate-300 hover:text-[#FF5722] font-semibold transition-colors">
                <span>&larr;</span> Back to Home
            </a>
        </div>
    </header>

    <!-- Main Content Section -->
    <main class="max-w-4xl mx-auto px-6 py-12">
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-8 sm:p-12">
            <h1 class="text-3xl font-extrabold text-slate-900 mb-2">Terms &amp; Conditions</h1>
            <p class="text-xs text-slate-400 mb-10 font-medium">Effective date: October 2, 2026</p>

            <section id="terms" class="section-box space-y-6 text-[15px] leading-relaxed text-slate-600">
                <h2 class="text-xl font-bold text-slate-900 pt-2">Terms &amp; Conditions</h2>

                <p>Welcome to ShuttleSync, a badminton court booking and sports analytics platform. By creating an account, accessing our website, or using our services, you agree to be bound by these Terms &amp; Conditions and our Privacy Policy.</p>

                <div>
                    <p><span class="term-num">1.</span> <strong class="text-slate-900 font-bold">Acceptance of Terms</strong></p>
                    <p>By registering an account, you confirm that you have read, understood, and agree to these Terms. If you do not agree, you may not use our services.</p>
                </div>

                <div>
                    <p><span class="term-num">2.</span> <strong class="text-slate-900 font-bold">Accounts</strong></p>
                    <p>You must provide accurate and complete information when creating an account. You are responsible for safeguarding your password and for all activity under your account. Notify us immediately of any unauthorized use.</p>
                </div>

                <div>
                    <p><span class="term-num">3.</span> <strong class="text-slate-900 font-bold">Court Bookings &amp; Cancellations</strong></p>
                    <p>Bookings are subject to availability and are confirmed only after successful payment. Cancellation and rescheduling policies are set per venue and displayed at the time of booking. No-shows may be charged in full.</p>
                </div>

                <div>
                    <p><span class="term-num">4.</span> <strong class="text-slate-900 font-bold">Payments</strong></p>
                    <p>All payments are processed through third-party payment providers. Prices are stated in Philippine Pesos (PHP) unless otherwise indicated. Refunds are issued in accordance with the applicable cancellation policy.</p>
                </div>

                <div>
                    <p><span class="term-num">5.</span> <strong class="text-slate-900 font-bold">Acceptable Use</strong></p>
                    <p>You agree not to misuse the platform, including attempting to disrupt services, accessing other users' accounts, reselling bookings without authorization, or uploading unlawful content.</p>
                </div>

                <div>
                    <p><span class="term-num">6.</span> <strong class="text-slate-900 font-bold">AI-Powered Analysis</strong></p>
                    <p>ShuttleSync provides AI-based movement and form analysis using uploaded media. Results are provided for guidance only and are not a substitute for professional coaching or medical advice. We are not liable for decisions made based on these insights.</p>
                </div>

                <div>
                    <p><span class="term-num">7.</span> <strong class="text-slate-900 font-bold">Limitation of Liability</strong></p>
                    <p>To the maximum extent permitted by law, ShuttleSync shall not be liable for any indirect, incidental, or consequential damages arising from your use of the platform, including but not limited to loss of bookings, data, or revenue.</p>
                </div>

                <div>
                    <p><span class="term-num">8.</span> <strong class="text-slate-900 font-bold">Termination</strong></p>
                    <p>We may suspend or terminate your account if you violate these Terms. You may close your account at any time by contacting support.</p>
                </div>

                <div>
                    <p><span class="term-num">9.</span> <strong class="text-slate-900 font-bold">Changes to These Terms</strong></p>
                    <p>We may update these Terms from time to time. Continued use of the platform after changes are posted constitutes acceptance of the revised Terms.</p>
                </div>

                <div>
                    <p><span class="term-num">10.</span> <strong class="text-slate-900 font-bold">Contact</strong></p>
                    <p>For questions about these Terms, contact us at <a href="mailto:support@hipowerbc.xyz" class="text-[#FF5722] hover:underline font-semibold">support@hipowerbc.xyz</a>.</p>
                </div>
            </section>

            <hr class="my-12 border-slate-100">

            <section id="privacy" class="section-box space-y-6 text-[15px] leading-relaxed text-slate-600">
                <h2 class="text-xl font-bold text-slate-900 pt-2">Privacy Policy</h2>

                <p>Your privacy matters to us. This policy explains what information we collect, how we use it, and the choices you have.</p>

                <div>
                    <p><span class="term-num">1.</span> <strong class="text-slate-900 font-bold">Information We Collect</strong></p>
                    <p>We collect the information you provide when registering, such as your name, email address, contact details, and skill level. When you book or purchase, we collect booking and transaction records.</p>
                </div>

                <div>
                    <p><span class="term-num">2.</span> <strong class="text-slate-900 font-bold">Media &amp; AI Analysis</strong></p>
                    <p>When you upload images or videos for AI analysis, we process them solely to generate your movement insights. Media is used only to provide the requested analysis and is not sold or shared with third parties.</p>
                </div>

                <div>
                    <p><span class="term-num">3.</span> <strong class="text-slate-900 font-bold">Payments</strong></p>
                    <p>Payment details are handled by our third-party payment processors. We do not store card numbers or sensitive payment credentials on our servers.</p>
                </div>

                <div>
                    <p><span class="term-num">4.</span> <strong class="text-slate-900 font-bold">How We Use Data</strong></p>
                    <p>We use your data to manage your account, process bookings and payments, deliver AI analysis, improve our services, and send service-related communications. We never sell your personal data.</p>
                </div>

                <div>
                    <p><span class="term-num">5.</span> <strong class="text-slate-900 font-bold">Third-Party Services</strong></p>
                    <p>We may use trusted third-party providers (e.g., payment gateways, analytics, Cloudflare) who process data only under our instruction.</p>
                </div>

                <div>
                    <p><span class="term-num">6.</span> <strong class="text-slate-900 font-bold">Cookies &amp; Local Storage</strong></p>
                    <p>We use cookies and local storage to keep you signed in and remember your preferences. You can disable cookies in your browser, though some features may not work.</p>
                </div>

                <div>
                    <p><span class="term-num">7.</span> <strong class="text-slate-900 font-bold">Data Security</strong></p>
                    <p>Passwords are stored as irreversible hashes, and access to personal data is restricted to authorized personnel only.</p>
                </div>

                <div>
                    <p><span class="term-num">8.</span> <strong class="text-slate-900 font-bold">Your Rights</strong></p>
                    <p>You may request access to, correction of, or deletion of your personal data at any time by contacting <a href="mailto:support@hipowerbc.xyz" class="text-[#FF5722] hover:underline font-semibold">support@hipowerbc.xyz</a>.</p>
                </div>
            </section>
        </div>

        <p class="text-center text-xs text-slate-400 mt-8 font-medium">&copy; 2026 ShuttleSync. All rights reserved.</p>
    </main>
</body>
</html>