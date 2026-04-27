<?php
session_start();
require 'db.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] === 'Admin') {
    header("Location: admin_dashboard.php");
    exit;
}

$page = isset($_GET['page']) ? $_GET['page'] : 'home';
$user_id = (int)$_SESSION['user_id'];
$msg = '';

$today = date('Y-m-d');
$today_attendance = null;
$att_res = $conn->query("SELECT * FROM attendance WHERE user_id = $user_id AND date = '$today'");
if ($att_res && $att_res->num_rows > 0) {
    $today_attendance = $att_res->fetch_assoc();
}

$selected_month = isset($_GET['month']) ? $conn->real_escape_string($_GET['month']) : date('F Y');

// Calculate stats for the selected month
$stats_res = $conn->query("SELECT 
    COUNT(CASE WHEN status = 'Present' THEN 1 END) as present_count,
    COUNT(CASE WHEN status = 'Half Day' THEN 1 END) as half_day_count,
    SUM(salary_deduction) as total_deduction
    FROM attendance 
    WHERE user_id = $user_id AND DATE_FORMAT(date, '%M %Y') = '$selected_month'");

$stats = $stats_res->fetch_assoc();
$present_days = (int)$stats['present_count'];
$half_days = (int)$stats['half_day_count'];
$total_paid_days = $present_days + ($half_days * 0.5);
$total_fines = (float)$stats['total_deduction'];

// Get user salary
$u_sal_res = $conn->query("SELECT base_salary FROM users WHERE id = $user_id");
$u_row = $u_sal_res->fetch_assoc();
$base_salary = $u_row['base_salary'] ?? 0;
$daily_rate = $base_salary / 26;
$net_earned = ($daily_rate * $total_paid_days);

// Fetch basic user info if not in session
if (!isset($_SESSION['profile_photo'])) {
    $u_res = $conn->query("SELECT profile_photo FROM users WHERE id = $user_id");
    if ($u_res && $u_res->num_rows > 0) {
        $u_row = $u_res->fetch_assoc();
        $_SESSION['profile_photo'] = $u_row['profile_photo'];
    }
}

// Handle Leave Request Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'submit_leave') {
    $leave_from = $conn->real_escape_string($_POST['leave_from']);
    $leave_to = $conn->real_escape_string($_POST['leave_to']);
    $reason = $conn->real_escape_string($_POST['reason']);
    $document_path = null;

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $filename = $_FILES['attachment']['name'];
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($file_ext, $allowed)) {
            $upload_dir = 'uploads/prescriptions/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
            $new_filename = 'E' . $user_id . '_' . time() . '.' . $file_ext;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $new_filename)) {
                $document_path = $upload_dir . $new_filename;
            }
        } else {
            $msg = "Error: Only JPG, PNG, and PDF files are allowed.";
        }
    }
    
    if (empty($msg)) {
        $doc_val = $document_path ? "'$document_path'" : "NULL";
        $conn->query("INSERT INTO leave_requests (user_id, leave_from, leave_to, reason, document_path) VALUES ($user_id, '$leave_from', '$leave_to', '$reason', $doc_val)");
        header("Location: index.php?page=leave&success=1");
        exit;
    }
}

// Handle Advance Money Request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'submit_advance') {
    $amount = (int)$_POST['amount'];
    $reason = $conn->real_escape_string($_POST['reason']);
    $ndate  = $conn->real_escape_string($_POST['needed_date']);
    $conn->query("INSERT INTO advance_requests (user_id, amount, reason, needed_date) VALUES ($user_id, $amount, '$reason', '$ndate')");
    header("Location: index.php?page=advance&success=1");
    exit;
}

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone_number']);
    $designation = $conn->real_escape_string($_POST['designation']);
    $dept_id = (int)$_POST['department_id'];
    $joining_date = $conn->real_escape_string($_POST['joining_date']);
    
    $photo_sql = "";
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $filename = $_FILES['profile_photo']['name'];
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($file_ext, $allowed)) {
            $upload_dir = 'uploads/profiles/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
            $new_filename = 'P' . $user_id . '_' . time() . '.' . $file_ext;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_dir . $new_filename)) {
                $photo_path = $upload_dir . $new_filename;
                $photo_sql = ", profile_photo = '$photo_path'";
                $_SESSION['profile_photo'] = $photo_path;
            }
        }
    }

    // Only allow updating Email, Phone, and Photo for employees
    $conn->query("UPDATE users SET 
        email = '$email', 
        phone_number = '$phone' 
        $photo_sql
        WHERE id = $user_id");
    
    $_SESSION['name'] = $name; // Update session name
    header("Location: index.php?page=profile&success=1");
    exit;
}

$holidays = [];
if ($page === 'holidays') {
    $res = $conn->query("SELECT * FROM holidays ORDER BY holiday_date ASC");
    if($res) {
        while($row = $res->fetch_assoc()) $holidays[] = $row;
    }
}

$attendance_history = [];
$res = $conn->query("SELECT * FROM attendance WHERE user_id = $user_id AND DATE_FORMAT(date, '%M %Y') = '$selected_month' ORDER BY date DESC");
if($res) {
    while($row = $res->fetch_assoc()) $attendance_history[] = $row;
}

// Fetch appreciations
$all_appreciations = [];
$res = $conn->query("SELECT a.*, u.name as emp_name FROM appreciations a JOIN users u ON a.user_id = u.id ORDER BY a.id DESC");
if($res && $res->num_rows > 0) {
    while($row = $res->fetch_assoc()) $all_appreciations[] = $row;
}

$advance_reqs = [];
if ($page === 'advance') {
    $res = $conn->query("SELECT * FROM advance_requests WHERE user_id = $user_id ORDER BY id DESC");
    if($res) {
        while($row = $res->fetch_assoc()) $advance_reqs[] = $row;
    }
}

