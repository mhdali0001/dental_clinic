<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// التحقق من تسجيل الدخول ونوع المستخدم
checkLogin('doctor');

// الحصول على اتصال قاعدة البيانات
$db = getDB();
$pdo = $db->getConnection();

$doctor_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// تحديد التبويب النشط
$active_tab = $_GET['tab'] ?? 'treatment_types';

// Header configuration
$pageTitle = 'إعدادات العلاج';
$pageIcon = 'fas fa-cogs';
$pageSubtitle = 'إعدادات أنواع العلاج والمراحل';
$currentPage = 'settings';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - عيادة الأسنان</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .tab-button.active {
            background-color: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        
        .settings-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        
        .settings-card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        
        .action-button {
            transition: all 0.2s ease;
        }
        
        .action-button:hover {
            transform: scale(1.05);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .sortable {
            cursor: move;
        }
        
        .sortable:hover {
            background-color: #f8fafc;
        }
        
        .drag-handle {
            cursor: grab;
        }
        
        .drag-handle:active {
            cursor: grabbing;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/doctor_header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-check-circle ml-1"></i>
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 fade-in">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <div class="mb-8">
            <nav class="flex space-x-4 space-x-reverse">
                <button class="tab-button px-6 py-3 rounded-lg border font-medium transition <?= $active_tab === 'treatment_types' ? 'active' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' ?>" 
                        onclick="switchTab('treatment_types')">
                    <i class="fas fa-list ml-2"></i>
                    أنواع العلاج
                </button>
                <button class="tab-button px-6 py-3 rounded-lg border font-medium transition <?= $active_tab === 'treatment_options' ? 'active' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' ?>" 
                        onclick="switchTab('treatment_options')">
                    <i class="fas fa-cog ml-2"></i>
                    خيارات العلاج
                </button>
                <button class="tab-button px-6 py-3 rounded-lg border font-medium transition <?= $active_tab === 'treatment_stages' ? 'active' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' ?>"
                        onclick="switchTab('treatment_stages')">
                    <i class="fas fa-tasks ml-2"></i>
                    مراحل العلاج
                </button>
                <button class="tab-button px-6 py-3 rounded-lg border font-medium transition <?= $active_tab === 'price_settings' ? 'active' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' ?>"
                        onclick="switchTab('price_settings')">
                    <i class="fas fa-money-bill-wave ml-2"></i>
                    إعدادات الأسعار
                </button>
            </nav>
        </div>

        <!-- Tab Contents -->
        <div id="treatment_types_content" class="tab-content <?= $active_tab === 'treatment_types' ? 'active' : '' ?>">
            <div class="settings-card p-6 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-list text-blue-600 ml-2"></i>
                        إدارة أنواع العلاج
                    </h3>
                    <button onclick="openAddTreatmentTypeModal()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition action-button">
                        <i class="fas fa-plus ml-1"></i>
                        إضافة نوع علاج جديد
                    </button>
                </div>
                
                <div id="treatmentTypesList">
                    <!-- سيتم تحميل القائمة هنا بواسطة JavaScript -->
                </div>
            </div>
        </div>

        <div id="treatment_options_content" class="tab-content <?= $active_tab === 'treatment_options' ? 'active' : '' ?>">
            <div class="settings-card p-6 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-cog text-orange-600 ml-2"></i>
                        إدارة خيارات العلاج
                    </h3>
                    <button onclick="openAddTreatmentOptionModal()" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg transition action-button">
                        <i class="fas fa-plus ml-1"></i>
                        إضافة خيار علاج جديد
                    </button>
                </div>
                
                <div id="treatmentOptionsList">
                    <!-- سيتم تحميل القائمة هنا بواسطة JavaScript -->
                </div>
            </div>
        </div>

        <div id="treatment_stages_content" class="tab-content <?= $active_tab === 'treatment_stages' ? 'active' : '' ?>">
            <div class="settings-card p-6 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-tasks text-purple-600 ml-2"></i>
                        إدارة مراحل العلاج
                    </h3>
                    <button onclick="openAddTreatmentStageModal()" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg transition action-button">
                        <i class="fas fa-plus ml-1"></i>
                        إضافة مرحلة علاج جديدة
                    </button>
                </div>
                
                <div id="treatmentStagesList">
                    <!-- سيتم تحميل القائمة هنا بواسطة JavaScript -->
                </div>
            </div>
        </div>

        <div id="price_settings_content" class="tab-content <?= $active_tab === 'price_settings' ? 'active' : '' ?>">
            <div class="settings-card p-6 fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-money-bill-wave text-green-600 ml-2"></i>
                        إعدادات الأسعار العامة
                    </h3>
                    <div class="flex items-center space-x-3 space-x-reverse">
                        <button onclick="bulkUpdatePrices()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition action-button">
                            <i class="fas fa-calculator ml-1"></i>
                            تحديث جماعي للأسعار
                        </button>
                        <a href="add_price_to_treatment_options.php" target="_blank" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition action-button">
                            <i class="fas fa-database ml-1"></i>
                            إضافة عمود السعر إلى قاعدة البيانات
                        </a>
                        <a href="debug_price_issues.php" target="_blank" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg transition action-button">
                            <i class="fas fa-tools ml-1"></i>
                            تشخيص المشاكل
                        </a>
                    </div>
                </div>

                <!-- Price Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="bg-blue-500 p-3 rounded-full text-white ml-3">
                                <i class="fas fa-list-ul"></i>
                            </div>
                            <div>
                                <p class="text-sm text-blue-600 font-medium">إجمالي الخيارات</p>
                                <p id="totalOptions" class="text-2xl font-bold text-blue-800">-</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="bg-green-500 p-3 rounded-full text-white ml-3">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div>
                                <p class="text-sm text-green-600 font-medium">بأسعار محددة</p>
                                <p id="optionsWithPrice" class="text-2xl font-bold text-green-800">-</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="bg-red-500 p-3 rounded-full text-white ml-3">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div>
                                <p class="text-sm text-red-600 font-medium">بدون أسعار</p>
                                <p id="optionsWithoutPrice" class="text-2xl font-bold text-red-800">-</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Price Management Tools -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Quick Price Setting -->
                    <div class="bg-white border border-gray-200 rounded-lg p-6">
                        <h4 class="text-lg font-semibold text-gray-800 mb-4">
                            <i class="fas fa-bolt text-yellow-500 ml-2"></i>
                            تحديد الأسعار السريع
                        </h4>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-gray-700 font-medium mb-2">نوع العلاج</label>
                                <select id="quickPriceTypeSelect" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">اختر نوع العلاج...</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-700 font-medium mb-2">السعر الموحد (ليرة سورية)</label>
                                <input type="number" id="quickPriceValue" step="0.01" min="0" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="0.00">
                            </div>
                            <button onclick="applyQuickPrice()" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-3 rounded-lg transition">
                                تطبيق السعر على جميع خيارات هذا النوع
                            </button>
                        </div>
                    </div>

                    <!-- Price Analysis -->
                    <div class="bg-white border border-gray-200 rounded-lg p-6">
                        <h4 class="text-lg font-semibold text-gray-800 mb-4">
                            <i class="fas fa-chart-pie text-purple-500 ml-2"></i>
                            تحليل الأسعار
                        </h4>
                        <div id="priceAnalysis" class="space-y-3">
                            <!-- سيتم تحميل التحليل هنا -->
                        </div>
                    </div>
                </div>

                <!-- Treatment Options Price List -->
                <div class="mt-8">
                    <h4 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-list text-blue-500 ml-2"></i>
                        قائمة أسعار خيارات العلاج
                    </h4>
                    <div id="priceSettingsList">
                        <!-- سيتم تحميل القائمة هنا -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals will be added here via JavaScript -->
    <div id="modalContainer"></div>

    <script>
        let currentActiveTab = '<?= $active_tab ?>';
        
        // Switch between tabs
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
                button.classList.add('bg-white', 'text-gray-700', 'border-gray-300', 'hover:bg-gray-50');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '_content').classList.add('active');
            
            // Add active class to selected tab button
            event.target.classList.add('active');
            event.target.classList.remove('bg-white', 'text-gray-700', 'border-gray-300', 'hover:bg-gray-50');
            
            currentActiveTab = tabName;
            
            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
            
            // Load content for the active tab
            loadTabContent(tabName);
        }
        
        // Load content for specific tab
        function loadTabContent(tabName) {
            switch(tabName) {
                case 'treatment_types':
                    loadTreatmentTypes();
                    break;
                case 'treatment_options':
                    loadTreatmentOptions();
                    break;
                case 'treatment_stages':
                    loadTreatmentStages();
                    break;
                case 'price_settings':
                    loadPriceSettings();
                    break;
            }
        }
        
        // Load treatment types
        async function loadTreatmentTypes() {
            try {
                const response = await fetch('treatment_types_management.php?action=get_all');
                const data = await response.json();
                
                if (data.success) {
                    displayTreatmentTypes(data.data);
                } else {
                    console.error('Error loading treatment types:', data.message);
                }
            } catch (error) {
                console.error('Error loading treatment types:', error);
            }
        }
        
        // Display treatment types
        function displayTreatmentTypes(types) {
            const container = document.getElementById('treatmentTypesList');
            
            if (!types || types.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-center py-8">لا توجد أنواع علاج مسجلة</p>';
                return;
            }
            
            let html = '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">';
            
            types.forEach(type => {
                html += `
                    <div class="bg-white border border-gray-200 rounded-lg p-4 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center">
                                <i class="${type.icon_class || 'fas fa-tooth'} ${type.icon_color || 'text-blue-600'} text-xl ml-2"></i>
                                <span class="font-semibold text-gray-900">${type.name_ar}</span>
                            </div>
                            <div class="flex items-center space-x-2 space-x-reverse">
                                <button onclick="editTreatmentType('${type.code}')" class="text-blue-600 hover:text-blue-800 p-1">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="deleteTreatmentType('${type.code}')" class="text-red-600 hover:text-red-800 p-1">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <div class="drag-handle text-gray-400 hover:text-gray-600 cursor-move p-1">
                                    <i class="fas fa-grip-vertical"></i>
                                </div>
                            </div>
                        </div>
                        <p class="text-sm text-gray-600 mb-2">${type.description_ar || 'لا يوجد وصف'}</p>
                        <div class="flex items-center justify-between text-xs text-gray-500">
                            <span>الترتيب: ${type.display_order || 0}</span>
                            <span class="px-2 py-1 rounded-full ${type.is_active == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                                ${type.is_active == 1 ? 'نشط' : 'غير نشط'}
                            </span>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }
        
        // Load treatment options
        async function loadTreatmentOptions() {
            try {
                const response = await fetch('treatment_options_management.php?action=get_all');
                const data = await response.json();
                
                if (data.success) {
                    displayTreatmentOptions(data.data);
                } else {
                    console.error('Error loading treatment options:', data.message);
                }
            } catch (error) {
                console.error('Error loading treatment options:', error);
            }
        }
        
        // Display treatment options
        function displayTreatmentOptions(options) {
            const container = document.getElementById('treatmentOptionsList');
            
            if (!options || options.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-center py-8">لا توجد خيارات علاج مسجلة</p>';
                return;
            }
            
            // Group options by treatment type
            const grouped = {};
            options.forEach(option => {
                if (!grouped[option.treatment_type_code]) {
                    grouped[option.treatment_type_code] = [];
                }
                grouped[option.treatment_type_code].push(option);
            });
            
            let html = '<div class="space-y-6">';
            
            Object.keys(grouped).forEach(typeCode => {
                html += `
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                        <h4 class="font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-folder text-orange-600 ml-2"></i>
                            ${typeCode}
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                `;
                
                grouped[typeCode].forEach(option => {
                    const price = option.price ? parseFloat(option.price).toFixed(2) : '0.00';
                    html += `
                        <div class="bg-white border border-gray-200 rounded p-3">
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <span class="font-medium text-gray-900">${option.name_ar}</span>
                                    <div class="text-sm font-semibold text-green-600 mt-1">
                                        <i class="fas fa-money-bill-wave ml-1"></i>
                                        ${price} ليرة سورية
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2 space-x-reverse">
                                    <button onclick="editTreatmentOption('${option.treatment_type_code}', '${option.option_code}')" class="text-blue-600 hover:text-blue-800 text-sm">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteTreatmentOption('${option.treatment_type_code}', '${option.option_code}')" class="text-red-600 hover:text-red-800 text-sm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <p class="text-sm text-gray-600">${option.description_ar || 'لا يوجد وصف'}</p>
                            <div class="flex items-center justify-between text-xs text-gray-500 mt-2">
                                <span>الترتيب: ${option.display_order || 0}</span>
                                <span class="px-2 py-1 rounded-full ${option.is_active == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                                    ${option.is_active == 1 ? 'نشط' : 'غير نشط'}
                                </span>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }
        
        // Load treatment stages
        async function loadTreatmentStages() {
            try {
                const response = await fetch('treatment_stages_management.php?action=get_all');
                const data = await response.json();
                
                if (data.success) {
                    displayTreatmentStages(data.data);
                } else {
                    console.error('Error loading treatment stages:', data.message);
                }
            } catch (error) {
                console.error('Error loading treatment stages:', error);
            }
        }
        
        // Display treatment stages
        function displayTreatmentStages(stages) {
            const container = document.getElementById('treatmentStagesList');

            if (!stages || stages.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-center py-8">لا توجد مراحل علاج مسجلة</p>';
                return;
            }

            // Group stages by treatment option
            const grouped = {};
            stages.forEach(stage => {
                const optionKey = stage.treatment_option_id;
                if (!grouped[optionKey]) {
                    grouped[optionKey] = {
                        option_name: stage.treatment_option_name || stage.option_name || 'خيار غير محدد',
                        stages: []
                    };
                }
                grouped[optionKey].stages.push(stage);
            });

            let html = '<div class="space-y-6">';

            Object.keys(grouped).forEach(optionId => {
                const optionData = grouped[optionId];
                // Sort stages by stage_order
                optionData.stages.sort((a, b) => (a.stage_order || 0) - (b.stage_order || 0));

                html += `
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                        <h4 class="font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-folder text-purple-600 ml-2"></i>
                            ${optionData.option_name}
                        </h4>
                        <div class="space-y-3">
                `;

                optionData.stages.forEach(stage => {
                    html += `
                        <div class="bg-white border border-gray-200 rounded p-3">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center">
                                    <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2 py-1 rounded-full ml-2">
                                        ${stage.stage_order}
                                    </span>
                                    <span class="font-medium text-gray-900">${stage.title_ar}</span>
                                </div>
                                <div class="flex items-center space-x-2 space-x-reverse">
                                    <button onclick="editTreatmentStage(${stage.treatment_option_id}, ${stage.stage_order})" class="text-blue-600 hover:text-blue-800 text-sm">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteTreatmentStage(${stage.treatment_option_id}, ${stage.stage_order})" class="text-red-600 hover:text-red-800 text-sm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <div class="drag-handle text-gray-400 hover:text-gray-600 cursor-move">
                                        <i class="fas fa-grip-vertical"></i>
                                    </div>
                                </div>
                            </div>
                            <p class="text-sm text-gray-600 mb-2">${stage.description_ar || 'لا يوجد وصف'}</p>
                            <div class="flex items-center justify-between text-xs text-gray-500">
                                <span>المدة: ${stage.duration_ar || 'غير محددة'}</span>
                                <span class="px-2 py-1 rounded-full ${stage.is_active == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                                    ${stage.is_active == 1 ? 'نشط' : 'غير نشط'}
                                </span>
                            </div>
                        </div>
                    `;
                });

                html += `
                        </div>
                    </div>
                `;
            });

            html += '</div>';
            container.innerHTML = html;
        }
        
        // Treatment Types Modal Functions
        async function openAddTreatmentTypeModal() {
            await showTreatmentTypeModal('add');
        }
        
        async function editTreatmentType(code) {
            await showTreatmentTypeModal('edit', code);
        }
        
        async function deleteTreatmentType(code) {
            if (confirm('هل أنت متأكد من حذف نوع العلاج؟ سيتم حذف جميع الخيارات والمراحل المرتبطة به.')) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('code', code);
                    
                    const response = await fetch('treatment_types_management.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        showMessage(result.message, 'success');
                        loadTreatmentTypes();
                    } else {
                        showMessage(result.message, 'error');
                    }
                } catch (error) {
                    showMessage('حدث خطأ أثناء الحذف', 'error');
                    console.error('Error:', error);
                }
            }
        }
        
        async function showTreatmentTypeModal(mode, code = null) {
            let modalTitle = mode === 'add' ? 'إضافة نوع علاج جديد' : 'تعديل نوع العلاج';
            let data = null;
            
            if (mode === 'edit' && code) {
                try {
                    const response = await fetch(`treatment_types_management.php?action=get_one&code=${encodeURIComponent(code)}`);
                    const result = await response.json();
                    if (result.success) {
                        data = result.data;
                    }
                } catch (error) {
                    console.error('Error fetching treatment type:', error);
                    return;
                }
            }
            
            // Get icon options
            let iconOptions = '';
            try {
                const response = await fetch('treatment_types_management.php?action=get_icon_options');
                const result = await response.json();
                if (result.success) {
                    result.data.forEach(icon => {
                        const selected = (data && data.icon_class === icon.class) ? 'selected' : '';
                        iconOptions += `<option value="${icon.class}" data-color="${icon.color}" ${selected}>${icon.name}</option>`;
                    });
                }
            } catch (error) {
                console.error('Error fetching icons:', error);
            }
            
            const modalHtml = `
                <div class="modal" id="treatmentTypeModal">
                    <div class="modal-content">
                        <div class="bg-blue-600 text-white p-4 rounded-t-lg">
                            <div class="flex justify-between items-center">
                                <h3 class="text-xl font-bold">${modalTitle}</h3>
                                <button onclick="closeModal('treatmentTypeModal')" class="text-white hover:text-gray-200">
                                    <i class="fas fa-times text-xl"></i>
                                </button>
                            </div>
                        </div>
                        
                        <form id="treatmentTypeForm" class="p-6">
                            <input type="hidden" name="action" value="${mode}">
                            ${mode === 'edit' ? `<input type="hidden" name="original_code" value="${code}">` : ''}
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">كود نوع العلاج *</label>
                                    <input type="text" name="code" value="${data ? data.code : ''}" required
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="مثال: cleaning">
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">اسم نوع العلاج *</label>
                                    <input type="text" name="name_ar" value="${data ? data.name_ar : ''}" required
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="مثال: تنظيف الأسنان">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-gray-700 font-semibold mb-2">الوصف</label>
                                <textarea name="description_ar" rows="3"
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                          placeholder="وصف مختصر لنوع العلاج...">${data ? data.description_ar : ''}</textarea>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">الأيقونة</label>
                                    <select name="icon_class" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                        <option value="">اختر أيقونة...</option>
                                        ${iconOptions}
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">لون الأيقونة</label>
                                    <select name="icon_color" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                        <option value="text-blue-600" ${data && data.icon_color === 'text-blue-600' ? 'selected' : ''}>أزرق</option>
                                        <option value="text-green-600" ${data && data.icon_color === 'text-green-600' ? 'selected' : ''}>أخضر</option>
                                        <option value="text-red-600" ${data && data.icon_color === 'text-red-600' ? 'selected' : ''}>أحمر</option>
                                        <option value="text-purple-600" ${data && data.icon_color === 'text-purple-600' ? 'selected' : ''}>بنفسجي</option>
                                        <option value="text-yellow-600" ${data && data.icon_color === 'text-yellow-600' ? 'selected' : ''}>أصفر</option>
                                        <option value="text-orange-600" ${data && data.icon_color === 'text-orange-600' ? 'selected' : ''}>برتقالي</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">ترتيب العرض</label>
                                    <input type="number" name="display_order" value="${data ? data.display_order || 0 : 0}" min="0"
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                            
                            <div class="mb-6">
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_active" ${data && data.is_active == 1 ? 'checked' : 'checked'} 
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200">
                                    <span class="mr-2 text-gray-700">نشط</span>
                                </label>
                            </div>
                            
                            <div class="flex justify-end space-x-4 space-x-reverse">
                                <button type="button" onclick="closeModal('treatmentTypeModal')" 
                                        class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                                    إلغاء
                                </button>
                                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                                    ${mode === 'add' ? 'إضافة' : 'تحديث'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            document.getElementById('modalContainer').innerHTML = modalHtml;
            document.getElementById('treatmentTypeModal').style.display = 'block';
            
            // Attach form submit event
            document.getElementById('treatmentTypeForm').addEventListener('submit', handleTreatmentTypeSubmit);
        }
        
        async function handleTreatmentTypeSubmit(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            
            try {
                const response = await fetch('treatment_types_management.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    closeModal('treatmentTypeModal');
                    loadTreatmentTypes();
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('حدث خطأ أثناء الحفظ', 'error');
                console.error('Error:', error);
            }
        }
        
        // Treatment Options Modal Functions
        async function openAddTreatmentOptionModal() {
            await showTreatmentOptionModal('add');
        }
        
        async function editTreatmentOption(treatmentTypeCode, optionCode) {
            await showTreatmentOptionModal('edit', treatmentTypeCode, optionCode);
        }
        
        async function deleteTreatmentOption(treatmentTypeCode, optionCode) {
            if (confirm('هل أنت متأكد من حذف خيار العلاج؟')) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('treatment_type_code', treatmentTypeCode);
                    formData.append('option_code', optionCode);
                    
                    const response = await fetch('treatment_options_management.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        showMessage(result.message, 'success');
                        loadTreatmentOptions();
                    } else {
                        showMessage(result.message, 'error');
                    }
                } catch (error) {
                    showMessage('حدث خطأ أثناء الحذف', 'error');
                    console.error('Error:', error);
                }
            }
        }
        
        async function showTreatmentOptionModal(mode, treatmentTypeCode = null, optionCode = null) {
            let modalTitle = mode === 'add' ? 'إضافة خيار علاج جديد' : 'تعديل خيار العلاج';
            let data = null;
            
            if (mode === 'edit' && treatmentTypeCode && optionCode) {
                try {
                    const response = await fetch(`treatment_options_management.php?action=get_one&treatment_type_code=${encodeURIComponent(treatmentTypeCode)}&option_code=${encodeURIComponent(optionCode)}`);
                    const result = await response.json();
                    if (result.success) {
                        data = result.data;
                    }
                } catch (error) {
                    console.error('Error fetching treatment option:', error);
                    return;
                }
            }
            
            // Get treatment types
            let treatmentTypeOptions = '';
            try {
                const response = await fetch('treatment_options_management.php?action=get_treatment_types');
                const result = await response.json();
                if (result.success) {
                    result.data.forEach(type => {
                        const selected = (data && data.treatment_type_code === type.code) ? 'selected' : '';
                        treatmentTypeOptions += `<option value="${type.code}" ${selected}>${type.name_ar}</option>`;
                    });
                }
            } catch (error) {
                console.error('Error fetching treatment types:', error);
            }
            
            const modalHtml = `
                <div class="modal" id="treatmentOptionModal">
                    <div class="modal-content">
                        <div class="bg-orange-600 text-white p-4 rounded-t-lg">
                            <div class="flex justify-between items-center">
                                <h3 class="text-xl font-bold">${modalTitle}</h3>
                                <button onclick="closeModal('treatmentOptionModal')" class="text-white hover:text-gray-200">
                                    <i class="fas fa-times text-xl"></i>
                                </button>
                            </div>
                        </div>
                        
                        <form id="treatmentOptionForm" class="p-6">
                            <input type="hidden" name="action" value="${mode}">
                            ${mode === 'edit' ? `
                                <input type="hidden" name="original_treatment_type_code" value="${treatmentTypeCode}">
                                <input type="hidden" name="original_option_code" value="${optionCode}">
                            ` : ''}
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">نوع العلاج *</label>
                                    <select name="treatment_type_code" required
                                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                        <option value="">اختر نوع العلاج...</option>
                                        ${treatmentTypeOptions}
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">كود الخيار *</label>
                                    <input type="text" name="option_code" value="${data ? data.option_code : ''}" required
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="مثال: basic_cleaning">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-gray-700 font-semibold mb-2">اسم الخيار *</label>
                                <input type="text" name="name_ar" value="${data ? data.name_ar : ''}" required
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                       placeholder="مثال: تنظيف أساسي">
                            </div>

                            <div class="mb-4">
                                <label class="block text-gray-700 font-semibold mb-2">الوصف</label>
                                <textarea name="description_ar" rows="3"
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                          placeholder="وصف مفصل للخيار...">${data ? data.description_ar : ''}</textarea>
                            </div>

                            <div class="mb-4">
                                <label class="block text-gray-700 font-semibold mb-2">السعر (ليرة سورية)</label>
                                <input type="number" name="price" value="${data ? data.price : ''}" step="0.01" min="0"
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                       placeholder="0.00">
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">ترتيب العرض</label>
                                    <input type="number" name="display_order" value="${data ? data.display_order || 0 : 0}" min="0"
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                </div>
                                
                                <div>
                                    <label class="flex items-center mt-8">
                                        <input type="checkbox" name="is_active" ${data && data.is_active == 1 ? 'checked' : 'checked'} 
                                               class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200">
                                        <span class="mr-2 text-gray-700">نشط</span>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="flex justify-end space-x-4 space-x-reverse">
                                <button type="button" onclick="closeModal('treatmentOptionModal')" 
                                        class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                                    إلغاء
                                </button>
                                <button type="submit" class="px-6 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition">
                                    ${mode === 'add' ? 'إضافة' : 'تحديث'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            document.getElementById('modalContainer').innerHTML = modalHtml;
            document.getElementById('treatmentOptionModal').style.display = 'block';
            
            // Attach form submit event
            document.getElementById('treatmentOptionForm').addEventListener('submit', handleTreatmentOptionSubmit);
        }
        
        async function handleTreatmentOptionSubmit(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            
            try {
                const response = await fetch('treatment_options_management.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    closeModal('treatmentOptionModal');
                    loadTreatmentOptions();
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('حدث خطأ أثناء الحفظ', 'error');
                console.error('Error:', error);
            }
        }
        
        // Treatment Stages Modal Functions
        async function openAddTreatmentStageModal() {
            await showTreatmentStageModal('add');
        }
        
        async function editTreatmentStage(treatmentOptionId, stageOrder) {
            await showTreatmentStageModal('edit', treatmentOptionId, stageOrder);
        }

        async function deleteTreatmentStage(treatmentOptionId, stageOrder) {
            if (confirm('هل أنت متأكد من حذف مرحلة العلاج؟ سيتم إعادة ترتيب المراحل المتبقية.')) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('treatment_option_id', treatmentOptionId);
                    formData.append('stage_order', stageOrder);

                    const response = await fetch('treatment_stages_management.php', {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showMessage(result.message, 'success');
                        loadTreatmentStages();
                    } else {
                        showMessage(result.message, 'error');
                    }
                } catch (error) {
                    showMessage('حدث خطأ أثناء الحذف', 'error');
                    console.error('Error:', error);
                }
            }
        }
        
        async function showTreatmentStageModal(mode, treatmentOptionId = null, stageOrder = null) {
            let modalTitle = mode === 'add' ? 'إضافة مرحلة علاج جديدة' : 'تعديل مرحلة العلاج';
            let data = null;

            if (mode === 'edit' && treatmentOptionId && stageOrder) {
                try {
                    const response = await fetch(`treatment_stages_management.php?action=get_one&treatment_option_id=${treatmentOptionId}&stage_order=${stageOrder}`);
                    const result = await response.json();
                    if (result.success) {
                        data = result.data;
                    }
                } catch (error) {
                    console.error('Error fetching treatment stage:', error);
                    return;
                }
            }

            // Get treatment options
            let treatmentOptionsHtml = '';
            try {
                const response = await fetch('treatment_stages_management.php?action=get_treatment_options');
                const result = await response.json();
                if (result.success) {
                    result.data.forEach(option => {
                        const selected = (data && data.treatment_option_id === option.id) ? 'selected' : '';
                        treatmentOptionsHtml += `<option value="${option.id}" ${selected}>${option.full_name || option.name_ar}</option>`;
                    });
                }
            } catch (error) {
                console.error('Error fetching treatment options:', error);
            }
            
            const modalHtml = `
                <div class="modal" id="treatmentStageModal">
                    <div class="modal-content">
                        <div class="bg-purple-600 text-white p-4 rounded-t-lg">
                            <div class="flex justify-between items-center">
                                <h3 class="text-xl font-bold">${modalTitle}</h3>
                                <button onclick="closeModal('treatmentStageModal')" class="text-white hover:text-gray-200">
                                    <i class="fas fa-times text-xl"></i>
                                </button>
                            </div>
                        </div>
                        
                        <form id="treatmentStageForm" class="p-6">
                            <input type="hidden" name="action" value="${mode}">
                            ${mode === 'edit' ? `
                                <input type="hidden" name="original_treatment_option_id" value="${treatmentOptionId}">
                                <input type="hidden" name="original_stage_order" value="${stageOrder}">
                            ` : ''}

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">خيار العلاج *</label>
                                    <select name="treatment_option_id" required
                                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                        <option value="">اختر خيار العلاج...</option>
                                        ${treatmentOptionsHtml}
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">ترتيب المرحلة</label>
                                    <input type="number" name="stage_order" value="${data ? data.stage_order : ''}" min="1"
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="اتركه فارغاً للإضافة في النهاية">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-gray-700 font-semibold mb-2">عنوان المرحلة *</label>
                                <input type="text" name="title_ar" value="${data ? data.title_ar : ''}" required
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                       placeholder="مثال: الفحص الأولي">
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-gray-700 font-semibold mb-2">وصف المرحلة</label>
                                <textarea name="description_ar" rows="3"
                                          class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                          placeholder="وصف مفصل للمرحلة والإجراءات المطلوبة...">${data ? data.description_ar : ''}</textarea>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-gray-700 font-semibold mb-2">المدة المتوقعة</label>
                                    <input type="text" name="duration_ar" value="${data ? data.duration_ar : ''}"
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                           placeholder="مثال: 30 دقيقة">
                                </div>
                                
                                <div>
                                    <label class="flex items-center mt-8">
                                        <input type="checkbox" name="is_active" ${data && data.is_active == 1 ? 'checked' : 'checked'} 
                                               class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200">
                                        <span class="mr-2 text-gray-700">نشط</span>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="flex justify-end space-x-4 space-x-reverse">
                                <button type="button" onclick="closeModal('treatmentStageModal')" 
                                        class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                                    إلغاء
                                </button>
                                <button type="submit" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition">
                                    ${mode === 'add' ? 'إضافة' : 'تحديث'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            document.getElementById('modalContainer').innerHTML = modalHtml;
            document.getElementById('treatmentStageModal').style.display = 'block';
            
            // Attach form submit event
            document.getElementById('treatmentStageForm').addEventListener('submit', handleTreatmentStageSubmit);
        }
        
        async function handleTreatmentStageSubmit(e) {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            
            try {
                const response = await fetch('treatment_stages_management.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    closeModal('treatmentStageModal');
                    loadTreatmentStages();
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('حدث خطأ أثناء الحفظ', 'error');
                console.error('Error:', error);
            }
        }
        
        // Utility functions
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function showMessage(message, type = 'info') {
            const messageDiv = document.createElement('div');
            messageDiv.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 ${
                type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' :
                type === 'error' ? 'bg-red-100 border border-red-400 text-red-700' :
                'bg-blue-100 border border-blue-400 text-blue-700'
            }`;
            messageDiv.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-triangle' : 'fa-info-circle'} ml-2"></i>
                    ${message}
                </div>
            `;
            
            document.body.appendChild(messageDiv);
            
            setTimeout(() => {
                document.body.removeChild(messageDiv);
            }, 5000);
        }
        
        // Price Settings Functions
        async function loadPriceSettings() {
            try {
                // Check if price column exists first
                const response = await fetch('treatment_options_management.php?action=get_all');
                const data = await response.json();

                if (data.success && !data.has_price_column) {
                    // Show warning message
                    const warningHtml = `
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
                            <div class="flex items-center">
                                <div class="bg-yellow-500 p-3 rounded-full text-white ml-3">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="flex-1">
                                    <h4 class="text-lg font-semibold text-yellow-800 mb-2">عمود السعر غير موجود</h4>
                                    <p class="text-yellow-700 mb-4">يجب إضافة عمود السعر إلى قاعدة البيانات أولاً لتتمكن من استخدام ميزات الأسعار.</p>
                                    <a href="add_price_to_treatment_options.php" target="_blank" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition">
                                        <i class="fas fa-database ml-1"></i>
                                        إضافة عمود السعر الآن
                                    </a>
                                </div>
                            </div>
                        </div>
                    `;

                    // Add warning to the top of price settings
                    const priceContainer = document.querySelector('#price_settings_content .settings-card');
                    if (priceContainer) {
                        priceContainer.insertAdjacentHTML('afterbegin', warningHtml);
                    }
                }

                await loadPriceStatistics();
                await loadTreatmentTypesForPricing();
                await loadPriceSettingsList();
                await loadPriceAnalysis();
            } catch (error) {
                console.error('Error loading price settings:', error);
                showMessage('حدث خطأ أثناء تحميل إعدادات الأسعار', 'error');
            }
        }

        async function loadPriceStatistics() {
            try {
                const response = await fetch('treatment_options_management.php?action=get_all');
                const data = await response.json();

                if (data.success) {
                    const options = data.data || [];
                    const totalOptions = options.length;
                    const optionsWithPrice = options.filter(opt => opt.price && parseFloat(opt.price) > 0).length;
                    const optionsWithoutPrice = totalOptions - optionsWithPrice;

                    document.getElementById('totalOptions').textContent = totalOptions;
                    document.getElementById('optionsWithPrice').textContent = optionsWithPrice;
                    document.getElementById('optionsWithoutPrice').textContent = optionsWithoutPrice;
                }
            } catch (error) {
                console.error('Error loading price statistics:', error);
            }
        }

        async function loadTreatmentTypesForPricing() {
            try {
                const response = await fetch('treatment_types_management.php?action=get_all');
                const data = await response.json();

                if (data.success) {
                    const select = document.getElementById('quickPriceTypeSelect');
                    select.innerHTML = '<option value="">اختر نوع العلاج...</option>';

                    data.data.forEach(type => {
                        select.innerHTML += `<option value="${type.code}">${type.name_ar}</option>`;
                    });
                }
            } catch (error) {
                console.error('Error loading treatment types for pricing:', error);
            }
        }

        async function loadPriceSettingsList() {
            try {
                const response = await fetch('treatment_options_management.php?action=get_all');
                const data = await response.json();

                if (data.success) {
                    displayPriceSettingsList(data.data);
                }
            } catch (error) {
                console.error('Error loading price settings list:', error);
            }
        }

        function displayPriceSettingsList(options) {
            const container = document.getElementById('priceSettingsList');

            if (!options || options.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-center py-8">لا توجد خيارات علاج</p>';
                return;
            }

            // Group options by treatment type
            const grouped = {};
            options.forEach(option => {
                if (!grouped[option.treatment_type_code]) {
                    grouped[option.treatment_type_code] = [];
                }
                grouped[option.treatment_type_code].push(option);
            });

            let html = '<div class="space-y-6">';

            Object.keys(grouped).forEach(typeCode => {
                html += `
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                        <h5 class="font-semibold text-gray-800 mb-4 flex items-center justify-between">
                            <span>
                                <i class="fas fa-folder text-blue-600 ml-2"></i>
                                ${typeCode}
                            </span>
                            <button onclick="setTypePrice('${typeCode}')" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm">
                                تحديد سعر موحد
                            </button>
                        </h5>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                `;

                grouped[typeCode].forEach(option => {
                    const price = option.price ? parseFloat(option.price).toFixed(2) : '0.00';
                    const priceClass = parseFloat(price) > 0 ? 'text-green-600' : 'text-red-600';

                    html += `
                        <div class="bg-white border border-gray-200 rounded p-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-medium text-gray-900 text-sm">${option.name_ar}</span>
                                <button onclick="editOptionPrice('${option.treatment_type_code}', '${option.option_code}')" class="text-blue-600 hover:text-blue-800 text-sm">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-500">السعر:</span>
                                <span class="font-semibold ${priceClass}">${price} ليرة</span>
                            </div>
                        </div>
                    `;
                });

                html += `
                        </div>
                    </div>
                `;
            });

            html += '</div>';
            container.innerHTML = html;
        }

        async function loadPriceAnalysis() {
            try {
                const response = await fetch('treatment_options_management.php?action=get_all');
                const data = await response.json();

                if (data.success) {
                    const options = data.data || [];
                    const analysis = analyzePrices(options);
                    displayPriceAnalysis(analysis);
                }
            } catch (error) {
                console.error('Error loading price analysis:', error);
            }
        }

        function analyzePrices(options) {
            const prices = options.filter(opt => opt.price && parseFloat(opt.price) > 0).map(opt => parseFloat(opt.price));

            if (prices.length === 0) {
                return { min: 0, max: 0, avg: 0, total: 0, count: 0 };
            }

            const min = Math.min(...prices);
            const max = Math.max(...prices);
            const total = prices.reduce((sum, price) => sum + price, 0);
            const avg = total / prices.length;

            return { min, max, avg, total, count: prices.length };
        }

        function displayPriceAnalysis(analysis) {
            const container = document.getElementById('priceAnalysis');

            container.innerHTML = `
                <div class="text-sm">
                    <div class="flex justify-between items-center py-2 border-b">
                        <span class="text-gray-600">أقل سعر:</span>
                        <span class="font-semibold text-green-600">${analysis.min.toFixed(2)} ليرة</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b">
                        <span class="text-gray-600">أعلى سعر:</span>
                        <span class="font-semibold text-blue-600">${analysis.max.toFixed(2)} ليرة</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b">
                        <span class="text-gray-600">متوسط السعر:</span>
                        <span class="font-semibold text-purple-600">${analysis.avg.toFixed(2)} ليرة</span>
                    </div>
                    <div class="flex justify-between items-center py-2">
                        <span class="text-gray-600">إجمالي القيمة:</span>
                        <span class="font-semibold text-orange-600">${analysis.total.toFixed(2)} ليرة</span>
                    </div>
                </div>
            `;
        }

        async function applyQuickPrice() {
            const typeCode = document.getElementById('quickPriceTypeSelect').value;
            const price = document.getElementById('quickPriceValue').value;

            if (!typeCode || !price || parseFloat(price) < 0) {
                showMessage('يرجى اختيار نوع العلاج وإدخال سعر صحيح', 'error');
                return;
            }

            if (!confirm(`هل أنت متأكد من تطبيق السعر ${price} ليرة سورية على جميع خيارات نوع العلاج ${typeCode}؟`)) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'bulk_update_price');
                formData.append('treatment_type_code', typeCode);
                formData.append('price', price);

                const response = await fetch('treatment_options_management.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showMessage(result.message, 'success');
                    loadPriceSettings();
                    document.getElementById('quickPriceValue').value = '';
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('حدث خطأ أثناء تحديث الأسعار', 'error');
                console.error('Error:', error);
            }
        }

        async function bulkUpdatePrices() {
            await showBulkUpdateModal();
        }

        async function showBulkUpdateModal() {
            const modalHtml = `
                <div class="modal" id="bulkUpdateModal">
                    <div class="modal-content">
                        <div class="bg-green-600 text-white p-4 rounded-t-lg">
                            <div class="flex justify-between items-center">
                                <h3 class="text-xl font-bold">تحديث جماعي للأسعار</h3>
                                <button onclick="closeModal('bulkUpdateModal')" class="text-white hover:text-gray-200">
                                    <i class="fas fa-times text-xl"></i>
                                </button>
                            </div>
                        </div>

                        <div class="p-6">
                            <div class="mb-6">
                                <h4 class="text-lg font-semibold text-gray-800 mb-4">خيارات التحديث الجماعي</h4>

                                <div class="space-y-4">
                                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                        <h5 class="font-semibold text-blue-800 mb-3">زيادة نسبية على جميع الأسعار</h5>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-gray-700 font-medium mb-2">نسبة الزيادة (%)</label>
                                                <input type="number" id="percentageIncrease" step="0.1" min="0" class="w-full p-3 border border-gray-300 rounded-lg" placeholder="10.5">
                                            </div>
                                            <div class="flex items-end">
                                                <button onclick="applyPercentageIncrease()" class="w-full bg-blue-500 hover:bg-blue-600 text-white py-3 rounded-lg">
                                                    تطبيق الزيادة
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                                        <h5 class="font-semibold text-red-800 mb-3">خصم نسبي على جميع الأسعار</h5>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-gray-700 font-medium mb-2">نسبة الخصم (%)</label>
                                                <input type="number" id="percentageDecrease" step="0.1" min="0" max="100" class="w-full p-3 border border-gray-300 rounded-lg" placeholder="5.0">
                                            </div>
                                            <div class="flex items-end">
                                                <button onclick="applyPercentageDecrease()" class="w-full bg-red-500 hover:bg-red-600 text-white py-3 rounded-lg">
                                                    تطبيق الخصم
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                        <h5 class="font-semibold text-yellow-800 mb-3">تعيين حد أدنى للأسعار</h5>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-gray-700 font-medium mb-2">الحد الأدنى (ليرة سورية)</label>
                                                <input type="number" id="minimumPrice" step="0.01" min="0" class="w-full p-3 border border-gray-300 rounded-lg" placeholder="100.00">
                                            </div>
                                            <div class="flex items-end">
                                                <button onclick="applyMinimumPrice()" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-3 rounded-lg">
                                                    تطبيق الحد الأدنى
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                                        <h5 class="font-semibold text-purple-800 mb-3">تقريب الأسعار</h5>
                                        <div class="grid grid-cols-3 gap-2">
                                            <button onclick="roundPrices(10)" class="bg-purple-500 hover:bg-purple-600 text-white py-2 rounded-lg text-sm">
                                                تقريب لأقرب 10
                                            </button>
                                            <button onclick="roundPrices(50)" class="bg-purple-500 hover:bg-purple-600 text-white py-2 rounded-lg text-sm">
                                                تقريب لأقرب 50
                                            </button>
                                            <button onclick="roundPrices(100)" class="bg-purple-500 hover:bg-purple-600 text-white py-2 rounded-lg text-sm">
                                                تقريب لأقرب 100
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <button onclick="closeModal('bulkUpdateModal')" class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition">
                                    إغلاق
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('modalContainer').innerHTML = modalHtml;
            document.getElementById('bulkUpdateModal').style.display = 'block';
        }

        async function setTypePrice(typeCode) {
            const price = prompt(`أدخل السعر الموحد لجميع خيارات نوع العلاج "${typeCode}" (ليرة سورية):`);

            if (price === null || price === '') return;

            const priceValue = parseFloat(price);
            if (isNaN(priceValue) || priceValue < 0) {
                showMessage('يرجى إدخال سعر صحيح', 'error');
                return;
            }

            if (!confirm(`هل أنت متأكد من تطبيق السعر ${priceValue} ليرة سورية على جميع خيارات نوع العلاج ${typeCode}؟`)) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'bulk_update_price');
                formData.append('treatment_type_code', typeCode);
                formData.append('price', priceValue);

                const response = await fetch('treatment_options_management.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showMessage(result.message, 'success');
                    loadPriceSettings();
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('حدث خطأ أثناء تحديث الأسعار', 'error');
                console.error('Error:', error);
            }
        }

        async function editOptionPrice(treatmentTypeCode, optionCode) {
            try {
                // Get current price
                const response = await fetch(`treatment_options_management.php?action=get_one&treatment_type_code=${encodeURIComponent(treatmentTypeCode)}&option_code=${encodeURIComponent(optionCode)}`);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                if (!result.success) {
                    showMessage(`فشل في جلب بيانات الخيار: ${result.message}`, 'error');
                    console.error('Failed to fetch option data:', result);
                    return;
                }

                // Check if price column exists
                if (!result.has_price_column) {
                    const confirmAdd = confirm('عمود السعر غير موجود في قاعدة البيانات. هل تريد إضافته الآن؟');
                    if (confirmAdd) {
                        window.open('add_price_to_treatment_options.php', '_blank');
                    }
                    return;
                }

                const currentPrice = result.data.price || '0.00';
                const optionName = result.data.name_ar || 'خيار غير محدد';
                const newPrice = prompt(`أدخل السعر الجديد لخيار "${optionName}":\n\nالسعر الحالي: ${currentPrice} ليرة سورية`, currentPrice);

                if (newPrice === null || newPrice === '') return;

                const priceValue = parseFloat(newPrice);
                if (isNaN(priceValue) || priceValue < 0) {
                    showMessage('يرجى إدخال سعر صحيح', 'error');
                    return;
                }

                // Update the price
                const formData = new FormData();
                formData.append('action', 'edit');
                formData.append('original_treatment_type_code', treatmentTypeCode);
                formData.append('original_option_code', optionCode);
                formData.append('treatment_type_code', treatmentTypeCode);
                formData.append('option_code', optionCode);
                formData.append('name_ar', result.data.name_ar || '');
                formData.append('description_ar', result.data.description_ar || '');
                formData.append('price', priceValue);
                formData.append('display_order', result.data.display_order || 0);
                if (result.data.is_active == 1) {
                    formData.append('is_active', '1');
                }

                const updateResponse = await fetch('treatment_options_management.php', {
                    method: 'POST',
                    body: formData
                });

                if (!updateResponse.ok) {
                    throw new Error(`HTTP error! status: ${updateResponse.status}`);
                }

                const updateResult = await updateResponse.json();

                if (updateResult.success) {
                    showMessage('تم تحديث السعر بنجاح', 'success');
                    loadPriceSettings();
                } else {
                    showMessage(`فشل في تحديث السعر: ${updateResult.message}`, 'error');
                }
            } catch (error) {
                showMessage('حدث خطأ أثناء تحديث السعر', 'error');
                console.error('Error in editOptionPrice:', error);

                // Additional debugging info
                console.log('Treatment Type Code:', treatmentTypeCode);
                console.log('Option Code:', optionCode);
            }
        }

        async function applyPercentageIncrease() {
            const percentage = parseFloat(document.getElementById('percentageIncrease').value);

            if (isNaN(percentage) || percentage <= 0) {
                showMessage('يرجى إدخال نسبة زيادة صحيحة', 'error');
                return;
            }

            if (!confirm(`هل أنت متأكد من زيادة جميع الأسعار بنسبة ${percentage}%؟`)) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'apply_percentage_change');
                formData.append('change_type', 'increase');
                formData.append('percentage', percentage);

                const response = await fetch('treatment_options_management.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showMessage(result.message, 'success');
                    loadPriceSettings();
                    document.getElementById('percentageIncrease').value = '';
                    closeModal('bulkUpdateModal');
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('حدث خطأ أثناء تطبيق الزيادة', 'error');
                console.error('Error:', error);
            }
        }

        async function applyPercentageDecrease() {
            const percentage = parseFloat(document.getElementById('percentageDecrease').value);

            if (isNaN(percentage) || percentage <= 0 || percentage >= 100) {
                showMessage('يرجى إدخال نسبة خصم صحيحة (1-99)', 'error');
                return;
            }

            if (!confirm(`هل أنت متأكد من خصم ${percentage}% من جميع الأسعار؟`)) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'apply_percentage_change');
                formData.append('change_type', 'decrease');
                formData.append('percentage', percentage);

                const response = await fetch('treatment_options_management.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showMessage(result.message, 'success');
                    loadPriceSettings();
                    document.getElementById('percentageDecrease').value = '';
                    closeModal('bulkUpdateModal');
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('حدث خطأ أثناء تطبيق الخصم', 'error');
                console.error('Error:', error);
            }
        }

        async function applyMinimumPrice() {
            const minimumPrice = parseFloat(document.getElementById('minimumPrice').value);

            if (isNaN(minimumPrice) || minimumPrice < 0) {
                showMessage('يرجى إدخال حد أدنى صحيح للسعر', 'error');
                return;
            }

            if (!confirm(`هل أنت متأكد من تعيين الحد الأدنى ${minimumPrice} ليرة سورية لجميع الأسعار؟\n\nسيتم رفع الأسعار الأقل من هذا المبلغ فقط.`)) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'apply_minimum_price');
                formData.append('minimum_price', minimumPrice);

                const response = await fetch('treatment_options_management.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showMessage(result.message, 'success');
                    loadPriceSettings();
                    document.getElementById('minimumPrice').value = '';
                    closeModal('bulkUpdateModal');
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('حدث خطأ أثناء تطبيق الحد الأدنى', 'error');
                console.error('Error:', error);
            }
        }

        async function roundPrices(roundTo) {
            if (!confirm(`هل أنت متأكد من تقريب جميع الأسعار لأقرب ${roundTo} ليرة سورية؟`)) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'round_prices');
                formData.append('round_to', roundTo);

                const response = await fetch('treatment_options_management.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showMessage(result.message, 'success');
                    loadPriceSettings();
                    closeModal('bulkUpdateModal');
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('حدث خطأ أثناء تقريب الأسعار', 'error');
                console.error('Error:', error);
            }
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            loadTabContent(currentActiveTab);
        });
    </script>
</body>
</html>