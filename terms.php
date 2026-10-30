<?php
require_once __DIR__ . '/includes/session_boot.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Terms &amp; Conditions | ShuttleSync</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: { blue: '#3145e6', red: '#ed4a30', dark: '#0f172a' }
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Inter', sans-serif; }
        .section-box { scroll-margin-top: 90px; }
        .term-num { color: #3145e6; font-weight: 700; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="sticky top-0 z-40 bg-white/95 backdrop-blur border-b border-gray-100">
        <div class="max-w-4xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <img src="/img/logo.png" alt="Logo" class="w-10 h-10 rounded-xl">
                <div>
                    <span class="text-lg font-bold text-gray-900"><span class="text-brand-blue">Shuttle</span><span class="text-brand-red">Sync</span></span>
                    <p class="text-[11px] text-gray-400 font-medium leading-none mt-0.5">Legal &amp; Privacy</p>
                </div>
            </div>
            <a href="/" class="inline-flex items-center gap-1.5 text-sm text-gray-400 hover:text-brand-blue transition-colors">
                <span>&larr;</span> Back to Home
            </a>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-6 py-12">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-8 sm:p-12">
            <h1 class="text-3xl font-extrabold text-gray-900 mb-2">Terms &amp; Conditions</h1>
            <p class="text-sm text-gray-400 mb-10">Effective date: October 2, 2026</p>

            <section id="terms" class="section-box space-y-5 text-[15px] leading-relaxed text-gray-600">
                <h2 class="text-xl font-bold text-gray-900 pt-2">Terms &amp; Conditions</h2>

                <p>Welcome to ShuttleSync, a badminton court booking and sports analytics platform. By creating an account, accessing our website, or using our services, you agree to be bound by these Terms &amp; Conditions and our Privacy Policy.</p>

                <div>
                    <p><span class="term-num">1.</span> <strong class="text-gray-900">Acceptance of Terms</strong></p>
                    <p>By registering an account, you confirm that you have read, understood, and agree to these Terms. If you do not agree, you may not use our services.</p>
                </div>

                <div>
                    <p><span class="term-num">2.</span> <strong class="text-gray-900">Accounts</strong></p>
                    <p>You must provide accurate and complete information when creating an account. You are responsible for safeguarding your password and for all activity under your account. Notify us immediately of any unauthorized use.</p>
                </div>

                <div>
                    <p><span class="term-num">3.</span> <strong class="text-gray-900">Court Bookings &amp; Cancellations</strong></p>
                    <p>Bookings are subject to availability and are confirmed only after successful payment. Cancellation and rescheduling policies are set per venue and displayed at the time of booking. No-shows may be charged in full.</p>
                </div>

                <div>
                    <p><span class="term-num">4.</span> <strong class="text-gray-900">Payments</strong></p>
                    <p>All payments are processed through third-party payment providers. Prices are stated in Philippine Pesos (PHP) unless otherwise indicated. Refunds are issued in accordance with the applicable cancellation policy.</p>
                </div>

                <div>
                    <p><span class="term-num">5.</span> <strong class="text-gray-900">Acceptable Use</strong></p>
                    <p>You agree not to misuse the platform, including attempting to disrupt services, accessing other users' accounts, reselling bookings without authorization, or uploading unlawful content.</p>
                </div>

                <div>
                    <p><span class="term-num">6.</span> <strong class="text-gray-900">AI-Powered Analysis</strong></p>
                    <p>ShuttleSync provides AI-based movement and form analysis using uploaded media. Results are provided for guidance only and are not a substitute for professional coaching or medical advice. We are not liable for decisions made based on these insights.</p>
                </div>

                <div>
                    <p><span class="term-num">7.</span> <strong class="text-gray-900">Limitation of Liability</strong></p>
                    <p>To the maximum extent permitted by law, ShuttleSync shall not be liable for any indirect, incidental, or consequential damages arising from your use of the platform, including but not limited to loss of bookings, data, or revenue.</p>
                </div>

                <div>
                    <p><span class="term-num">8.</span> <strong class="text-gray-900">Termination</strong></p>
                    <p>We may suspend or terminate your account if you violate these Terms. You may close your account at any time by contacting support.</p>
                </div>

                <div>
                    <p><span class="term-num">9.</span> <strong class="text-gray-900">Changes to These Terms</strong></p>
                    <p>We may update these Terms from time to time. Continued use of the platform after changes are posted constitutes acceptance of the revised Terms.</p>
                </div>

                <div>
                    <p><span class="term-num">10.</span> <strong class="text-gray-900">Contact</strong></p>
                    <p>For questions about these Terms, contact us at <a href="mailto:support@hipowerbc.xyz" class="text-brand-blue hover:underline font-medium">support@hipowerbc.xyz</a>.</p>
                </div>
            </section>

            <hr class="my-12 border-gray-100">

            <section id="privacy" class="section-box space-y-5 text-[15px] leading-relaxed text-gray-600">
                <h2 class="text-xl font-bold text-gray-900 pt-2">Privacy Policy</h2>

                <p>Your privacy matters to us. This policy explains what information we collect, how we use it, and the choices you have.</p>

                <div>
                    <p><span class="term-num">1.</span> <strong class="text-gray-900">Information We Collect</strong></p>
                    <p>We collect the information you provide when registering, such as your name, email address, contact details, and skill level. When you book or purchase, we collect booking and transaction records.</p>
                </div>

                <div>
                    <p><span class="term-num">2.</span> <strong class="text-gray-900">Media &amp; AI Analysis</strong></p>
                    <p>When you upload images or videos for AI analysis, we process them solely to generate your movement insights. Media is used only to provide the requested analysis and is not sold or shared with third parties.</p>
                </div>

                <div>
                    <p><span class="term-num">3.</span> <strong class="text-gray-900">Payments</strong></p>
                    <p>Payment details are handled by our third-party payment processors. We do not store card numbers or sensitive payment credentials on our servers.</p>
                </div>

                <div>
                    <p><span class="term-num">4.</span> <strong class="text-gray-900">How We Use Data</strong></p>
                    <p>We use your data to manage your account, process bookings and payments, deliver AI analysis, improve our services, and send service-related communications. We never sell your personal data.</p>
                </div>

                <div>
                    <p><span class="term-num">5.</span> <strong class="text-gray-900">Third-Party Services</strong></p>
                    <p>We may use trusted third-party providers (e.g., payment gateways, analytics, Cloudflare) who process data only under our instruction.</p>
                </div>

                <div>
                    <p><span class="term-num">6.</span> <strong class="text-gray-900">Cookies &amp; Local Storage</strong></p>
                    <p>We use cookies and local storage to keep you signed in and remember your preferences. You can disable cookies in your browser, though some features may not work.</p>
                </div>

                <div>
                    <p><span class="term-num">7.</span> <strong class="text-gray-900">Data Security</strong></p>
                    <p>Passwords are stored as irreversible hashes, and access to personal data is restricted to authorized personnel only.</p>
                </div>

                <div>
                    <p><span class="term-num">8.</span> <strong class="text-gray-900">Your Rights</strong></p>
                    <p>You may request access to, correction of, or deletion of your personal data at any time by contacting <a href="mailto:support@hipowerbc.xyz" class="text-brand-blue hover:underline font-medium">support@hipowerbc.xyz</a>.</p>
                </div>
            </section>
        </div>

        <p class="text-center text-xs text-gray-400 mt-8">&copy; 2026 ShuttleSync. All rights reserved.</p>
    </main>
</body>
</html>