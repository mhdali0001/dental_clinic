<?php
// ترويسة EDSM الموحّدة (الشريط العلوي + التنقل + عنوان الصفحة) لكل الأدوار.
// تُستدعى من ترويسة كل دور بعد تجهيز:
//   $appNav          [key => ['url','icon','label','badge', 'notify' (اختياري)]]
//   $appCurrent      مفتاح الصفحة الحالية في $appNav
//   $appHomeUrl      رابط الرئيسية
//   $appUser         ['name' => ..., 'role' => ..., 'icon' => أيقونة الدور]
//   $appLogoutUrl    رابط الخروج، $appLogoutConfirm (اختياري) لطلب تأكيد
//   $appSettingsUrl  (اختياري) رابط الإعدادات
//   $appQuickActions (اختياري) [['url','icon','title']] أزرار سريعة على الشاشات الصغيرة
// ومتغيرات الصفحة المعتادة: $pageTitle, $pageIcon, $pageSubtitle, $breadcrumbs

$appAssetBase = '../assets/';
$appNav = $appNav ?? [];
$appCurrent = $appCurrent ?? '';
$appQuickActions = $appQuickActions ?? [];
$appUserName = trim($appUser['name'] ?? '');

// الإشعارات = عناصر التنقل التي تحمل عدّاداً (إلا ما عُلِّم notify => false لأنه عدد وليس تنبيهاً)
$appNotifications = array_filter($appNav, fn($item) => !empty($item['badge']) && ($item['notify'] ?? true));
$appNotificationTotal = array_sum(array_map(fn($item) => (int)$item['badge'], $appNotifications));

// تاريخ احتياطي من الخادم؛ يُستبدل بتاريخ جهاز المستخدم عبر JavaScript
$appDays = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
$appMonths = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=Almarai:wght@400;700;800&display=swap" rel="stylesheet">
<link href="<?= $appAssetBase ?>css/edsm.css?v=<?= filemtime(__DIR__ . '/../assets/css/edsm.css') ?>" rel="stylesheet">
<script>
    // الأيقونة في تبويب المتصفح (الصفحات لا تضعها في <head>)
    (function () {
        var link = document.querySelector("link[rel~='icon']") || document.createElement('link');
        link.rel = 'icon';
        link.type = 'image/png';
        link.href = '<?= $appAssetBase ?>img/favicon.png';
        document.head.appendChild(link);
    })();
</script>

