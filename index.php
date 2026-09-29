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
            --edsm-navy: #0A3D7A;
            --edsm-navy-deep: #0A2A5E;
            --edsm-teal: #14B8A6;
            --edsm-text: #1F2937;
            --edsm-muted: #64748B;
        }
        * { font-family: 'Almarai', 'Segoe UI', Tahoma, sans-serif; }
        .latin { font-family: 'Montserrat', 'Almarai', sans-serif; }
        body { background: #F5F9FE; color: var(--edsm-text); }

        /* wordmark */
        .wordmark { font-family: 'Montserrat', sans-serif; font-weight: 800; direction: ltr; line-height: 1; letter-spacing: 1px; color: var(--edsm-navy); }
        .wordmark span { color: var(--edsm-teal); }
        .wordmark-sub { font-family: 'Montserrat', sans-serif; direction: ltr; color: var(--edsm-navy); font-weight: 500; }

        /* ---------- art panel (left) ---------- */
        .art {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, #F7FBFF 0%, #EFF6FD 45%, #E7F1FB 100%);
        }
        .art-photo {
            position: absolute;
            inset-inline: 0;
            bottom: 0;
            height: 64%;
            background: url('assets/img/login-clinic.jpg') center bottom / cover no-repeat;
        }
        .art-photo::before { /* blend the photo into the light top area */
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, #F0F7FE 0%, rgba(240, 247, 254, 0.55) 22%, rgba(240, 247, 254, 0) 45%);
        }
        .art-wave { position: absolute; inset-inline: 0; bottom: 0; width: 100%; height: 34%; }
        .art-features {
            position: absolute;
            inset-inline: 0;
            bottom: 34px;
            display: flex;
            justify-content: center;
            gap: 0;
            color: #fff;
            z-index: 2;
        }
        .art-feature { display: flex; align-items: center; gap: 12px; padding: 0 28px; font-size: 14.5px; line-height: 1.35; }
        .art-feature + .art-feature { border-inline-start: 1px solid rgba(255, 255, 255, 0.35); }
        .art-feature i { font-size: 26px; opacity: 0.95; }
        .tagline { color: var(--edsm-blue); }
        .tagline-bar { width: 70px; height: 4px; border-radius: 4px; background: var(--edsm-teal); }
        .brand-rule { flex: 1; height: 1px; background: #94A3B8; opacity: 0.6; }

        /* ---------- form side (right) ---------- */
        .form-side { position: relative; overflow: hidden; }
        .form-side::before { /* large faint tooth, top corner */
            content: '';
            position: absolute;
            top: -40px;
            inset-inline-start: -60px;
            width: 320px;
            height: 300px;
            background: url('assets/img/edsm-icon.png') no-repeat center / contain;
            opacity: 0.05;
            pointer-events: none;
        }
        .login-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(11, 94, 215, 0.10), 0 2px 8px rgba(15, 23, 42, 0.04);
            border: 1px solid #E6EEF8;
        }
        .field {
            border: 1.5px solid #DCE6F2;
            background: #fff;
            border-radius: 12px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .field:focus { outline: none; border-color: var(--edsm-blue); box-shadow: 0 0 0 4px rgba(11, 94, 215, 0.12); }
        .field-icon { position: absolute; inset-inline-start: 16px; top: 50%; transform: translateY(-50%); color: #64748B; pointer-events: none; font-size: 17px; }
        .btn-brand {
            background: linear-gradient(to left, var(--edsm-teal), var(--edsm-blue));
            border-radius: 12px;
            box-shadow: 0 12px 24px rgba(11, 94, 215, 0.22);
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }
        .btn-brand:hover { transform: translateY(-1px); box-shadow: 0 16px 30px rgba(11, 94, 215, 0.3); filter: brightness(1.05); }
        .btn-brand:active { transform: none; }
        .demo summary { list-style: none; cursor: pointer; }
        .demo summary::-webkit-details-marker { display: none; }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
    </style>
</head>
<body class="min-h-screen">
    <div class="min-h-screen grid md:grid-cols-2">
        <!-- Form side (right in RTL) -->
        <main class="form-side flex flex-col items-center justify-center px-5 py-10 md:px-10">
            <div class="login-card w-full max-w-md p-8 sm:p-12 fade-in relative">
                <!-- Logo -->
                <div class="flex items-center justify-center gap-3 mb-8" dir="ltr">
                    <img src="assets/img/edsm-icon.png" alt="" class="w-16 h-auto">
                    <div>
                        <div class="wordmark text-4xl">EDS<span>M</span></div>
                        <div class="wordmark-sub text-[10px] mt-1">Dental Clinic Management System</div>
                    </div>
                </div>

                <div class="text-center mb-8">
                    <h1 class="text-2xl font-extrabold text-gray-800 flex items-center justify-center gap-3">
                        مرحباً بعودتك
                        <i class="far fa-hand text-[#0B5ED7] text-2xl"></i>
                    </h1>
                    <p class="text-gray-500 mt-2">قم بتسجيل الدخول للمتابعة</p>
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

                <form action="auth/login.php" method="POST" class="space-y-4">
                    <div class="relative">
                        <label for="username" class="sr-only">اسم المستخدم</label>
                        <i class="far fa-user field-icon"></i>
                        <input type="text" id="username" name="username" required autocomplete="username" autofocus
                               class="field w-full py-3.5 ps-12 pe-4 text-gray-800" placeholder="اسم المستخدم">
                    </div>

                    <div class="relative">
                        <label for="password" class="sr-only">كلمة المرور</label>
                        <i class="fas fa-lock field-icon"></i>
                        <input type="password" id="password" name="password" required autocomplete="current-password"
                               class="field w-full py-3.5 ps-12 pe-4 text-gray-800" placeholder="كلمة المرور">
                    </div>

                    <!-- نوع الحساب يُحدَّد تلقائياً من قاعدة البيانات -->
                    <button type="submit" class="btn-brand w-full py-3.5 px-4 text-white font-bold text-lg flex items-center justify-center gap-3 !mt-6">
                        <i class="fas fa-arrow-right"></i>
                        تسجيل الدخول
                    </button>
                </form>

                <!-- Demo Credentials (مطويّة) -->
                <details class="demo mt-6 text-xs">
                    <summary class="text-center text-[#0B5ED7] font-semibold hover:underline">
                        <i class="fas fa-info-circle ml-1"></i>بيانات الدخول التجريبية
                    </summary>
                    <div class="mt-3 bg-slate-50 border border-slate-200 rounded-xl p-3 space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600 font-medium">حساب الطبيب:</span>
                            <code class="bg-white border border-slate-200 px-2 py-1 rounded-lg text-gray-800" dir="ltr">doctor1 / password</code>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600 font-medium">حساب الممرضة:</span>
                            <code class="bg-white border border-slate-200 px-2 py-1 rounded-lg text-gray-800" dir="ltr">nurse1 / password</code>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600 font-medium">حساب الإدارة:</span>
                            <code class="bg-white border border-slate-200 px-2 py-1 rounded-lg text-gray-800" dir="ltr">admin / password</code>
                        </div>
                    </div>
                </details>
            </div>

            <footer class="text-center text-xs text-gray-400 mt-8 leading-6">
                <div class="latin" dir="ltr">© <?= date('Y') ?> EDSM - Dental Clinic Management System</div>
                <div>جميع الحقوق محفوظة</div>
            </footer>
        </main>

        <!-- Art panel (left in RTL) -->
        <aside class="art hidden md:block min-h-screen" aria-hidden="true">
            <div class="art-photo"></div>
            <svg class="art-wave" viewBox="0 0 800 280" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="waveNavy" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stop-color="#0B5ED7"/>
                        <stop offset="1" stop-color="#0A2A5E"/>
                    </linearGradient>
                    <linearGradient id="waveTeal" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0" stop-color="#14B8A6" stop-opacity="0.9"/>
                        <stop offset="1" stop-color="#5EEAD4" stop-opacity="0.55"/>
                    </linearGradient>
                </defs>
                <path d="M0,70 C170,20 330,150 520,120 C650,100 730,130 800,150 L800,280 L0,280 Z" fill="#0B5ED7" opacity="0.35"/>
                <path d="M0,110 C190,60 340,190 540,160 C660,142 740,168 800,185 L800,280 L0,280 Z" fill="url(#waveTeal)"/>
                <path d="M0,140 C200,95 350,215 560,190 C670,178 745,196 800,210 L800,280 L0,280 Z" fill="url(#waveNavy)"/>
            </svg>

            <div class="relative z-10 px-12 pt-16 lg:pt-24">
                <!-- Logo -->
                <div class="flex items-center justify-center gap-4" dir="ltr">
                    <img src="assets/img/edsm-icon.png" alt="" class="w-28 lg:w-32 h-auto">
                    <div>
                        <div class="wordmark text-6xl lg:text-7xl">EDS<span>M</span></div>
                        <div class="wordmark-sub text-base lg:text-lg mt-2">Dental Clinic Management System</div>
                        <div class="flex items-center gap-3 mt-1" dir="rtl">
                            <span class="brand-rule"></span>
                            <span class="text-gray-500 text-base">برنامج إدارة عيادة الأسنان</span>
                            <span class="brand-rule"></span>
                        </div>
                    </div>
                </div>

                <!-- Tagline -->
                <div class="mt-12 lg:mt-16 max-w-md mx-auto">
                    <h2 class="tagline text-3xl lg:text-4xl font-bold leading-relaxed">إدارة أسهل ..<br>لابتسامات أكثر</h2>
                    <div class="tagline-bar mt-4"></div>
                </div>
            </div>

            <div class="art-features">
                <div class="art-feature"><i class="fas fa-tooth"></i><span>إدارة متكاملة<br>للعيادات</span></div>
                <div class="art-feature"><i class="fas fa-cloud"></i><span>وصول من أي مكان<br>وفي أي وقت</span></div>
                <div class="art-feature"><i class="fas fa-shield-alt"></i><span>أمان عالي<br>لبياناتك</span></div>
            </div>
        </aside>
    </div>
</body>
</html>
