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
    <title>نظام إدارة عيادة الأسنان</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Noto+Kufi+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Noto Kufi Arabic', sans-serif;
        }
        
        h1, h2 {
            font-family: 'Amiri', serif;
        }
        
        body {
            background-color: #f8f9fa;
            background-image: 
                linear-gradient(90deg, #e9ecef 1px, transparent 1px),
                linear-gradient(180deg, #e9ecef 1px, transparent 1px);
            background-size: 25px 25px;
            position: relative;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at center, transparent 0%, rgba(248, 249, 250, 0.8) 100%);
            pointer-events: none;
        }
        
        .classic-card {
            background: white;
            border: 1px solid #dee2e6;
            box-shadow: 
                0 2px 4px rgba(0,0,0,0.02),
                0 4px 8px rgba(0,0,0,0.03),
                0 8px 16px rgba(0,0,0,0.04),
                0 16px 32px rgba(0,0,0,0.05);
            position: relative;
        }
        
        .classic-card::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, #dee2e6, #f8f9fa, #dee2e6);
            z-index: -1;
            opacity: 0.5;
        }
        
        .header-pattern {
            background-color: #1e3a5f;
            background-image: 
                repeating-linear-gradient(45deg, transparent, transparent 10px, rgba(255,255,255,.05) 10px, rgba(255,255,255,.05) 20px),
                repeating-linear-gradient(-45deg, transparent, transparent 10px, rgba(255,255,255,.03) 10px, rgba(255,255,255,.03) 20px);
            position: relative;
            overflow: hidden;
        }
        
        .header-pattern::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #c9a961, #f4e4c1, #c9a961);
        }
        
        .classic-input {
            border: 1px solid #ced4da;
            background-color: #fff;
            transition: all 0.2s ease;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
        }
        
        .classic-input:focus {
            border-color: #1e3a5f;
            background-color: #fafbfc;
            box-shadow: 
                inset 0 1px 2px rgba(0,0,0,0.05),
                0 0 0 3px rgba(30, 58, 95, 0.05);
            outline: none;
        }
        
        .classic-button {
            background: linear-gradient(180deg, #2c5282 0%, #1e3a5f 100%);
            border: 1px solid #1a2f4e;
            color: white;
            font-weight: 500;
            letter-spacing: 0.5px;
            transition: all 0.2s ease;
            box-shadow: 
                0 2px 4px rgba(0,0,0,0.1),
                inset 0 1px 0 rgba(255,255,255,0.1);
        }
        
        .classic-button:hover {
            background: linear-gradient(180deg, #1e3a5f 0%, #152941 100%);
            transform: translateY(-1px);
            box-shadow: 
                0 4px 8px rgba(0,0,0,0.15),
                inset 0 1px 0 rgba(255,255,255,0.1);
        }
        
        .classic-button:active {
            transform: translateY(0);
            box-shadow: 
                0 1px 2px rgba(0,0,0,0.1),
                inset 0 1px 2px rgba(0,0,0,0.1);
        }
        
        .logo-badge {
            background: white;
            border: 3px solid #c9a961;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, #dee2e6, transparent);
            margin: 1.5rem 0;
        }
        
        .info-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            border-right: 3px solid #c9a961;
        }
        
        .error-box {
            background-color: #fff5f5;
            border: 1px solid #feb2b2;
            border-right: 3px solid #fc8181;
        }
        
        .success-box {
            background-color: #f0fdf4;
            border: 1px solid #86efac;
            border-right: 3px solid #22c55e;
        }
        
        .label-text {
            color: #495057;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { 
                opacity: 0; 
                transform: translateY(10px);
            }
            to { 
                opacity: 1; 
                transform: translateY(0);
            }
        }
        
        .tooth-icon-classic {
            fill: #1e3a5f;
        }
        
        .decorative-line {
            width: 60px;
            height: 3px;
            background: linear-gradient(90deg, #c9a961, #f4e4c1);
            margin: 0 auto;
        }
        
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            pointer-events: none;
        }
        
        .input-with-icon {
            padding-left: 40px;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center py-12 px-4">
    <div class="container mx-auto max-w-md relative z-10">
        <!-- Logo and Title Section -->
        <div class="text-center mb-8 fade-in">
            <div class="logo-badge w-24 h-24 rounded-full mx-auto mb-4 flex items-center justify-center">
                <svg class="w-14 h-14 tooth-icon-classic" viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.94-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-800 mb-2">عيادة الأسنان المتطورة</h1>
            <div class="decorative-line mb-2"></div>
            <p class="text-gray-600 text-sm mt-2">نظام إدارة العيادة الطبية</p>
        </div>
        
        <!-- Main Login Card -->
        <div class="classic-card rounded-lg overflow-hidden fade-in">
            <!-- Card Header -->
            <div class="header-pattern px-8 py-6">
                <h2 class="text-2xl font-bold text-white text-center">تسجيل الدخول</h2>
                <p class="text-gray-200 text-sm text-center mt-1">أدخل بياناتك للوصول إلى النظام</p>
            </div>
            
            <!-- Card Body -->
            <div class="p-8">
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="error-box px-4 py-3 rounded mb-6 fade-in">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-red-500 ml-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <span class="text-red-700 text-sm"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="success-box px-4 py-3 rounded mb-6 fade-in">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-green-500 ml-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span class="text-green-700 text-sm"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form action="auth/login.php" method="POST" class="space-y-5">
                    <!-- Username Field -->
                    <div>
                        <label class="label-text">
                            اسم المستخدم
                        </label>
                        <div class="relative">
                            <span class="input-icon">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                                </svg>
                            </span>
                            <input type="text" name="username" required
                                   class="classic-input input-with-icon w-full py-3 rounded text-gray-700"
                                   placeholder="أدخل اسم المستخدم">
                        </div>
                    </div>
                    
                    <!-- Password Field -->
                    <div>
                        <label class="label-text">
                            كلمة المرور
                        </label>
                        <div class="relative">
                            <span class="input-icon">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                                </svg>
                            </span>
                            <input type="password" name="password" required
                                   class="classic-input input-with-icon w-full py-3 rounded text-gray-700"
                                   placeholder="أدخل كلمة المرور">
                        </div>
                    </div>
                    
                    <!-- Hidden Role Field - Will be determined from database -->
                    <!-- تم إزالة حقل اختيار نوع المستخدم لأنه سيتم جلبه من قاعدة البيانات -->
                    
                    <div class="divider"></div>
                    
                    <!-- Submit Button -->
                    <button type="submit" 
                            class="classic-button w-full py-3 px-4 rounded text-white">
                        <svg class="w-5 h-5 inline-block ml-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M3 3a1 1 0 011 1v12a1 1 0 11-2 0V4a1 1 0 011-1zm7.707 3.293a1 1 0 010 1.414L9.414 9H17a1 1 0 110 2H9.414l1.293 1.293a1 1 0 01-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                        دخول إلى النظام
                    </button>
                </form>
                
                <!-- Demo Credentials -->
                <div class="mt-6">
                    <div class="info-box p-4 rounded">
                        <h3 class="text-xs font-semibold text-gray-700 mb-3 flex items-center">
                            <svg class="w-4 h-4 ml-2 text-gray-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                            </svg>
                            بيانات الدخول التجريبية
                        </h3>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-gray-600 font-medium">حساب الطبيب:</span>
                                <code class="bg-white px-2 py-1 rounded text-gray-800" dir="ltr">doctor1 / password</code>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-gray-600 font-medium">حساب الممرضة:</span>
                                <code class="bg-white px-2 py-1 rounded text-gray-800" dir="ltr">nurse1 / password</code>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-gray-600 font-medium">حساب الإدارة:</span>
                                <code class="bg-white px-2 py-1 rounded text-gray-800" dir="ltr">admin / password</code>
                            </div>
                        </div>
                        <div class="mt-3 pt-3 border-t border-gray-200">
                            <p class="text-xs text-gray-500 text-center">
                                سيتم تحديد نوع الحساب تلقائياً من قاعدة البيانات
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="text-center mt-6">
            <p class="text-xs text-gray-500">© 2024 عيادة الأسنان المتطورة - جميع الحقوق محفوظة</p>
            <p class="text-xs text-gray-400 mt-1">نظام إدارة العيادات الطبية - الإصدار 2.0</p>
        </div>
    </div>
</body>
</html>