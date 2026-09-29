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
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link href="<?= $appAssetBase ?>css/edsm.css" rel="stylesheet">
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

<header class="edsm-topbar">
    <div class="edsm-topbar-inner">
        <a href="<?= htmlspecialchars($appHomeUrl ?? '#') ?>" class="edsm-brand" title="الرئيسية">
            <img src="<?= $appAssetBase ?>img/edsm-icon-light.png" alt="EDSM">
            <div>
                <div class="edsm-brand-word">EDS<span>M</span></div>
                <div class="edsm-brand-sub">Dental Clinic Management System</div>
            </div>
        </a>

        <div class="edsm-date" aria-label="التاريخ">
            <i class="far fa-calendar-alt"></i>
            <div>
                <span id="edsmDateDay"><?= $appDays[(int)date('w')] ?></span>
                <small id="edsmDateFull"><?= (int)date('j') . ' ' . $appMonths[(int)date('n')] . ' ' . date('Y') ?></small>
            </div>
        </div>

        <div class="edsm-actions">
            <details class="edsm-menu">
                <summary class="edsm-icon-btn" title="التنبيهات">
                    <i class="fas fa-bell"></i>
                    <?php if ($appNotificationTotal > 0): ?>
                        <span class="edsm-dot"><?= min(99, $appNotificationTotal) ?></span>
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

            <?php if (!empty($appSettingsUrl)): ?>
                <a href="<?= htmlspecialchars($appSettingsUrl) ?>" class="edsm-icon-btn" title="الإعدادات">
                    <i class="fas fa-cog"></i>
                </a>
            <?php endif; ?>

            <details class="edsm-menu">
                <summary class="edsm-user">
                    <span class="edsm-avatar"><i class="<?= htmlspecialchars($appUser['icon'] ?? 'fas fa-user') ?>"></i></span>
                    <span class="edsm-user-text">
                        <strong><?= htmlspecialchars($appUserName) ?></strong>
                        <small><?= htmlspecialchars($appUser['role'] ?? '') ?></small>
                    </span>
                    <i class="fas fa-chevron-down"></i>
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
        <h1><?= htmlspecialchars($pageTitle ?? '') ?></h1>
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
