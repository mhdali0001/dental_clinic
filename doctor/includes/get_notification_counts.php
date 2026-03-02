<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in as doctor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$doctor_id = $_SESSION['user_id'];
$db = getDB();
$pdo = $db->getConnection();

$counts = [
    'appointments' => 0,
    'treatments' => 0,
    'patients' => 0,
    'total' => 0
];

try {
    // Today's appointments count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments a
        WHERE DATE(a.appointment_date) = CURDATE()
        AND a.status IN ('scheduled', 'confirmed')
    ");
    $stmt->execute();
    $counts['appointments'] = (int)$stmt->fetchColumn();

    // Incomplete treatments count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM treatments
        WHERE doctor_id = ? AND (status IS NULL OR status != 'completed')
    ");
    $stmt->execute([$doctor_id]);
    $counts['treatments'] = (int)$stmt->fetchColumn();

    // Patients needing follow-up
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT t.patient_id) FROM treatments t
        WHERE t.doctor_id = ?
        AND t.next_appointment_date IS NOT NULL
        AND t.next_appointment_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND NOT EXISTS (
            SELECT 1 FROM appointments a
            WHERE a.patient_id = t.patient_id
            AND a.appointment_date >= CURDATE()
        )
    ");
    $stmt->execute([$doctor_id]);
    $counts['patients'] = (int)$stmt->fetchColumn();

    // Calculate total
    $counts['total'] = $counts['appointments'] + $counts['treatments'] + $counts['patients'];

} catch (PDOException $e) {
    error_log("Notification counts error: " . $e->getMessage());
    // Return zeros on error
}

header('Content-Type: application/json');
echo json_encode($counts);
?>