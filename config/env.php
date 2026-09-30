<?php
// تحميل متغيرات البيئة من ملف .env
function loadEnv($path) {
    if (!is_readable($path)) {
        die('ملف الإعدادات .env غير موجود. انسخ .env.example إلى .env وعدّل القيم.');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = array_map('trim', explode('=', $line, 2));

        // إزالة علامات التنصيص المحيطة بالقيمة
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
    }
}

// دالة مساعدة لقراءة متغير بيئة مع قيمة افتراضية
function env($key, $default = null) {
    return array_key_exists($key, $_ENV) ? $_ENV[$key] : $default;
}

loadEnv(__DIR__ . '/../.env');
