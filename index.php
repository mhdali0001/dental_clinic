<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// تحويل المستخدمين المسجلين إلى الصفحة المناسبة
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] == 'doctor') {
        header('Location: doctor/dashboard.php');
        exit;
    } elseif ($_SESSION['user_role'] == 'nurse') {
        header('Location: nurse/dashboard.php');
        exit;
    } elseif ($_SESSION['user_role'] == 'admin') {
        header('Location: admin/dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDSM - نظام إدارة عيادة الأسنان</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=Almarai:wght@400;700;800&display=swap" rel="stylesheet">
    <style>
        /* تصميم صفحة الدخول: design/Login Page.png — ألوان الهوية: design/Visual Identity.png */
        :root {
            --edsm-blue: #0B5ED7;
            --edsm-navy: #06326B;
            --edsm-teal: #06A89F;
            --edsm-text: #1F2937;
            --edsm-muted: #7992B0;
            --edsm-line: #D5E2F0;
        }
        * { font-family: 'Almarai', 'Segoe UI', Tahoma, sans-serif; }
        .latin { font-family: 'Montserrat', 'Almarai', sans-serif; }
        body { background: #F7FAFD; color: var(--edsm-text); }
        .ico { fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }

        /* wordmark */
        .wordmark { font-family: 'Montserrat', sans-serif; font-weight: 800; direction: ltr; line-height: 1; letter-spacing: 0.5px; color: var(--edsm-navy); }
        .wordmark span { color: var(--edsm-teal); }
        .wordmark-sub { font-family: 'Montserrat', sans-serif; direction: ltr; color: #012C60; font-weight: 500; white-space: nowrap; }

        /* ---------- art panel (left) ----------
           أبعاد التصميم مرجعها شاشة 1536×1024؛ --dp = بكسل واحد من التصميم */
        .art {
            --dp: clamp(0.6px, min(calc(100vw / 1536), calc(100vh / 1024)), 1.35px);
            --sp: calc(50vw / 788); /* مقياس المشهد السفلي (عرض اللوحة 788 في التصميم) */
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, #FBFDFE 0%, #F3F8FC 55%, #EDF5FB 100%);
        }
        .art-head { position: relative; z-index: 2; padding-top: calc(150 * var(--dp)); text-align: center; }
        .art-brand { display: inline-flex; flex-direction: column; text-align: right; }
        .art-lockup { display: flex; align-items: center; gap: calc(14 * var(--dp)); }
        .art-lockup img { width: calc(140 * var(--dp)); height: auto; }
        .art-lockup .wordmark { font-size: calc(97 * var(--dp)); }
        .art-lockup .wordmark-sub { font-size: calc(17.5 * var(--dp)); margin-top: calc(10 * var(--dp)); }
        .art-ar { display: flex; align-items: center; gap: calc(10 * var(--dp)); margin-top: calc(6 * var(--dp)); direction: rtl; color: #365A84; font-size: calc(17 * var(--dp)); }
        .art-ar::before, .art-ar::after { content: ''; flex: 1; height: 1px; background: #C9D5E2; }
        .tagline {
            align-self: flex-end;
            margin: calc(46 * var(--dp)) 0 0 calc(14 * var(--dp));
            color: #0759B4;
            font-size: calc(33 * var(--dp));
            font-weight: 700;
            line-height: 1.4;
        }
        .tagline-bar { width: calc(70 * var(--dp)); height: calc(4 * var(--dp)); min-height: 3px; border-radius: 4px; background: #0CB2AD; margin: calc(20 * var(--dp)) auto 0 0; }

        .art-scene { position: absolute; inset-inline: 0; bottom: 0; height: min(calc(542 * var(--sp)), 58vh); }
        .art-scene > svg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            -webkit-mask-image: linear-gradient(180deg, transparent 0, #000 24%);
            mask-image: linear-gradient(180deg, transparent 0, #000 24%);
        }
        .art-features {
            position: absolute;
            left: 7%;
            bottom: calc(79 * var(--sp));
            display: flex;
            color: #fff;
            font-size: max(11px, calc(13.5 * var(--sp)));
            line-height: 1.5;
        }
        .art-feature { display: flex; align-items: center; gap: calc(12 * var(--sp)); padding: 0 calc(36 * var(--sp)); }
        .art-feature:first-child { padding-right: 0; }
        .art-feature:last-child { padding-left: 0; }
        .art-feature:not(:last-child) { border-left: 1px solid rgba(255, 255, 255, 0.4); }
        .art-feature svg { width: max(20px, calc(28 * var(--sp))); height: max(20px, calc(28 * var(--sp))); flex-shrink: 0; stroke-width: 1.5; }

        /* ---------- form side (right) ---------- */
        .form-side { position: relative; overflow: hidden; }
        .form-side::before { /* large faint tooth, top corner */
            content: '';
            position: absolute;
            top: -50px;
            inset-inline-start: -70px;
            width: 300px;
            height: 280px;
            background: url('assets/img/edsm-icon.png') no-repeat center / contain;
            opacity: 0.045;
            filter: saturate(0.5);
            pointer-events: none;
        }
        .login-card {
            position: relative;
            width: 100%;
            max-width: 565px;
            padding: 44px 57px 46px;
            background: #fff;
            border-radius: 20px;
            border: 1px solid #EBF1F8;
            box-shadow: 0 22px 60px rgba(16, 70, 140, 0.08), 0 2px 6px rgba(15, 23, 42, 0.03);
        }
        .card-logo { display: flex; align-items: center; justify-content: center; gap: 6px; }
        .card-logo img { width: 76px; height: auto; }
        .card-logo .wordmark { font-size: 49px; }
        .card-logo .wordmark-sub { font-size: 9px; font-weight: 600; margin-top: 4px; letter-spacing: -0.1px; }
        .card-head { text-align: center; margin: 48px 0 36px; }
        .card-head h1 { display: flex; align-items: center; justify-content: center; gap: 14px; font-size: 26px; font-weight: 800; color: var(--edsm-navy); }
        .card-head h1 i { color: #1C6FD1; font-size: 27px; transform: rotate(-14deg); }
        .card-head p { margin-top: 10px; font-size: 17px; color: var(--edsm-muted); }

        .field-wrap { position: relative; }
        .field-wrap + .field-wrap { margin-top: 18px; }
        .field {
            width: 100%;
            height: 54px;
            padding: 0 52px 0 16px;
            border: 1.5px solid var(--edsm-line);
            background: #fff;
            border-radius: 10px;
            font-size: 15px;
            color: var(--edsm-text);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .field::placeholder { color: #8396AF; }
        .field:focus { outline: none; border-color: var(--edsm-blue); box-shadow: 0 0 0 4px rgba(11, 94, 215, 0.12); }
        .field-icon { position: absolute; right: 17px; top: 50%; width: 21px; height: 21px; transform: translateY(-50%); color: #7C97B3; pointer-events: none; }
        .field:focus ~ .field-icon { color: var(--edsm-blue); }

        .remember { display: inline-flex; align-items: center; gap: 10px; margin-top: 24px; font-size: 15px; color: #748EB0; cursor: pointer; user-select: none; }
        .remember input {
            appearance: none;
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            margin: 0;
            border: 1.5px solid #9CB2CA;
            border-radius: 4px;
            background: #fff center / 12px no-repeat;
            cursor: pointer;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }
        .remember input:checked {
            background-color: var(--edsm-blue);
            border-color: var(--edsm-blue);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12.5l4.5 4.5L19 7.5'/%3E%3C/svg%3E");
        }
        .remember input:focus-visible { outline: none; box-shadow: 0 0 0 4px rgba(11, 94, 215, 0.15); }

        .btn-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            height: 52px;
            margin-top: 26px;
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            background: linear-gradient(to left, #16B8B0, #0A6DC0);
            border-radius: 10px;
            box-shadow: 0 12px 24px rgba(10, 109, 192, 0.2);
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }
        .btn-brand svg { width: 20px; height: 20px; stroke-width: 2.2; }
        .btn-brand:hover { transform: translateY(-1px); box-shadow: 0 16px 30px rgba(10, 109, 192, 0.28); filter: brightness(1.05); }
        .btn-brand:active { transform: none; }
        .btn-brand:focus-visible { outline: none; box-shadow: 0 0 0 4px rgba(11, 94, 215, 0.25); }

        details summary { list-style: none; cursor: pointer; }
        details summary::-webkit-details-marker { display: none; }
        .forgot { margin-top: 26px; text-align: center; font-size: 14px; }
        .forgot summary { display: inline-block; color: #036ECC; font-weight: 700; }
        .forgot summary:hover { text-decoration: underline; }
        .forgot p { margin-top: 10px; padding: 10px 14px; font-size: 13px; color: #365A84; background: #F2F7FD; border: 1px solid #E1EBF6; border-radius: 10px; }

        .or-divider { display: flex; align-items: center; gap: 16px; margin: 30px 0 18px; font-size: 14px; font-weight: 700; color: #0E447A; }
        .or-divider::before, .or-divider::after { content: ''; flex: 1; height: 1px; background: #DDE6EF; }
        .btn-outline {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            height: 47px;
            border: 1.5px solid #D6E4F3;
            border-radius: 10px;
            color: #0C62C1;
            font-size: 14px;
            font-weight: 700;
            transition: border-color 0.2s ease, background-color 0.2s ease;
        }
        .btn-outline svg { width: 19px; height: 19px; stroke-width: 2; }
        .btn-outline:hover, .demo[open] .btn-outline { border-color: #B8D0EC; background: #F6FAFE; }
        .demo-list { margin-top: 10px; padding: 12px; font-size: 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; }
        .demo-list div { display: flex; justify-content: space-between; align-items: center; }
        .demo-list div + div { margin-top: 8px; }
        .demo-list code { background: #fff; border: 1px solid #E2E8F0; padding: 3px 8px; border-radius: 8px; color: var(--edsm-text); }

        .login-footer { margin-top: 56px; text-align: center; font-size: 12.5px; line-height: 20px; color: #91A5BC; }

        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

        @media (max-height: 860px) {
            .login-card { padding-top: 34px; padding-bottom: 34px; }
            .card-head { margin: 30px 0 26px; }
            .remember, .btn-brand { margin-top: 18px; }
            .forgot { margin-top: 18px; }
            .or-divider { margin: 20px 0 14px; }
            .login-footer { margin-top: 28px; }
        }
        @media (max-width: 1100px) {
            .login-card { padding-left: 32px; padding-right: 32px; }
        }
        @media (max-width: 480px) {
            .login-card { padding: 32px 22px; }
            .card-logo img { width: 62px; }
            .card-logo .wordmark { font-size: 40px; }
            .card-head { margin: 32px 0 28px; }
            .card-head h1 { font-size: 23px; }
        }
    </style>
</head>
<body class="min-h-screen">
    <div class="min-h-screen grid md:grid-cols-2">
        <!-- Form side (right in RTL) -->
        <main class="form-side flex flex-col items-center justify-center px-4 py-10 md:px-10">
            <div class="login-card fade-in">
                <!-- Logo -->
                <div class="card-logo" dir="ltr">
                    <img src="assets/img/edsm-icon.png" alt="">
                    <div>
                        <div class="wordmark">EDS<span>M</span></div>
                        <div class="wordmark-sub">Dental Clinic Management System</div>
                    </div>
                </div>

                <div class="card-head">
                    <h1><i class="far fa-hand" aria-hidden="true"></i>مرحباً بعودتك</h1>
                    <p>قم بتسجيل الدخول للمتابعة</p>
                </div>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="flex items-center gap-2 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">
                        <i class="fas fa-exclamation-circle text-red-500"></i>
                        <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="flex items-center gap-2 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm px-4 py-3 rounded-xl mb-5">
                        <i class="fas fa-check-circle text-emerald-500"></i>
                        <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                    </div>
                <?php endif; ?>

                <form id="loginForm" action="auth/login.php" method="POST">
                    <div class="field-wrap">
                        <label for="username" class="sr-only">اسم المستخدم</label>
                        <input type="text" id="username" name="username" required autocomplete="username" autofocus
                               class="field" placeholder="اسم المستخدم">
                        <svg class="field-icon ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M5.5 20.5v-1.5a4 4 0 0 1 4-4h5a4 4 0 0 1 4 4v1.5"/></svg>
                    </div>

                    <div class="field-wrap">
                        <label for="password" class="sr-only">كلمة المرور</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password"
                               class="field" placeholder="كلمة المرور">
                        <svg class="field-icon ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10.5" rx="2.5"/><path d="M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3"/><path d="M12 14.8v2"/></svg>
                    </div>

                    <!-- يحفظ اسم المستخدم في هذا المتصفح فقط -->
                    <label class="remember">
                        <input type="checkbox" id="remember">
                        <span>تذكرني</span>
                    </label>

                    <!-- نوع الحساب يُحدَّد تلقائياً من قاعدة البيانات -->
                    <button type="submit" class="btn-brand">
                        <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        تسجيل الدخول
                    </button>
                </form>

                <details class="forgot">
                    <summary>نسيت كلمة المرور؟</summary>
                    <p>لإعادة تعيين كلمة المرور يرجى التواصل مع مدير النظام.</p>
                </details>

                <div class="or-divider">أو</div>

                <!-- Demo Credentials (مطويّة) -->
                <details class="demo">
                    <summary class="btn-outline">
                        <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20v-1a4.5 4.5 0 0 1 4.5-4.5h4a4.5 4.5 0 0 1 4.5 4.5v1"/><path d="M15.5 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14.7a4.5 4.5 0 0 1 3 4.3v1"/></svg>
                        بيانات الدخول التجريبية
                    </summary>
                    <div class="demo-list">
                        <div>
                            <span class="text-gray-600 font-medium">حساب الطبيب:</span>
                            <code dir="ltr">doctor1 / password</code>
                        </div>
                        <div>
                            <span class="text-gray-600 font-medium">حساب الممرضة:</span>
                            <code dir="ltr">nurse1 / password</code>
                        </div>
                        <div>
                            <span class="text-gray-600 font-medium">حساب الإدارة:</span>
                            <code dir="ltr">admin / password</code>
                        </div>
                    </div>
                </details>
            </div>

            <footer class="login-footer">
                <div class="latin" dir="ltr">© <?= date('Y') ?> EDSM - Dental Clinic Management System</div>
                <div>جميع الحقوق محفوظة</div>
            </footer>
        </main>

        <!-- Art panel (left in RTL) -->
        <aside class="art hidden md:block min-h-screen" aria-hidden="true">
            <div class="art-head">
                <div class="art-brand">
                    <div class="art-lockup" dir="ltr">
                        <img src="assets/img/edsm-icon.png" alt="">
                        <div>
                            <div class="wordmark">EDS<span>M</span></div>
                            <div class="wordmark-sub">Dental Clinic Management System</div>
                            <div class="art-ar">برنامج إدارة عيادة الأسنان</div>
                        </div>
                    </div>

                    <div class="tagline">
                        <h2>إدارة أسـهل ..<br>لابتسامات أكثر</h2>
                        <div class="tagline-bar"></div>
                    </div>
                </div>
            </div>

            <!-- الصورة والأمواج بإحداثيات التصميم (788×542 أسفل اللوحة) -->
            <div class="art-scene">
                <svg viewBox="0 0 788 542" preserveAspectRatio="xMidYMax slice">
                    <defs>
                        <linearGradient id="waveSky" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0" stop-color="#2F95D0" stop-opacity="0.95"/>
                            <stop offset="0.3" stop-color="#6DBAE3" stop-opacity="0.85"/>
                            <stop offset="0.55" stop-color="#FFFFFF" stop-opacity="0.6"/>
                            <stop offset="1" stop-color="#FFFFFF" stop-opacity="0.45"/>
                        </linearGradient>
                        <linearGradient id="waveTeal" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0" stop-color="#5CCBCD"/>
                            <stop offset="0.5" stop-color="#1FAFB3"/>
                            <stop offset="1" stop-color="#0E8EA5"/>
                        </linearGradient>
                        <linearGradient id="waveNavy" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#135A94"/>
                            <stop offset="1" stop-color="#0B4478"/>
                        </linearGradient>
                    </defs>
                    <image href="assets/img/login-clinic.jpg" width="788" height="401" preserveAspectRatio="none"/>
                    <path fill="url(#waveSky)" d="M0,218 C140,198 240,272 360,322 C480,372 640,382 788,382 L788,542 L0,542 Z"/>
                    <path fill="url(#waveTeal)" d="M150,332 C300,322 420,362 560,392 C660,412 740,407 788,404 L788,542 L0,542 Z"/>
                    <path fill="url(#waveNavy)" d="M0,303 C130,292 250,330 390,383 C500,420 620,430 788,498 L788,542 L0,542 Z"/>
                </svg>

                <div class="art-features">
                    <div class="art-feature">
                        <span>إدارة متكاملة<br>لعيادتك</span>
                        <svg class="ico" viewBox="0 0 24 24"><path d="M12 5.5c-1.07-.59-2.58-1.5-4-1.5-2.1 0-4 1.25-4 5 0 4.9 1.06 8.41 2.67 10.54.57.75 2.37.09 2.83-1.02l.67-1.98c.31-.94 1.01-1.54 1.83-1.54s1.52.6 1.83 1.54l.67 1.98c.46 1.11 2.26 1.78 2.83 1.02C18.94 17.41 20 13.9 20 9c0-3.77-1.9-5-4-5-1.42 0-2.93.91-4 1.5z"/><path d="M12 5.5l3 1.5"/></svg>
                    </div>
                    <div class="art-feature">
                        <span>وصول من أي مكان<br>وفي أي وقت</span>
                        <svg class="ico" viewBox="0 0 24 24"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                    </div>
                    <div class="art-feature">
                        <span>أمان عالي<br>لبياناتك</span>
                        <svg class="ico" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 4.8-3.4 8-8 9-4.6-1-8-4.2-8-9V6l8-3z"/></svg>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <script>
        // تذكرني: حفظ اسم المستخدم في هذا المتصفح فقط (كلمة المرور لا تُحفظ)
        (function () {
            var key = 'edsm_login_username';
            var form = document.getElementById('loginForm');
            var username = document.getElementById('username');
            var remember = document.getElementById('remember');
            try {
                var saved = localStorage.getItem(key);
                if (saved) {
                    username.value = saved;
                    remember.checked = true;
                    document.getElementById('password').focus();
                }
            } catch (e) {}
            form.addEventListener('submit', function () {
                try {
                    if (remember.checked) localStorage.setItem(key, username.value.trim());
                    else localStorage.removeItem(key);
                } catch (e) {}
            });
        })();
    </script>
</body>
</html>