<div class="edsm-topbar-wrap">
<header class="edsm-topbar">
    <!-- الأشكال المائلة في الخلفية (design/New Style.png) -->
    <div class="edsm-topbar-bg" aria-hidden="true">
        <div class="edsm-topbar-bg-inner">
            <span class="edsm-topbar-shape s-mid"></span>
            <span class="edsm-topbar-shape s-band"></span>
            <span class="edsm-topbar-shape s-edge"></span>
        </div>
    </div>

    <div class="edsm-topbar-inner">
        <a href="<?= htmlspecialchars($appHomeUrl ?? '#') ?>" class="edsm-brand" title="الرئيسية">
            <img src="<?= $appAssetBase ?>img/edsm-icon-light.png" alt="">
            <span class="edsm-brand-text">
                <span class="edsm-brand-word">EDS<span>M</span></span>
                <span class="edsm-brand-sub">Dental Clinic Management System</span>
            </span>
        </a>

        <div class="edsm-tools">
            <div class="edsm-date" aria-label="التاريخ">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3.5" y="5" width="17" height="15.5" rx="2.6"/>
                    <path d="M8 3v4M16 3v4M3.5 10h17"/>
                    <g fill="currentColor" stroke="none">
                        <rect x="8.4" y="12.4" width="3" height="2.6" rx=".6"/><rect x="12.6" y="12.4" width="3" height="2.6" rx=".6"/>
                        <rect x="8.4" y="16.1" width="3" height="2.6" rx=".6"/><rect x="12.6" y="16.1" width="3" height="2.6" rx=".6"/>
                    </g>
                </svg>
                <div>
                    <span class="edsm-date-day" id="edsmDateDay"><?= $appDays[(int)date('w')] ?></span>
                    <span class="edsm-date-full" id="edsmDateFull"><?= (int)date('j') . ' ' . $appMonths[(int)date('n')] . ' ' . date('Y') ?></span>
                </div>
            </div>
            <span class="edsm-sep" aria-hidden="true"></span>

            <div class="edsm-tool-group">
                <?php if (!empty($appSettingsUrl)): ?>
                    <a href="<?= htmlspecialchars($appSettingsUrl) ?>" class="edsm-tool-btn" title="الإعدادات" aria-label="الإعدادات">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37 1 .608 2.296.07 2.572-1.065z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </a>
                <?php endif; ?>

                <details class="edsm-menu">
                    <summary class="edsm-tool-btn" title="التنبيهات" aria-label="التنبيهات">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M10 5a2 2 0 1 1 4 0 7 7 0 0 1 4 6v3a4 4 0 0 0 2 3H4a4 4 0 0 0 2-3v-3a7 7 0 0 1 4-6"/>
                            <path d="M9 17v1a3 3 0 0 0 6 0v-1"/>
                        </svg>
                        <?php if ($appNotificationTotal > 0): ?>
                            <span class="edsm-badge"><?= min(99, $appNotificationTotal) ?></span>
                        <?php endif; ?>
                    </summary>
                    <div class="edsm-menu-panel">
                        <?php if (empty($appNotifications)): ?>
                            <div class="edsm-menu-empty"><i class="fas fa-check-circle ml-1"></i> لا توجد تنبيهات جديدة</div>
                        <?php else: ?>
                            <?php foreach ($appNotifications as $item): ?>
                                <a href="<?= htmlspecialchars($item['url']) ?>">
                                    <i class="<?= $item['icon'] ?> text-blue-600"></i>
                                    <?= htmlspecialchars($item['label']) ?>
                                    <span class="edsm-menu-count"><?= min(99, (int)$item['badge']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
            <span class="edsm-sep" aria-hidden="true"></span>

            <details class="edsm-menu">
                <summary class="edsm-user" aria-label="حساب المستخدم">
                    <span class="edsm-user-text">
                        <strong><?= htmlspecialchars($appUserName) ?></strong>
                        <small><?= htmlspecialchars($appUser['role'] ?? '') ?></small>
                    </span>
                    <svg class="edsm-avatar" viewBox="0 0 40 40" aria-hidden="true">
                        <defs>
                            <clipPath id="edsmAvatarClip"><circle cx="20" cy="20" r="20"/></clipPath>
                            <linearGradient id="edsmAvatarBg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#A9D3FE"/><stop offset="1" stop-color="#8EBEF6"/></linearGradient>
                        </defs>
                        <g clip-path="url(#edsmAvatarClip)">
                            <rect width="40" height="40" fill="url(#edsmAvatarBg)"/>
                            <circle cx="20" cy="15.6" r="7.2" fill="#fff"/>
                            <path d="M4.5 40c.9-8.2 7.3-13.3 15.5-13.3S34.6 31.8 35.5 40z" fill="#fff"/>
                        </g>
                    </svg>
                    <svg class="edsm-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </summary>
                <div class="edsm-menu-panel">
                    <?php if (!empty($appSettingsUrl)): ?>
                        <a href="<?= htmlspecialchars($appSettingsUrl) ?>"><i class="fas fa-cog text-gray-500"></i> الإعدادات</a>
                        <hr>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($appLogoutUrl ?? '../logout.php') ?>" class="edsm-danger"
                       <?= !empty($appLogoutConfirm) ? 'onclick="if (!confirm(\'هل تريد تسجيل الخروج؟\')) return false; try { sessionStorage.clear(); } catch (e) {}"' : '' ?>>
                        <i class="fas fa-sign-out-alt"></i> تسجيل الخروج
                    </a>
                </div>
            </details>
        </div>
    </div>
</header>
</div>

<nav class="edsm-nav" aria-label="التنقل الرئيسي">
    <div class="edsm-nav-inner">
        <?php foreach ($appNav as $key => $item): ?>
            <a href="<?= htmlspecialchars($item['url']) ?>" class="edsm-nav-item <?= $appCurrent === $key ? 'active' : '' ?>"
               <?= $appCurrent === $key ? 'aria-current="page"' : '' ?>>
                <i class="<?= $item['icon'] ?>"></i>
                <span><?= htmlspecialchars($item['label']) ?></span>
                <?php if (!empty($item['badge'])): ?>
                    <span class="edsm-nav-badge"><?= min(99, (int)$item['badge']) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>

        <?php if ($appQuickActions): ?>
            <div class="edsm-nav-quick">
                <?php foreach ($appQuickActions as $quick): ?>
                    <a href="<?= htmlspecialchars($quick['url']) ?>" title="<?= htmlspecialchars($quick['title']) ?>"><i class="<?= $quick['icon'] ?>"></i></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</nav>

<div class="edsm-page-head">
    <div class="edsm-page-icon"><i class="<?= $pageIcon ?? 'fas fa-tooth' ?>"></i></div>
    <div>
        <h1><?= $pageTitleHtml ?? htmlspecialchars($pageTitle ?? '') /* $pageTitleHtml: عنوان منسّق جاهز (مُهرَّب مسبقاً) */ ?></h1>
        <?php if (!empty($breadcrumbs)): ?>
            <div class="edsm-crumbs">
                <a href="<?= htmlspecialchars($appHomeUrl ?? '#') ?>">الرئيسية</a>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <i class="fas fa-chevron-left"></i>
                    <?php if (isset($crumb['url'])): ?>
                        <a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['title']) ?></a>
                    <?php else: ?>
                        <span><?= htmlspecialchars($crumb['title']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php elseif (!empty($pageSubtitle)): ?>
            <p><?= htmlspecialchars($pageSubtitle) ?></p>
        <?php endif; ?>
    </div>
    <?php if (!empty($pageHeadActions)): /* HTML جاهز من الصفحة (مثل مربع البحث) */ ?>
        <div class="edsm-page-head-actions"><?= $pageHeadActions ?></div>
    <?php endif; ?>
</div>

<script>
    (function () {
        // التاريخ حسب ساعة جهاز المستخدم (الخادم يعمل بتوقيت UTC)
        try {
            var now = new Date();
            document.getElementById('edsmDateDay').textContent = now.toLocaleDateString('ar-u-nu-latn', { weekday: 'long' });
            document.getElementById('edsmDateFull').textContent = now.toLocaleDateString('ar-u-nu-latn', { day: 'numeric', month: 'long', year: 'numeric' });
        } catch (e) { /* keep server date */ }

        // إغلاق القوائم المنسدلة عند النقر خارجها أو عند فتح قائمة أخرى
        document.addEventListener('click', function (e) {
            document.querySelectorAll('details.edsm-menu[open]').forEach(function (menu) {
                if (!menu.contains(e.target)) menu.removeAttribute('open');
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') document.querySelectorAll('details.edsm-menu[open]').forEach(function (m) { m.removeAttribute('open'); });
        });
    })();
</script>
