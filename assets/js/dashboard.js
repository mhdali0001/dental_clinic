// =====================================================
// Dental Clinic Dashboard JavaScript Functions
// =====================================================

// Global variables
let selectedPatientId = null;

// =====================================================
// PATIENT MANAGEMENT FUNCTIONS
// =====================================================

/**
 * View patient details in a modal
 */
function viewPatientDetails(patientId) {
    if (!patientId || patientId <= 0) {
        showAlert('معرف المريض غير صحيح', 'error');
        return;
    }

    // Show loading modal
    document.getElementById('modalContainer').innerHTML = `
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg p-8">
                <div class="text-center">
                    <svg class="w-8 h-8 animate-spin text-blue-500 mx-auto mb-4" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z"/>
                    </svg>
                    <p class="text-gray-600">جاري تحميل بيانات المريض...</p>
                </div>
            </div>
        </div>
    `;
    
    // Fetch patient details
    fetch(`patient_details.php?id=${patientId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('فشل في تحميل البيانات');
            }
            return response.text();
        })
        .then(html => {
            document.getElementById('modalContainer').innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('modalContainer').innerHTML = '';
            showAlert('حدث خطأ في تحميل بيانات المريض: ' + error.message, 'error');
        });
}

/**
 * Show add patient modal
 */
function showAddPatientModal() {
    const modalHtml = `
        <div id="addPatientModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg p-8 max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
                <h3 class="text-2xl font-bold text-center mb-6">إضافة مريض جديد</h3>
                <form action="add_patient.php" method="POST" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-gray-700 mb-2">الاسم الكامل *</label>
                            <input type="text" name="name" required class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">رقم الهاتف *</label>
                            <input type="tel" name="phone" required class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="05XXXXXXXX">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">العمر *</label>
                            <input type="number" name="age" required min="1" max="150" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">الجنس *</label>
                            <select name="gender" required class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">اختر</option>
                                <option value="male">ذكر</option>
                                <option value="female">أنثى</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">الطبيب المعالج</label>
                            <input type="text" name="doctor" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="اسم الطبيب">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-gray-700 mb-2">البريد الإلكتروني</label>
                            <input type="email" name="email" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-gray-700 mb-2">العنوان</label>
                            <textarea name="address" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500" rows="2"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-gray-700 mb-2">جهة اتصال طارئة</label>
                            <input type="text" name="emergency_contact" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-gray-700 mb-2">التاريخ الطبي</label>
                            <textarea name="medical_history" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500" rows="2"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-gray-700 mb-2">الحساسية</label>
                            <textarea name="allergies" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500" rows="2"></textarea>
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-2">فصيلة الدم</label>
                            <select name="blood_type" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">اختر</option>
                                <option value="A+">A+</option>
                                <option value="A-">A-</option>
                                <option value="B+">B+</option>
                                <option value="B-">B-</option>
                                <option value="AB+">AB+</option>
                                <option value="AB-">AB-</option>
                                <option value="O+">O+</option>
                                <option value="O-">O-</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex space-x-4 space-x-reverse">
                        <button type="submit" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-3 rounded-lg transition">
                            إضافة المريض
                        </button>
                        <button type="button" onclick="closeModal('addPatientModal')" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 py-3 rounded-lg transition">
                            إلغاء
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `;
    
    document.getElementById('modalContainer').innerHTML = modalHtml;
}

/**
 * Add patient to waiting list
 */
function addToWaitingList(patientId) {
    if (!patientId || patientId <= 0) {
        showAlert('معرف المريض غير صحيح', 'error');
        return;
    }

    const modalHtml = `
        <div id="addToWaitingModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
                <h3 class="text-xl font-bold text-center mb-4">إضافة لقائمة الانتظار</h3>
                <form action="add_to_waiting.php" method="POST" class="space-y-4">
                    <input type="hidden" name="patient_id" value="${patientId}">
                    <div>
                        <label class="block text-gray-700 mb-2">الأولوية</label>
                        <select name="priority" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="normal">عادي</option>
                            <option value="urgent">عاجل</option>
                            <option value="emergency">طارئ</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-2">نوع العلاج</label>
                        <input type="text" name="treatment_type" value="فحص عام" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-2">السبب/الملاحظات</label>
                        <textarea name="reason" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-500" rows="2" placeholder="اختياري..."></textarea>
                    </div>
                    <div class="flex space-x-4 space-x-reverse">
                        <button type="submit" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-3 rounded-lg">
                            إضافة للانتظار
                        </button>
                        <button type="button" onclick="closeModal('addToWaitingModal')" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-700 py-3 rounded-lg">
                            إلغاء
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `;
    
    document.getElementById('modalContainer').innerHTML = modalHtml;
}

/**
 * Call patient (open phone app)
 */
function callPatient(phoneNumber) {
    if (!phoneNumber) {
        showAlert('رقم الهاتف غير متوفر', 'error');
        return;
    }
    
    if (confirm(`هل تريد الاتصال بالرقم: ${phoneNumber}؟`)) {
        window.open(`tel:${phoneNumber}`, '_self');
    }
}

/**
 * Book appointment for patient
 */
function bookAppointmentForPatient(patientId) {
    if (!patientId || patientId <= 0) {
        showAlert('معرف المريض غير صحيح', 'error');
        return;
    }
    
    showAlert('ميزة حجز المواعيد قيد التطوير', 'info');
    // TODO: Implement appointment booking modal
}

// =====================================================
// APPOINTMENT FUNCTIONS
// =====================================================

/**
 * Show add appointment modal
 */
function showAddAppointmentModal(preSelectedPatientId = null) {
    showAlert('ميزة إضافة المواعيد قيد التطوير', 'info');
    // TODO: Implement add appointment modal
}

/**
 * Start appointment and add treatment
 */
function startAppointment(appointmentId, patientId) {
    if (!appointmentId || !patientId) {
        showAlert('بيانات غير صحيحة', 'error');
        return;
    }
    
    // Update appointment status to in_progress
    updateAppointmentStatus(appointmentId, 'in_progress');
    
    // Open treatment modal after a short delay
    setTimeout(() => {
        showAddTreatmentModal(patientId, appointmentId);
    }, 500);
}

/**
 * Update appointment status
 */
function updateAppointmentStatus(appointmentId, newStatus) {
    if (!appointmentId || !newStatus) {
        showAlert('بيانات غير صحيحة', 'error');
        return;
    }
    
    fetch('update_appointment_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `appointment_id=${appointmentId}&status=${newStatus}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('تم تحديث حالة الموعد بنجاح', 'success');
            // Reload page after a short delay
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            showAlert(data.message || 'حدث خطأ في تحديث حالة الموعد', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('حدث خطأ في تحديث حالة الموعد', 'error');
    });
}

