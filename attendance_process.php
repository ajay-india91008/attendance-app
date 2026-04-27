<?php
session_start();
require 'db.php';

date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// Helper function to calculate distance between two coordinates (Haversine formula)
function getDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // in meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// Fetch settings
$settings = [];
$res = $conn->query("SELECT * FROM settings");
while($row = $res->fetch_assoc()) $settings[$row['setting_key']] = $row['setting_value'];

$checkin_start = $settings['checkin_time'] ?? '09:30';
$checkin_max = $settings['checkin_max_time'] ?? '10:00';
$checkout_target = $settings['checkout_time'] ?? '18:30';

// Fetch user's branch location
$u_res = $conn->query("SELECT u.branch_id, b.latitude, b.longitude, b.radius 
                       FROM users u 
                       LEFT JOIN branches b ON u.branch_id = b.id 
                       WHERE u.id = $user_id");
$user_branch = $u_res->fetch_assoc();

if (!$user_branch || !$user_branch['branch_id']) {
    echo json_encode(['success' => false, 'message' => 'No branch assigned to user.']);
    exit;
}

$branch_lat = (float)$user_branch['latitude'];
$branch_lng = (float)$user_branch['longitude'];
$branch_radius = (int)$user_branch['radius'];

if ($action === 'check_in') {
    $lat = (float)$_POST['latitude'];
    $lng = (float)$_POST['longitude'];
    $selfie_data = $_POST['selfie'] ?? '';

    // 1. Verify Location
    $distance = getDistance($lat, $lng, $branch_lat, $branch_lng);
    if ($distance > $branch_radius) {
        echo json_encode(['success' => false, 'message' => "You are outside the branch radius. Distance: " . round($distance) . "m"]);
        exit;
    }

    // 2. Verify Time
    $now = new DateTime();
    $today = $now->format('Y-m-d');
    
    // Check if already checked in today
    $check = $conn->query("SELECT id FROM attendance WHERE user_id = $user_id AND date = '$today'");
    if ($check->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'You have already checked in today.']);
        exit;
    }

    // 3. Process Selfie
    $selfie_path = null;
    if ($selfie_data) {
        $img = str_replace('data:image/png;base64,', '', $selfie_data);
        $img = str_replace(' ', '+', $img);
        $data = base64_decode($img);
        $filename = 'IN_' . $user_id . '_' . time() . '.png';
        $selfie_path = 'uploads/selfies/' . $filename;
        file_put_contents($selfie_path, $data);
    }

    // 4. Save Record
    $timestamp = date('Y-m-d H:i:s');
    $sql = "INSERT INTO attendance (user_id, date, check_in, check_in_selfie, check_in_lat, check_in_lng, status) 
            VALUES ($user_id, '$today', '$timestamp', '$selfie_path', $lat, $lng, 'Present')";
    
    if ($conn->query($sql)) {
        echo json_encode(['success' => true, 'message' => 'Checked in successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }

} elseif ($action === 'check_out') {
    $lat = (float)$_POST['latitude'];
    $lng = (float)$_POST['longitude'];
    $selfie_data = $_POST['selfie'] ?? '';

    // 1. Verify Location
    $distance = getDistance($lat, $lng, $branch_lat, $branch_lng);
    if ($distance > $branch_radius) {
        echo json_encode(['success' => false, 'message' => "You are outside the branch radius."]);
        exit;
    }

    $today = date('Y-m-d');
    $check = $conn->query("SELECT * FROM attendance WHERE user_id = $user_id AND date = '$today' AND check_out IS NULL");
    if ($check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No active check-in found for today.']);
        exit;
    }
    $attendance_row = $check->fetch_assoc();
    $att_id = $attendance_row['id'];
    $check_in_time = new DateTime($attendance_row['check_in']);

    // 2. Process Selfie
    $selfie_path = null;
    if ($selfie_data) {
        $img = str_replace('data:image/png;base64,', '', $selfie_data);
        $img = str_replace(' ', '+', $img);
        $data = base64_decode($img);
        $filename = 'OUT_' . $user_id . '_' . time() . '.png';
        $selfie_path = 'uploads/selfies/' . $filename;
        file_put_contents($selfie_path, $data);
    }

    // 3. Calculate Hours and Status
    $check_out_time_str = date('Y-m-d H:i:s');
    $check_in_ts = strtotime($attendance_row['check_in']);
    $check_out_ts = time();
    $seconds = $check_out_ts - $check_in_ts;
    $hours = $seconds / 3600;
    
    $status = 'Present';
    $deduction = 0;
    
    // Get user salary
    $u_sal_res = $conn->query("SELECT base_salary FROM users WHERE id = $user_id");
    $u_sal = $u_sal_res->fetch_assoc()['base_salary'] ?? 0;
    $one_day_salary = $u_sal / 26;

    if ($hours < 4.5) {
        $status = 'Absent';
        $deduction = $one_day_salary;
    } elseif ($hours < 9) {
        $status = 'Half Day';
        $deduction = $one_day_salary * 0.5;
    }

    // 4. Update Record
    $sql = "UPDATE attendance SET 
            check_out = '$check_out_time_str', 
            check_out_selfie = '$selfie_path', 
            check_out_lat = $lat, 
            check_out_lng = $lng, 
            working_hours = $hours, 
            status = '$status', 
            salary_deduction = $deduction 
            WHERE id = $att_id";
    
    if ($conn->query($sql)) {
        echo json_encode(['success' => true, 'message' => 'Checked out successfully. Status: ' . $status]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
} elseif ($action === 'start_break') {
    $today = date('Y-m-d');
    $check = $conn->query("SELECT id, break_start FROM attendance WHERE user_id = $user_id AND date = '$today' AND check_out IS NULL");
    if ($check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'You must check in before starting a break.']);
        exit;
    }
    $row = $check->fetch_assoc();
    if ($row['break_start']) {
        echo json_encode(['success' => false, 'message' => 'Break already started.']);
        exit;
    }
    
    $now = date('Y-m-d H:i:s');
    $conn->query("UPDATE attendance SET break_start = '$now' WHERE id = " . $row['id']);
    echo json_encode(['success' => true, 'message' => 'Break started.']);

} elseif ($action === 'end_break') {
    $today = date('Y-m-d');
    $check = $conn->query("SELECT id, break_start, break_end FROM attendance WHERE user_id = $user_id AND date = '$today' AND check_out IS NULL");
    if ($check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Active session not found.']);
        exit;
    }
    $row = $check->fetch_assoc();
    if (!$row['break_start']) {
        echo json_encode(['success' => false, 'message' => 'You have not started a break.']);
        exit;
    }
    if ($row['break_end']) {
        echo json_encode(['success' => false, 'message' => 'Break already ended.']);
        exit;
    }
    
    $now = date('Y-m-d H:i:s');
    $break_start = new DateTime($row['break_start']);
    $break_end = new DateTime($now);
    $interval = $break_start->diff($break_end);
    $break_hours = $interval->h + ($interval->i / 60);
    
    if ($break_hours > 0.5) {
        // Optional: Log or alert if break exceeded 0.5h
    }
    
    $conn->query("UPDATE attendance SET break_end = '$now' WHERE id = " . $row['id']);
    echo json_encode(['success' => true, 'message' => 'Break ended. Duration: ' . round($break_hours * 60) . ' mins']);

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}
?>
