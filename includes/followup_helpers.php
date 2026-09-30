<?php
// أدوات عرض المتابعات المشتركة بين صفحة المتابعات (doctor/follow_ups.php) وملف المريض

// أنواع المتابعة في النموذج؛ يُحفظ النص نفسه في follow_up_reason
const FOLLOWUP_KINDS = [
    'checkup'    => 'متابعة فحص دوري',
    'ortho'      => 'متابعة تقويم',
    'root'       => 'متابعة علاج عصب',
    'implant'    => 'متابعة زراعة',
    'filling'    => 'متابعة حشو',
    'extraction' => 'متابعة خلع',
    'cleaning'   => 'متابعة تنظيف',
    'prosthesis' => 'متابعة تركيبات',
    'whitening'  => 'متابعة تبييض',
];

const FOLLOWUP_STATUS_LABELS = ['active' => 'نشطة', 'overdue' => 'متأخرة', 'completed' => 'مكتملة', 'cancelled' => 'ملغاة'];
const FOLLOWUP_PRIORITY_LABELS = ['normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة', 'low' => 'منخفضة'];

// اسم الطبيب بصيغة "د. الاسم" (تُحذف "د." إن كانت ضمن الاسم المخزَّن)
function doctorLabel($name, $role = 'doctor') {
    $name = trim((string)$name);
    if ($name === '') {
        return '—';
    }
    return $role === 'doctor' ? 'د. ' . preg_replace('/^د\.\s*/u', '', $name) : $name;
}

// [مفتاح التصفية، النص المعروض] لنوع المتابعة
// الصف يحتاج: follow_up_reason, treatment_id, treatment_type (الاسم العربي), follow_up_type
function followupKind(array $row) {
    $reason = trim((string)($row['follow_up_reason'] ?? ''));
    $slug = array_search($reason, FOLLOWUP_KINDS, true);
    if ($slug !== false) {
        return [$slug, $reason];
    }
    if (!empty($row['treatment_id'])) {
        return ['treatment', !empty($row['treatment_type']) ? 'متابعة ' . $row['treatment_type'] : 'متابعة بعد علاج'];
    }
    if (($row['follow_up_type'] ?? '') === 'birthday') {
        return ['birthday', 'متابعة عيد الميلاد'];
    }
    return ['other', $reason !== '' ? $reason : 'متابعة عامة'];
}

// active | overdue | completed | cancelled
function followupState(array $row, $today) {
    if (in_array($row['status'], ['pending', 'rescheduled'], true)) {
        return $row['follow_up_date'] < $today ? 'overdue' : 'active';
    }
    return $row['status'];
}