// =====================================================
// TREATMENT FUNCTIONS
// =====================================================

/**
 * Show add treatment modal
 */
function showAddTreatmentModal(patientId, appointmentId = null) {
    if (!patientId || patientId <= 0) {
        showAlert('معرف المريض غير صحيح', 'error');
        return;
    }
    
    showAlert('ميزة إضافة العلاجات قيد التطوير', 'info');
    // TODO: Implement add treatment modal
}

/**
 * Show treatment modal for a patient
 */
function showTreatmentModal(patientId) {
    showAddTreatmentModal(patientId);
}

// =====================================================
// UTILITY FUNCTIONS
// =====================================================

/**
 * Close modal by ID
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.remove();
    } else {
        // Fallback: clear modal container
        const container = document.getElementById('modalContainer');
        if (container) {
            container.innerHTML = '';
        }
    }
}

/**
 * Show alert message
 */
function showAlert(message, type = 'info') {
    const alertClasses = {
        'success': 'bg-green-100 border-green-500 text-green-700',
        'error': 'bg-red-100 border-red-500 text-red-700',
        'warning': 'bg-yellow-100 border-yellow-500 text-yellow-700',
        'info': 'bg-blue-100 border-blue-500 text-blue-700'
    };
    
    const alertClass = alertClasses[type] || alertClasses.info;
    
    const alertHtml = `
        <div id="alert" class="fixed top-4 left-1/2 transform -translate-x-1/2 z-50 ${alertClass} border px-4 py-3 rounded max-w-md shadow-lg">
            <span class="block sm:inline">${message}</span>
        </div>
    `;
    
    // Remove existing alert
    const existingAlert = document.getElementById('alert');
    if (existingAlert) {
        existingAlert.remove();
    }
    
    // Add new alert
    document.body.insertAdjacentHTML('beforeend', alertHtml);
    
    // Remove alert after 4 seconds
    setTimeout(() => {
        const alert = document.getElementById('alert');
        if (alert) {
            alert.remove();
        }
    }, 4000);
}