$user_data = [];
if ($page === 'profile') {
    $res = $conn->query("SELECT u.*, d.name as dept_name FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.id = $user_id");
    $user_data = $res->fetch_assoc();
    
    $departments = [];
    $dept_res = $conn->query("SELECT * FROM departments ORDER BY name ASC");
    while($d = $dept_res->fetch_assoc()) $departments[] = $d;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Dashboard</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #1a1a24;
            color: #ffffff;
            font-family: 'Inter', sans-serif;
            padding-bottom: 70px; /* Space for bottom nav */
        }
        .header {
            padding: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #2e2f42; /* Subtle border */
        }
        .profile-img-container {
            position: relative;
            display: inline-block;
        }
        .profile-img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            border: 2px solid #00e676;
            object-fit: cover;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-details h6 {
            margin: 0;
            font-weight: 600;
            font-size: 16px;
        }
        .user-details small {
            color: #00e676;
            font-size: 13px;
            font-weight: 500;
        }
        .status-icons {
            display: flex;
            gap: 12px;
            font-size: 13px;
            font-weight: 600;
        }
        .status-icons span {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .text-yellow { color: #ffb300 !important; }
        .text-green { color: #00e676 !important; }
        .text-red { color: #ff3d00 !important; }
        .power-btn {
            color: #ff3d00;
            font-size: 24px;
            cursor: pointer;
        }
        
        .marquee-container {
            background-color: #1a1a24;
            color: #ff9100;
            padding: 8px 0;
            font-size: 14px;
            border-bottom: 1px solid #2e2f42;
            display: flex;
            align-items: center;
        }
        .marquee-icon {
            padding-left: 15px;
            padding-right: 10px;
        }
        
        .main-card {
            background-color: #252634;
            border-radius: 12px;
            padding: 24px 20px;
            margin: 15px;
            text-align: center;
            border: 1px solid #2e2f42;
        }
        .clock-display {
            font-size: 52px;
            font-weight: 700;
            color: #00e676;
            line-height: 1;
            margin-bottom: 8px;
            font-variant-numeric: tabular-nums;
        }
        .date-display {
            color: #8c8d9e;
            font-size: 15px;
            margin-bottom: 20px;
        }
        .month-selector {
            background-color: #2e2f42;
            border: 1px solid #45475a;
            color: #ffffff;
            border-radius: 8px;
            padding: 6px 16px;
            font-size: 14px;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238c8d9e' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 14px;
            padding-right: 32px;
            cursor: pointer;
        }
        .info-text {
            color: #6c6d7d;
            font-size: 12px;
            margin-top: 12px;
            margin-bottom: 15px;
        }
        .team-present {
            font-size: 15px;
            color: #8c8d9e;
            margin-bottom: 24px;
            font-weight: 500;
        }
        .team-present i {
            color: #2979ff;
            margin-right: 6px;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
        }
        .btn-action {
            flex: 1;
            border: none;
            border-radius: 12px;
            padding: 20px 0;
            font-weight: 600;
            font-size: 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: opacity 0.2s;
        }
        .btn-in {
            background-color: #00e676;
            color: #1a1a24;
        }
        .btn-in i {
            font-size: 24px;
        }
        .btn-disabled {
            background-color: #2a2a38;
            color: #5c5d6e;
        }
        .btn-disabled i {
            font-size: 24px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            padding: 0 15px;
        }
        .stat-card {
            background-color: #252634;
            border-radius: 12px;
            padding: 18px;
            text-align: center;
            border: 1px solid #2e2f42;
        }
        .stat-value {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .stat-label {
            font-size: 12px;
            color: #8c8d9e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .val-orange { color: #ffb300; }
        .val-red { color: #ff5252; }
        
        .selfie-card {
            background-color: #252634;
            border-radius: 12px;
            padding: 16px;
            margin: 15px;
            text-align: center;
            border: 1px solid #2d385a; /* Subtle blue tint border */
            color: #8c8d9e;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .selfie-card:hover {
            background-color: #2a2b3b;
        }
        .selfie-card i {
            color: #2979ff;
            font-size: 24px;
            display: block;
            margin-bottom: 6px;
        }
        .selfie-card span {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .fab-btn {
            position: fixed;
            bottom: 85px;
            right: 20px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background-color: #2979ff;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 4px 15px rgba(41, 121, 255, 0.4);
            border: none;
            cursor: pointer;
            z-index: 1000;
        }
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            width: 100%;
            background-color: #252634;
            display: flex;
            justify-content: space-around;
            padding: 12px 0 8px 0;
            border-top: 1px solid #2e2f42;
            z-index: 1000;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #6c6d7d;
            text-decoration: none;
            font-size: 12px;
            gap: 4px;
        }
        .nav-item i {
            font-size: 22px;
            margin-bottom: 2px;
        }
        .nav-item.active {
            color: #2979ff;
        }
        .nav-item:hover {
            color: #8c8d9e;
        }
        .nav-item.active:hover {
            color: #2979ff;
        }

        /* Adjusting layout for mobile screens */
        @media (max-width: 380px) {
            .status-icons {
                gap: 8px;
                font-size: 11px;
            }
            .clock-display {
                font-size: 42px;
            }
        }

        .theme-fab {
            position: fixed;
            bottom: 155px; /* Above existing FAB */
            right: 20px;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background-color: #2e2f42;
            color: #ffb300;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            border: none;
            cursor: pointer;
            z-index: 1000;
        }

        /* Light Mode Styles */
        body.light-mode { background-color: #f4f6f9; color: #212529; }
        body.light-mode .header { background-color: #ffffff; border-color: #e9ecef; }
        body.light-mode .user-details h6 { color: #212529; }
        body.light-mode .marquee-container { background-color: #ffffff; border-color: #e9ecef; }
        body.light-mode .main-card, body.light-mode .stat-card { background-color: #ffffff; border-color: #e9ecef; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        body.light-mode .selfie-card { background-color: #ffffff; border-color: #dbe4ff; box-shadow: 0 4px 12px rgba(0,0,0,0.03); color: #495057; }
        body.light-mode .selfie-card:hover { background-color: #f8f9fa; }
        body.light-mode .stat-value.text-white { color: #212529 !important; }
        body.light-mode .bottom-nav { background-color: #ffffff; border-color: #e9ecef; }
        body.light-mode .month-selector { background-color: #f8f9fa; color: #212529; border-color: #ced4da; }
        body.light-mode .btn-disabled { background-color: #f1f3f5; color: #adb5bd; }
        body.light-mode .theme-fab { background-color: #ffffff; border: 1px solid #e9ecef; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <script>if (localStorage.getItem('theme') === 'light') { document.body.classList.add('light-mode'); }</script>

    <!-- Header -->
    <div class="header">
        <div class="user-info">
            <div class="profile-img-container">
                <!-- User avatar -->
                <?php if (isset($_SESSION['profile_photo']) && $_SESSION['profile_photo']): ?>
                    <img src="<?php echo htmlspecialchars($_SESSION['profile_photo']); ?>" alt="User" class="profile-img">
                <?php else: ?>
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['name']); ?>&background=2e2f42&color=fff&size=100" alt="User" class="profile-img">
                <?php endif; ?>
            </div>
            <div class="user-details">
                <h6><?php echo htmlspecialchars($_SESSION['name']); ?></h6>
                <small><?php echo htmlspecialchars($_SESSION['role']); ?></small>
            </div>
        </div>
        <div class="status-icons">
            <span class="text-yellow"><i class="bi bi-geo-alt-fill"></i> GPS</span>
            <span class="text-green"><i class="bi bi-camera-fill"></i> CAM</span>
            <span class="text-green"><i class="bi bi-wifi"></i> NET</span>
        </div>
        <a href="logout.php"><i class="bi bi-power power-btn"></i></a>
    </div>

    <!-- Marquee -->
    <div class="marquee-container">
        <div class="marquee-icon">
            <i class="bi bi-megaphone-fill"></i>
        </div>
        <marquee behavior="scroll" direction="left" scrollamount="4" style="flex:1; margin-right: 10px;">
            सभी कर्मचारी अपनी प्रोफ़ाइल में जानकारी अपडेट करें और अपनी उपस्थिति समय पर दर्ज करें।
        </marquee>
    </div>

    <?php if ($page === 'home'): ?>

    <!-- Main Card -->
    <div class="main-card">
        <div class="clock-display" id="clock">18:01:03</div>
        <div class="date-display" id="date">Tuesday, 21 April 2026</div>
        
        <select class="month-selector mb-3" onchange="window.location.href='index.php?month=' + this.value">
            <?php
            for ($i = 0; $i < 6; $i++) {
                $m = date('F Y', strtotime("-$i months"));
                $sel = ($m == $selected_month) ? 'selected' : '';
                echo "<option value='$m' $sel>$m</option>";
            }
            ?>
        </select>
        
        <div class="info-text">Select month to view past records</div>
        
        <!-- <div class="team-present">
        <i class="bi bi-people-fill"></i> Team Present: <strong>84</strong>
        </div> -->
        
        <div class="action-buttons">
            <button class="btn-action <?= ($today_attendance && $today_attendance['check_in']) ? 'btn-disabled' : 'btn-in' ?>" 
                    id="checkInBtn" 
                    onclick="handleAttendance('check_in')"
                    <?= ($today_attendance && $today_attendance['check_in']) ? 'disabled' : '' ?>>
                <i class="bi bi-fingerprint"></i>
                IN
            </button>
            <?php 
                $break_btn_class = 'btn-disabled';
                $break_btn_text = 'BREAK';
                $break_btn_onclick = '';
                $break_btn_disabled = 'disabled';
                
                if ($today_attendance && $today_attendance['check_in'] && !$today_attendance['check_out']) {
                    $break_btn_class = 'btn-in';
                    $break_btn_onclick = "handleBreak()";
                    $break_btn_disabled = '';
                    if ($today_attendance['break_start'] && !$today_attendance['break_end']) {
                        $break_btn_text = 'END BREAK';
                        $break_btn_class = 'btn-out'; // yellow or orange for active break
                    } elseif ($today_attendance['break_start'] && $today_attendance['break_end']) {
                        $break_btn_text = 'BREAK DONE';
                        $break_btn_class = 'btn-disabled';
                        $break_btn_disabled = 'disabled';
                    } else {
                        $break_btn_text = 'START BREAK';
                    }
                }
            ?>
            <button class="btn-action <?= $break_btn_class ?>" id="breakBtn" onclick="<?= $break_btn_onclick ?>" <?= $break_btn_disabled ?>>
                <i class="bi bi-cup-hot-fill"></i>
                <?= $break_btn_text ?>
            </button>
            <button class="btn-action <?= ($today_attendance && $today_attendance['check_in'] && !$today_attendance['check_out']) ? 'btn-in' : 'btn-disabled' ?>" 
                    id="checkOutBtn" 
                    onclick="handleAttendance('check_out')"
                    <?= ($today_attendance && $today_attendance['check_in'] && !$today_attendance['check_out']) ? '' : 'disabled' ?>>
                <i class="bi bi-box-arrow-right"></i>
                OUT
            </button>
        </div>
    </div>

    <!-- Attendance Modal -->
    <div class="modal fade" id="attendanceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background-color: #252634; color: #fff; border: 1px solid #2e2f42;">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title" id="attModalTitle">Verification</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="cameraContainer" style="position: relative; width: 100%; max-width: 400px; margin: 0 auto; border-radius: 12px; overflow: hidden; background: #000;">
                        <video id="video" width="100%" height="auto" autoplay playsinline></video>
                        <canvas id="canvas" style="display:none;"></canvas>
                    </div>
                    <div id="statusMsg" class="mt-3 small text-info">Starting camera...</div>
                </div>
                <div class="modal-footer border-top-0 justify-content-center">
                    <button type="button" class="btn btn-primary" id="captureBtn" style="background: linear-gradient(90deg, #2979ff, #651fff); border: none; padding: 10px 30px; border-radius: 25px;">
                        Capture & Proceed
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value text-white"><?= $total_paid_days ?></div>
            <div class="stat-label">PAID DAYS</div>
        </div>
        <div class="stat-card">
            <div class="stat-value text-white">₹<?= number_format($net_earned) ?></div>
            <div class="stat-label">NET EARNED</div>
        </div>
        <div class="stat-card">
            <div class="stat-value val-orange"><?= $present_days ?> P / <?= $half_days ?> H</div>
            <div class="stat-label">ATTENDANCE</div>
        </div>
        <div class="stat-card">
            <div class="stat-value val-red">₹<?= number_format($total_fines) ?></div>
            <div class="stat-label">FINES</div>
        </div>
    </div>

    <!-- Selfie Action -->
    <div class="selfie-card" onclick="<?= ($today_attendance && $today_attendance['check_in_selfie']) ? "showSelfie('".$today_attendance['check_in_selfie']."')" : "alert('No selfie captured yet today.')" ?>">
        <i class="bi bi-camera-fill"></i>
        <span><?= ($today_attendance && $today_attendance['check_in_selfie']) ? 'VIEW TODAY\'S SELFIE' : 'MY SELFIE' ?></span>
    </div>

    <!-- Selfie Viewer Modal -->
    <div class="modal fade" id="selfieViewerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background-color: #252634; border: 1px solid #2e2f42;">
                <div class="modal-body p-0 text-center">
                    <img id="selfieImage" src="" alt="Selfie" style="width: 100%; border-radius: 12px;">
                    <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal"></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Wall of Fame / Appreciation Section -->
    <?php if(!empty($all_appreciations)): ?>
    <div style="padding: 15px 0 15px 15px; margin-top: 10px;">
        <h5 style="margin: 0 0 15px 0; font-weight: 700; font-size: 16px; color: #ffb300; display: flex; align-items: center; gap: 8px;">
            <i class="bi bi-trophy-fill"></i> Wall of Fame
        </h5>
        <div id="appreciationCarousel" style="display: flex; overflow-x: auto; gap: 15px; padding-right: 15px; scroll-snap-type: x mandatory; -ms-overflow-style: none; scrollbar-width: none; scroll-behavior: smooth;">
            <style>
                .appreciation-card::-webkit-scrollbar { display: none; }
            </style>
            <?php foreach($all_appreciations as $ap): ?>
            <div style="flex: 0 0 85%; scroll-snap-align: center; background: linear-gradient(135deg, rgba(255, 179, 0, 0.12) 0%, rgba(255, 143, 0, 0.05) 100%); border: 1px solid rgba(255, 179, 0, 0.2); border-radius: 16px; padding: 18px; position: relative; overflow: hidden;">
                <div style="position: absolute; right: -15px; top: -15px; font-size: 80px; color: rgba(255, 179, 0, 0.04); transform: rotate(-15deg); pointer-events: none;">
                    <i class="bi bi-award-fill"></i>
                </div>
                <div style="display: flex; align-items: flex-start; gap: 12px; position: relative; z-index: 1;">
                    <div style="width: 45px; height: 45px; background: linear-gradient(135deg, #ffb300, #ff8f00); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #1a1a24; flex-shrink: 0; box-shadow: 0 4px 10px rgba(255, 179, 0, 0.2);">
                        <i class="bi bi-star-fill"></i>
                    </div>
                    <div>
                        <div style="color: #ffb300; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px;"><?= htmlspecialchars($ap['month']) ?></div>
                        <h4 style="margin: 0; font-weight: 700; font-size: 16px; color: #fff;"><?= htmlspecialchars($ap['emp_name']) ?></h4>
                        <div style="color: #a3a4b0; font-size: 12px; margin-top: 6px; line-height: 1.4; font-style: italic; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">"<?= htmlspecialchars($ap['reason']) ?>"</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Attendance Table -->
    <div style="padding: 15px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h5 style="margin: 0; font-weight: 600; font-size: 16px;">Attendance History (<?= $selected_month ?>)</h5>
        </div>
        <div style="background-color: #252634; border-radius: 12px; border: 1px solid #2e2f42; overflow-x: auto;">
            <table class="table" style="color: #fff; margin-bottom: 0px; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 1px solid #2e2f42;">
                        <th style="color: #8c8d9e; font-weight: 500; font-size: 11px; border: none; padding: 12px 15px; text-transform: uppercase;">Date</th>
                        <th style="color: #8c8d9e; font-weight: 500; font-size: 11px; border: none; padding: 12px 15px; text-transform: uppercase;">Time</th>
                        <th style="color: #8c8d9e; font-weight: 500; font-size: 11px; border: none; padding: 12px 15px; text-transform: uppercase;">Status</th>
                        <th style="color: #8c8d9e; font-weight: 500; font-size: 11px; border: none; padding: 12px 15px; text-transform: uppercase;">Selfie</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($attendance_history)): ?>
                        <?php foreach($attendance_history as $att): 
                            $clr = $att['status'] == 'Present' ? '#00e676' : ($att['status'] == 'Half Day' ? '#ffb300' : '#ff5252');
                            $bg = $att['status'] == 'Present' ? 'rgba(0,230,118,0.1)' : ($att['status'] == 'Half Day' ? 'rgba(255,179,0,0.1)' : 'rgba(255,82,82,0.1)');
                        ?>
                        <tr style="border-bottom: 1px solid #2e2f42;">
                            <td style="padding: 12px 15px; border: none; white-space: nowrap;">
                                <div style="font-weight: 500;"><?= date('d M', strtotime($att['date'])) ?></div>
                            </td>
                            <td style="padding: 12px 15px; border: none;">
                                <div style="font-size: 11px; color: #00e676;">
                                    In: <?= $att['check_in'] ? date('h:i A', strtotime($att['check_in'])) : '--:--' ?>
                                </div>
                                <div style="font-size: 11px; color: #ff5252;">
                                    Out: <?= $att['check_out'] ? date('h:i A', strtotime($att['check_out'])) : '--:--' ?>
                                </div>
                            </td>
                            <td style="padding: 12px 15px; border: none;">
                                <span style="color: <?= $clr ?>; background: <?= $bg ?>; padding: 4px 8px; border-radius: 20px; font-size: 10px; font-weight: 600;"><?= $att['status'] ?></span>
                            </td>
                            <td style="padding: 12px 15px; border: none;">
                                <?php if($att['check_in_selfie']): ?>
                                    <i class="bi bi-camera-fill" style="color: #2979ff; cursor: pointer;" onclick="showSelfie('<?= $att['check_in_selfie'] ?>')"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align:center; padding: 20px 15px; color: #8c8d9e; border:none; font-size: 13px;">No records for this month.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Leave Requests Table on Dashboard -->
    <div style="padding: 15px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h5 style="margin: 0; font-weight: 600; font-size: 16px;">Recent Leaves</h5>
            <a href="?page=leave" style="color: #2979ff; font-size: 13px; text-decoration: none; font-weight: 500;">View All</a>
        </div>
        <div style="background-color: #252634; border-radius: 12px; border: 1px solid #2e2f42; overflow-x: auto;">
            <table class="table" style="color: #fff; margin-bottom: 0px; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 1px solid #2e2f42;">
                        <th style="color: #8c8d9e; font-weight: 500; font-size: 11px; border: none; padding: 12px 15px; text-transform: uppercase;">Dates</th>
                        <th style="color: #8c8d9e; font-weight: 500; font-size: 11px; border: none; padding: 12px 15px; text-transform: uppercase;">Reason</th>
                        <th style="color: #8c8d9e; font-weight: 500; font-size: 11px; border: none; padding: 12px 15px; text-transform: uppercase;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $dash_reqs = $conn->query("SELECT * FROM leave_requests WHERE user_id = $user_id ORDER BY id DESC LIMIT 3");
                    if($dash_reqs && $dash_reqs->num_rows > 0): 
                        while($lr = $dash_reqs->fetch_assoc()):
                            $clr = $lr['status'] == 'Approved' ? '#00e676' : ($lr['status'] == 'Rejected' ? '#ff5252' : '#ffb300');
                            $bg = $lr['status'] == 'Approved' ? 'rgba(0,230,118,0.1)' : ($lr['status'] == 'Rejected' ? 'rgba(255,82,82,0.1)' : 'rgba(255,179,0,0.1)');
                    ?>
                    <tr style="border-bottom: 1px solid #2e2f42;">
                        <td style="padding: 12px 15px; border: none; white-space: nowrap;">
                            <div style="font-weight: 500;"><?= date('d M Y', strtotime($lr['leave_from'])) ?></div>
                            <div style="color: #8c8d9e; font-size: 11px;">to <?= date('d M Y', strtotime($lr['leave_to'])) ?></div>
                        </td>
                        <td style="padding: 12px 15px; border: none; color: #a3a4b0; max-width: 120px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;" title="<?= htmlspecialchars($lr['reason']) ?>">
                            <?= htmlspecialchars($lr['reason']) ?>
                        </td>
                        <td style="padding: 12px 15px; border: none;">
                            <span style="color: <?= $clr ?>; background: <?= $bg ?>; padding: 4px 8px; border-radius: 20px; font-size: 10px; font-weight: 600;"><?= htmlspecialchars($lr['status']) ?></span>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="3" style="text-align:center; padding: 20px 15px; color: #8c8d9e; border:none; font-size: 13px;">No recent leaves tracked.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Floating Action Button -->
    <button class="fab-btn">
        <i class="bi bi-arrow-repeat"></i>
    </button>
    
    <?php elseif ($page === 'leave'): ?>
    
    <div style="padding: 20px;">
        <h4 style="margin-bottom: 20px; font-weight: 600;">Request Leave</h4>
        
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success" style="background: rgba(0,230,118,0.1); border: 1px solid rgba(0,230,118,0.3); color: #00e676; padding: 10px; border-radius: 8px; font-size: 14px;">
                Leave application submitted successfully!
            </div>
        <?php endif; ?>
        <?php if($msg): ?>
            <div class="alert alert-danger" style="background: rgba(255,82,82,0.1); border: 1px solid rgba(255,82,82,0.3); color: #ff5252; padding: 10px; border-radius: 8px; font-size: 14px;">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <div style="background-color: #252634; border-radius: 12px; padding: 20px; border: 1px solid #2e2f42; margin-bottom: 25px;">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="submit_leave">
                
                <div style="margin-bottom: 15px;">
                    <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Leave From</label>
                    <input type="date" name="leave_from" required style="width: 100%; background: #1a1a24; border: 1px solid #45475a; color: #fff; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Leave To</label>
                    <input type="date" name="leave_to" required style="width: 100%; background: #1a1a24; border: 1px solid #45475a; color: #fff; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Reason</label>
                    <textarea name="reason" rows="3" required style="width: 100%; background: #1a1a24; border: 1px solid #45475a; color: #fff; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif;" placeholder="e.g. Medical emergency or Out of town..."></textarea>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Attach Document (Optional)</label>
                    <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf" style="width: 100%; background: #1a1a24; border: 1px solid #45475a; color: #8c8d9e; padding: 8px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px;">
                    <div style="font-size: 11px; color: #6c6d7d; margin-top: 5px;">Supported formats: JPG, PNG, PDF (Max 2MB)</div>
                </div>
                
                <button type="submit" style="width: 100%; background: linear-gradient(90deg, #2979ff, #651fff); color: #fff; padding: 12px; border: none; border-radius: 8px; font-weight: 600; font-size: 15px;">Submit Request &rarr;</button>
            </form>
        </div>
        
        <h5 style="margin-bottom: 15px; font-weight: 600; font-size: 16px;">My Past Requests</h5>
        <?php 
            $reqs_res = $conn->query("SELECT * FROM leave_requests WHERE user_id = $user_id ORDER BY id DESC");
            $has_requests = false;
        ?>
        <div style="display:flex; flex-direction: column; gap:10px; margin-bottom: 30px;">
            <?php if($reqs_res && $reqs_res->num_rows > 0): 
                $has_requests = true;
                while($l = $reqs_res->fetch_assoc()): 
                    $status_color = $l['status'] == 'Approved' ? '#00e676' : ($l['status'] == 'Rejected' ? '#ff5252' : '#ffb300');
                    $status_bg = $l['status'] == 'Approved' ? 'rgba(0,230,118,0.1)' : ($l['status'] == 'Rejected' ? 'rgba(255,82,82,0.1)' : 'rgba(255,179,0,0.1)');
            ?>
            <div style="background-color: #252634; border: 1px solid #2e2f42; border-radius: 12px; padding: 15px;">
                <div style="display:flex; justify-content: space-between; align-items:flex-start; margin-bottom:8px;">
                    <div style="font-size: 14px; font-weight: 600;"><?= date('d M Y', strtotime($l['leave_from'])) ?> <span style="color:#8c8d9e;">to</span> <?= date('d M Y', strtotime($l['leave_to'])) ?></div>
                    <div style="color: <?= $status_color ?>; background: <?= $status_bg ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;"><?= htmlspecialchars($l['status']) ?></div>
                </div>
                <div style="font-size: 13px; color: #a3a4b0; margin-bottom: 8px;"><?= htmlspecialchars($l['reason']) ?></div>
                <?php if($l['document_path']): ?>
                    <a href="<?= htmlspecialchars($l['document_path']) ?>" target="_blank" style="font-size: 12px; color: #2979ff; text-decoration: none;"><i class="bi bi-paperclip"></i> View Attached Document</a>
                <?php endif; ?>
            </div>
            <?php endwhile; else: ?>
                <div style="text-align:center; padding: 20px; color: #8c8d9e; font-size: 14px; background-color: #252634; border-radius: 12px; border: 1px solid #2e2f42;">No past leave requests.</div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php elseif ($page === 'holidays'): ?>
    
    <div style="padding: 20px;">
        <h4 style="margin-bottom: 20px; font-weight: 600;">List of Holidays</h4>
        
        <div style="background-color: #252634; border-radius: 12px; border: 1px solid #2e2f42; overflow: hidden;">
            <table class="table" style="color: #fff; margin-bottom: 0px; font-size: 14px;">
                <thead>
                    <tr style="background: rgba(41, 121, 255, 0.1); border-bottom: 1px solid #2e2f42;">
                        <th style="color: #8c8d9e; font-weight: 600; font-size: 12px; border: none; padding: 15px; text-transform: uppercase;">Holiday</th>
                        <th style="color: #8c8d9e; font-weight: 600; font-size: 12px; border: none; padding: 15px; text-transform: uppercase;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($holidays)): ?>
                    <tr><td colspan="2" style="text-align:center; padding: 30px; color: #8c8d9e; border:none;">No upcoming holidays set.</td></tr>
                    <?php else: foreach($holidays as $h): ?>
                    <tr style="border-bottom: 1px solid #2e2f42;">
                        <td style="padding: 15px; border: none; vertical-align: middle;">
                            <div style="font-weight: 600; color: #000;"><?= htmlspecialchars($h['name']) ?></div>
                        </td>
                        <td style="padding: 15px; border: none; vertical-align: middle;">
                            <div style="color: #00e676; font-weight: 500;"><?= date('d M Y', strtotime($h['holiday_date'])) ?></div>
                            <div style="color: #8c8d9e; font-size: 12px;"><?= date('l', strtotime($h['holiday_date'])) ?></div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 25px; background: rgba(41, 121, 255, 0.05); padding: 15px; border-radius: 12px; border: 1px dashed rgba(41, 121, 255, 0.2);">
            <div style="display: flex; gap: 10px; align-items: flex-start;">
                <i class="bi bi-info-circle" style="color: #2979ff; font-size: 18px;"></i>
                <div style="font-size: 13px; color: #8c8d9e; line-height: 1.5;">
                    These holidays are set by the administration. All employees are eligible for these leaves as per company policy.
                </div>
            </div>
        </div>
    </div>
    
    <?php elseif ($page === 'advance'): ?>
    
    <div style="padding: 20px;">
        <h4 style="margin-bottom: 20px; font-weight: 600;">Advance Money Request</h4>
        
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success" style="background: rgba(0,230,118,0.1); border: 1px solid rgba(0,230,118,0.3); color: #00e676; padding: 10px; border-radius: 8px; font-size: 14px; margin-bottom: 20px;">
                Advance request submitted successfully!
            </div>
        <?php endif; ?>

        <div style="background-color: #252634; border-radius: 12px; padding: 20px; border: 1px solid #2e2f42; margin-bottom: 25px;">
            <form method="POST">
                <input type="hidden" name="action" value="submit_advance">
                
                <div style="margin-bottom: 15px;">
                    <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Request Amount (₹)</label>
                    <input type="number" name="amount" placeholder="e.g. 5000" required style="width: 100%; background: #1a1a24; border: 1px solid #45475a; color: #fff; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Required On Date</label>
                    <input type="date" name="needed_date" required style="width: 100%; background: #1a1a24; border: 1px solid #45475a; color: #fff; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif;">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Reason for Advance</label>
                    <textarea name="reason" rows="3" required style="width: 100%; background: #1a1a24; border: 1px solid #45475a; color: #fff; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif;" placeholder="Briefly explain why you need an advance..."></textarea>
                </div>
                
                <button type="submit" style="width: 100%; background: linear-gradient(90deg, #ffb300, #ff8f00); color: #1a1a24; padding: 12px; border: none; border-radius: 8px; font-weight: 700; font-size: 15px;">Submit Advance Request <i class="bi bi-send-fill" style="margin-left: 5px;"></i></button>
            </form>
        </div>
        
        <h5 style="margin-bottom: 15px; font-weight: 600; font-size: 16px;">My Request History</h5>
        <div style="display:flex; flex-direction: column; gap:12px; margin-bottom: 30px;">
            <?php if(empty($advance_reqs)): ?>
                <div style="text-align:center; padding: 20px; color: #8c8d9e; font-size: 14px; background-color: #252634; border-radius: 12px; border: 1px solid #2e2f42;">No past advance requests found.</div>
            <?php else: foreach($advance_reqs as $ar): 
                $status_color = $ar['status'] == 'Approved' ? '#00e676' : ($ar['status'] == 'Rejected' ? '#ff5252' : '#ffb300');
                $status_bg = $ar['status'] == 'Approved' ? 'rgba(0,230,118,0.1)' : ($ar['status'] == 'Rejected' ? 'rgba(255,82,82,0.1)' : 'rgba(255,179,0,0.1)');
            ?>
            <div style="background-color: #252634; border: 1px solid #2e2f42; border-radius: 12px; padding: 15px;">
                <div style="display:flex; justify-content: space-between; align-items:flex-start; margin-bottom:8px;">
                    <div>
                        <div style="font-size: 18px; font-weight: 700; color: #fff;">₹<?= number_format($ar['amount']) ?></div>
                        <div style="font-size: 11px; color: #8c8d9e;">Requested: <?= date('d M Y', strtotime($ar['created_at'])) ?></div>
                    </div>
                    <div style="color: <?= $status_color ?>; background: <?= $status_bg ?>; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase;"><?= htmlspecialchars($ar['status']) ?></div>
                </div>
                <div style="font-size: 13px; color: #fff; margin-bottom: 4px;"><strong>Needed By:</strong> <?= $ar['needed_date'] ? date('d M Y', strtotime($ar['needed_date'])) : 'N/A' ?></div>
                <div style="font-size: 13px; color: #a3a4b0; line-height: 1.4;"><?= htmlspecialchars($ar['reason']) ?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    
    <?php elseif ($page === 'profile'): ?>
    
    <div style="padding: 20px;">
        <h4 style="margin-bottom: 20px; font-weight: 600;">My Profile</h4>
        
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success" style="background: rgba(0,230,118,0.1); border: 1px solid rgba(0,230,118,0.3); color: #00e676; padding: 10px; border-radius: 8px; font-size: 14px; margin-bottom: 20px;">
                Profile updated successfully!
            </div>
        <?php endif; ?>

        <div style="background-color: #252634; border-radius: 12px; padding: 25px; border: 1px solid #2e2f42; margin-bottom: 30px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <?php if (isset($user_data['profile_photo']) && $user_data['profile_photo']): ?>
                    <img src="<?= htmlspecialchars($user_data['profile_photo']) ?>" alt="Profile" style="width: 120px; height: 120px; border-radius: 50%; border: 3px solid #2979ff; margin-bottom: 15px; object-fit: cover;">
                <?php else: ?>
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($user_data['name'] ?? 'User'); ?>&background=2979ff&color=fff&size=200" alt="Profile" style="width: 120px; height: 120px; border-radius: 50%; border: 3px solid #2979ff; margin-bottom: 15px;">
                <?php endif; ?>
                <h5 style="margin: 0; font-weight: 700;"><?= htmlspecialchars($user_data['name'] ?? '') ?></h5>
                <p style="color: #8c8d9e; font-size: 13px; margin-top: 5px;"><?= htmlspecialchars($user_data['emp_id'] ?? '') ?> • <?= htmlspecialchars($user_data['role'] ?? '') ?></p>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="row">
                    <div class="col-12 mb-3">
                        <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Update Profile Photo</label>
                        <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png" style="width: 100%; background: #1a1a24; border: 1px solid #45475a; color: #8c8d9e; padding: 10px; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 13px;">
                        <div style="font-size: 11px; color: #6c6d7d; margin-top: 5px;">Upload a professional photo (JPG, PNG). Max 2MB.</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Full Name (Read Only)</label>
                        <input type="text" value="<?= htmlspecialchars($user_data['name'] ?? '') ?>" readonly style="width: 100%; background: #2e2f42; border: 1px solid #45475a; color: #8c8d9e; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif; cursor: not-allowed;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Employee ID (Read Only)</label>
                        <input type="text" value="<?= htmlspecialchars($user_data['emp_id'] ?? '') ?>" readonly style="width: 100%; background: #2e2f42; border: 1px solid #45475a; color: #8c8d9e; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif; cursor: not-allowed;">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Email Address</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>" required style="width: 100%; background: #1a1a24; border: 1px solid #45475a; color: #fff; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Phone Number</label>
                        <input type="text" name="phone_number" value="<?= htmlspecialchars($user_data['phone_number'] ?? '') ?>" placeholder="e.g. +91 9876543210" style="width: 100%; background: #1a1a24; border: 1px solid #45475a; color: #fff; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif;">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Designation (Read Only)</label>
                        <input type="text" value="<?= htmlspecialchars($user_data['designation'] ?? '') ?>" readonly style="width: 100%; background: #2e2f42; border: 1px solid #45475a; color: #8c8d9e; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif; cursor: not-allowed;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Department (Read Only)</label>
                        <input type="text" value="<?= htmlspecialchars($user_data['dept_name'] ?? 'N/A') ?>" readonly style="width: 100%; background: #2e2f42; border: 1px solid #45475a; color: #8c8d9e; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif; cursor: not-allowed;">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Joining Date (Read Only)</label>
                        <input type="text" value="<?= $user_data['joining_date'] ? date('d M Y', strtotime($user_data['joining_date'])) : 'N/A' ?>" readonly style="width: 100%; background: #2e2f42; border: 1px solid #45475a; color: #8c8d9e; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif; cursor: not-allowed;">
                    </div>
                    <div class="col-md-6 mb-4">
                        <label style="color: #8c8d9e; font-size: 13px; margin-bottom: 5px; display: block;">Base Salary (Read Only)</label>
                        <input type="text" value="₹<?= number_format($user_data['base_salary'] ?? 0) ?>" readonly style="width: 100%; background: #2e2f42; border: 1px solid #45475a; color: #8c8d9e; padding: 12px; border-radius: 8px; font-family: 'Inter', sans-serif; cursor: not-allowed;">
                    </div>
                </div>
                
                <button type="submit" style="width: 100%; background: linear-gradient(90deg, #2979ff, #651fff); color: #fff; padding: 15px; border: none; border-radius: 8px; font-weight: 700; font-size: 16px; box-shadow: 0 4px 15px rgba(41, 121, 255, 0.3);">Save Changes <i class="bi bi-check2-circle" style="margin-left: 5px;"></i></button>
            </form>
        </div>
    </div>
    
    <?php endif; ?>

    <!-- Theme Toggle FAB -->
    <div class="bottom-nav">
        <a href="?page=home" class="nav-item <?= $page=='home'?'active':'' ?>">
            <i class="bi bi-house-door-fill"></i>
            <span>Home</span>
        </a>
        <a href="?page=leave" class="nav-item <?= $page=='leave'?'active':'' ?>">
            <i class="bi bi-calendar-event"></i>
            <span>Leave</span>
        </a>
        <a href="?page=holidays" class="nav-item <?= $page=='holidays'?'active':'' ?>">
            <i class="bi bi-umbrella"></i>
            <span>Holidays</span>
        </a>
        <a href="?page=advance" class="nav-item <?= $page=='advance'?'active':'' ?>" style="font-weight: 600; font-family: sans-serif; font-size: 13px;">
            <i style="font-style: normal; font-size: 21px;">Rs</i>
            <span style="font-weight: normal; font-size: 12px; font-family: 'Inter', sans-serif;">Advance</span>
        </a>
        <a href="?page=profile" class="nav-item <?= $page=='profile'?'active':'' ?>">
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Script for Live Clock -->
    <script>
        function updateClock() {
            const now = new Date();
            
            // Format time
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('clock').textContent = `${hours}:${minutes}:${seconds}`;
            
            // Format date
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('date').textContent = now.toLocaleDateString('en-GB', options);
        }
        
        // Update immediately and then every second
        updateClock();
        setInterval(updateClock, 1000);

        // Theme Toggle Logic
        document.addEventListener('DOMContentLoaded', () => {
            const themeFab = document.querySelector('.theme-fab');
            const icon = themeFab.querySelector('i');
            
            if (localStorage.getItem('theme') === 'light') {
                document.body.classList.add('light-mode');
                icon.classList.replace('bi-moon-stars-fill', 'bi-sun-fill');
            }

            themeFab.addEventListener('click', () => {
                document.body.classList.toggle('light-mode');
                if (document.body.classList.contains('light-mode')) {
                    localStorage.setItem('theme', 'light');
                    icon.classList.replace('bi-moon-stars-fill', 'bi-sun-fill');
                } else {
                    localStorage.setItem('theme', 'dark');
                    icon.classList.replace('bi-sun-fill', 'bi-moon-stars-fill');
                }
            });
        });

        // Autoplay Appreciation Carousel
        document.addEventListener('DOMContentLoaded', () => {
            const carousel = document.getElementById('appreciationCarousel');
            if (carousel) {
                let scrollInterval;
                const startAutoplay = () => {
                    scrollInterval = setInterval(() => {
                        const cardWidth = carousel.querySelector('div').offsetWidth + 15; // Card width + gap
                        const maxScroll = carousel.scrollWidth - carousel.offsetWidth;
                        
                        if (carousel.scrollLeft >= maxScroll - 5) {
                            carousel.scrollTo({ left: 0, behavior: 'smooth' });
                        } else {
                            carousel.scrollBy({ left: cardWidth, behavior: 'smooth' });
                        }
                    }, 3000);
                };

                const stopAutoplay = () => clearInterval(scrollInterval);

                startAutoplay();

                // Pause on user interaction
                carousel.addEventListener('touchstart', stopAutoplay);
                carousel.addEventListener('mousedown', stopAutoplay);
                carousel.addEventListener('touchend', () => {
                    setTimeout(startAutoplay, 2000);
                });
            }
        });

        function showSelfie(src) {
            document.getElementById('selfieImage').src = src;
            new bootstrap.Modal(document.getElementById('selfieViewerModal')).show();
        }

        function handleBreak() {
            const btn = document.getElementById('breakBtn');
            const action = btn.innerText.includes('START') ? 'start_break' : 'end_break';
            
            const formData = new FormData();
            formData.append('action', action);

            fetch('attendance_process.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert("Error: " + data.message);
                }
            });
        }

        // Attendance Logic
        let currentAction = '';
        const attModal = new bootstrap.Modal(document.getElementById('attendanceModal'));
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const captureBtn = document.getElementById('captureBtn');
        const statusMsg = document.getElementById('statusMsg');
        let stream = null;

        async function handleAttendance(action) {
            currentAction = action;
            document.getElementById('attModalTitle').textContent = action === 'check_in' ? 'Check-In Verification' : 'Check-Out Verification';
            statusMsg.textContent = "Requesting location and camera...";
            attModal.show();

            try {
                // 1. Get Camera Stream
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
                video.srcObject = stream;
                statusMsg.textContent = "Camera ready. Please look at the camera.";
            } catch (err) {
                console.error(err);
                statusMsg.textContent = "Error: Camera access denied or not found.";
                captureBtn.disabled = true;
            }
        }

        captureBtn.addEventListener('click', async () => {
            statusMsg.textContent = "Verifying location and processing...";
            captureBtn.disabled = true;

            // 1. Capture Image
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            const imageData = canvas.toDataURL('image/png');

            // 2. Stop Camera
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }

            // 3. Get Geolocation
            if (!navigator.geolocation) {
                alert("Geolocation is not supported by your browser.");
                attModal.hide();
                return;
            }

            navigator.geolocation.getCurrentPosition(async (position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;

                // 4. Send to Backend
                const formData = new FormData();
                formData.append('action', currentAction);
                formData.append('latitude', lat);
                formData.append('longitude', lng);
                formData.append('selfie', imageData);

                try {
                    const response = await fetch('attendance_process.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        alert(result.message);
                        location.reload();
                    } else {
                        alert("Error: " + result.message);
                        statusMsg.textContent = result.message;
                        captureBtn.disabled = false;
                    }
                } catch (error) {
                    console.error(error);
                    alert("An error occurred during processing.");
                    captureBtn.disabled = false;
                }
            }, (err) => {
                alert("Error getting location: " + err.message);
                captureBtn.disabled = false;
            });
        });

        // Stop camera when modal is closed
        document.getElementById('attendanceModal').addEventListener('hidden.bs.modal', () => {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
            captureBtn.disabled = false;
        });
    </script>
</body>
</html>