/**
 * Export patients data
 */
function exportPatientsData(search = '') {
    const url = `export_patients.php?search=${encodeURIComponent(search)}`;
    window.open(url, '_blank');
}

/**
 * Print patients list
 */
function printPatientsList() {
    window.print();
}

// =====================================================
// FORM HANDLING
// =====================================================

/**
 * Handle form submissions with loading state
 */
function handleFormSubmission() {
    document.addEventListener('submit', function(e) {
        const form = e.target;
        const submitButton = form.querySelector('button[type="submit"]');
        
        if (submitButton) {
            // Disable submit button and show loading
            submitButton.disabled = true;
            const originalText = submitButton.textContent;
            submitButton.innerHTML = `
                <svg class="w-4 h-4 animate-spin inline ml-2" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z"/>
                </svg>
                جاري الحفظ...
            `;
            
            // Re-enable after 5 seconds in case of issues
            setTimeout(() => {
                if (submitButton.disabled) {
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                }
            }, 5000);
        }
    });
}

// =====================================================
// KEYBOARD SHORTCUTS
// =====================================================

/**
 * Setup keyboard shortcuts
 */
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + N for new patient
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            showAddPatientModal();
        }
        
        // Escape to close modals
        if (e.key === 'Escape') {
            const modals = document.querySelectorAll('[id$="Modal"]');
            modals.forEach(modal => {
                if (modal) {
                    modal.remove();
                }
            });
            
            // Also clear modal container
            const container = document.getElementById('modalContainer');
            if (container) {
                container.innerHTML = '';
            }
        }
    });
}

// =====================================================
// AUTO-HIDE ALERTS
// =====================================================

/**
 * Auto-hide success/error messages
 */
function autoHideAlerts() {
    setTimeout(() => {
        const alerts = document.querySelectorAll('#successAlert, #errorAlert');
        alerts.forEach(alert => {
            if (alert) {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }
        });
    }, 5000);
}

// =====================================================
// INITIALIZATION
// =====================================================

/**
 * Initialize dashboard functions when DOM is loaded
 */
document.addEventListener('DOMContentLoaded', function() {
    // Setup event handlers
    handleFormSubmission();
    setupKeyboardShortcuts();
    autoHideAlerts();
    
    // Make functions globally available
    window.viewPatientDetails = viewPatientDetails;
    window.showAddPatientModal = showAddPatientModal;
    window.addToWaitingList = addToWaitingList;
    window.callPatient = callPatient;
    window.bookAppointmentForPatient = bookAppointmentForPatient;
    window.showAddAppointmentModal = showAddAppointmentModal;
    window.startAppointment = startAppointment;
    window.updateAppointmentStatus = updateAppointmentStatus;
    window.showAddTreatmentModal = showAddTreatmentModal;
    window.showTreatmentModal = showTreatmentModal;
    window.closeModal = closeModal;
    window.showAlert = showAlert;
    window.exportPatientsData = exportPatientsData;
    window.printPatientsList = printPatientsList;
    
    console.log('Dental clinic dashboard initialized successfully');
});

// =====================================================
// ERROR HANDLING
// =====================================================

/**
 * Global error handler
 */
window.addEventListener('error', function(e) {
    console.error('Global error:', e.error);
    showAlert('حدث خطأ غير متوقع. يرجى إعادة تحميل الصفحة.', 'error');
});

/**
 * Handle unhandled promise rejections
 */
window.addEventListener('unhandledrejection', function(e) {
    console.error('Unhandled promise rejection:', e.reason);
    showAlert('حدث خطأ في العملية. يرجى المحاولة مرة أخرى.', 'error');
});