<?php
session_start();
require 'db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit;
}

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Handle CSV Export
if (isset($_GET['action']) && $_GET['action'] == 'export_salary_csv') {
    $month = isset($_GET['month']) ? $conn->real_escape_string($_GET['month']) : date('F Y');
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=salary_export_' . str_replace(' ', '_', $month) . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['EMPLOYEE', 'ID', 'BASE SALARY', 'PRESENT', 'HALF', 'ABSENT', 'LATE FINES', 'OT BONUS', 'NET PAYABLE', 'STATUS']);
    
    $res = $conn->query("SELECT s.*, u.name as emp_name FROM salaries s JOIN users u ON s.user_id = u.id WHERE s.month_year = '$month' ORDER BY s.user_id ASC");
    $emp_id_counter = 1;
    while($row = $res->fetch_assoc()){
        $formatted_id = "EMP" . str_pad($emp_id_counter++, 3, "0", STR_PAD_LEFT);
        fputcsv($output, [
            $row['emp_name'],
            $formatted_id,
            $row['base_salary'],
            $row['present_days'],
            $row['half_days'],
            $row['absent_days'],
            $row['late_fines'],
            $row['ot_bonus'],
            $row['net_payable'],
            $row['status']
        ]);
    }
    fclose($output);
    exit;
}

// Helper: sync attendance into salaries for all users for a month
function syncSalaryFromAttendance($conn, $month) {
    $safe_month = $conn->real_escape_string($month);
    $u_res = $conn->query("SELECT * FROM users WHERE role IN ('Employee', 'HR') ORDER BY name ASC");
    while ($u = $u_res->fetch_assoc()) {
        $uid = (int)$u['id'];
        $base = (int)$u['base_salary'];

        $att_res = $conn->query("SELECT 
            COUNT(CASE WHEN status = 'Present' THEN 1 END) as p_days,
            COUNT(CASE WHEN status = 'Half Day' THEN 1 END) as h_days,
            COUNT(CASE WHEN status = 'Absent' THEN 1 END) as a_days,
            COALESCE(SUM(salary_deduction), 0) as fines
            FROM attendance WHERE user_id = $uid AND DATE_FORMAT(date, '%M %Y') = '$safe_month'");
        $att_row = $att_res->fetch_assoc();

        $p_count = (int)$att_row['p_days'];
        $h_count = (int)$att_row['h_days'];
        $a_count = (int)$att_row['a_days'];
        $paid_days = $p_count + ($h_count * 0.5);
        $fines = (float)$att_row['fines'];
        $daily = $base / 26;

        // Check if a salary record already exists (to preserve ot_bonus)
        $existing = $conn->query("SELECT id, ot_bonus FROM salaries WHERE user_id = $uid AND month_year = '$safe_month'");
        if ($existing->num_rows > 0) {
            $ex_row = $existing->fetch_assoc();
            $ot = (float)$ex_row['ot_bonus'];
            // NET = (paid_days × daily) + OT  [fines shown separately, not deducted]
            $net = round(($daily * $paid_days) + $ot);
            $conn->query("UPDATE salaries SET 
                base_salary = $base, present_days = $p_count, half_days = $h_count, 
                absent_days = $a_count, late_fines = $fines, net_payable = $net 
                WHERE id = {$ex_row['id']}");
        } else {
            $net = round($daily * $paid_days);
            $conn->query("INSERT INTO salaries (user_id, month_year, base_salary, present_days, half_days, absent_days, late_fines, ot_bonus, net_payable) 
                         VALUES ($uid, '$safe_month', $base, $p_count, $h_count, $a_count, $fines, 0, $net)");
        }
    }
}

// Handle Generate/Refresh Salaries — always re-syncs from attendance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'generate_salaries') {
    $month = $conn->real_escape_string($_POST['month']);
    syncSalaryFromAttendance($conn, $month);
    header("Location: admin_dashboard.php?page=salary&month=" . urlencode($month));
    exit;
}

// Handle Update Salaries
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_salaries') {
    $month = $_POST['month'];
    if (isset($_POST['salary_ids']) && is_array($_POST['salary_ids'])) {
        foreach ($_POST['salary_ids'] as $sid) {
            $sid = (int)$sid;
            $p_count = (int)$_POST['present'][$sid];
            $h_count = (int)$_POST['half'][$sid];
            $a_count = (int)$_POST['absent'][$sid];
            $fine = (int)$_POST['fine'][$sid];
            $ot = (int)$_POST['ot'][$sid];
            
            $b_res = $conn->query("SELECT base_salary FROM salaries WHERE id = $sid");
            if ($b_row = $b_res->fetch_assoc()) {
                $base = (int)$b_row['base_salary'];
                $paid_days = $p_count + ($h_count * 0.5);
                $daily = $base / 26;
                // NET = (paid_days × daily) + OT  [fines shown separately, not deducted]
                $net = round(($daily * $paid_days) + $ot);
                $conn->query("UPDATE salaries SET present_days = $p_count, half_days = $h_count, absent_days = $a_count, late_fines = $fine, ot_bonus = $ot, net_payable = $net WHERE id = $sid");
            }
        }
    }
    header("Location: admin_dashboard.php?page=salary&month=" . urlencode($month));
    exit;
}

// Handle Assign Users
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'assign_users') {
    $branch_id = (int)$_POST['branch_id'];
    $conn->query("UPDATE users SET branch_id = NULL WHERE branch_id = $branch_id");
    if (isset($_POST['users']) && is_array($_POST['users'])) {
        foreach ($_POST['users'] as $uid) {
            $uid = (int)$uid;
            $conn->query("UPDATE users SET branch_id = $branch_id WHERE id = $uid");
        }
    }
    header("Location: admin_dashboard.php?page=branches");
    exit;
}

// Handle Add Department
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_department') {
    $dname = $conn->real_escape_string($_POST['department_name']);
    $conn->query("INSERT INTO departments (name) VALUES ('$dname')");
    header("Location: admin_dashboard.php?page=departments");
    exit;
}

// Handle Leave Request Action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_leave', 'reject_leave'])) {
    $req_id = (int)$_POST['request_id'];
    $status = $_POST['action'] == 'approve_leave' ? 'Approved' : 'Rejected';
    $conn->query("UPDATE leave_requests SET status = '$status' WHERE id = $req_id");
    header("Location: admin_dashboard.php?page=leave_requests");
    exit;
}

// Handle Add Employee
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_employee') {
    $ename = $conn->real_escape_string($_POST['emp_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $epass = password_hash($_POST['emp_password'], PASSWORD_DEFAULT);
    $edesi = $conn->real_escape_string($_POST['designation']);
    $ephone = $conn->real_escape_string($_POST['phone_number']);
    $ejoin = $conn->real_escape_string($_POST['joining_date']);
    $esal  = (int)$_POST['base_salary'];
    $edept = isset($_POST['department_id']) && $_POST['department_id'] != '0' ? (int)$_POST['department_id'] : 'NULL';
    $ebrn  = isset($_POST['branch_id']) && $_POST['branch_id'] != '0' ? (int)$_POST['branch_id'] : 'NULL';
    
    $photo_path = "NULL";
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/profiles/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
        $new_filename = 'P_NEW_' . time() . '.' . pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_dir . $new_filename)) {
            $photo_path = "'" . $upload_dir . $new_filename . "'";
        }
    }

    $conn->query("INSERT INTO users (name, email, phone_number, password, role, base_salary, branch_id, designation, department_id, joining_date, profile_photo) 
                 VALUES ('$ename', '$email', '$ephone', '$epass', 'Employee', $esal, $ebrn, '$edesi', $edept, '$ejoin', $photo_path)");
    
    $new_id = $conn->insert_id;
    $new_emp_id = 'E-' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
    $conn->query("UPDATE users SET emp_id = '$new_emp_id' WHERE id = $new_id");
    
    header("Location: admin_dashboard.php?page=employees");
    exit;
}

// Fetch today's HR attendance (if any)
$today = date('Y-m-d');
$hr_att_res = $conn->query("SELECT a.*, u.name as emp_name FROM attendance a JOIN users u ON a.user_id = u.id WHERE u.role = 'HR' AND a.date = '$today' ORDER BY a.id DESC");
$hr_today_attendance = [];
if($hr_att_res) while($row = $hr_att_res->fetch_assoc()) $hr_today_attendance[] = $row;

// Handle Toggle Employee Status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'toggle_employee_status') {
    $uid = (int)$_POST['user_id'];
    $current_status = $_POST['current_status'];
    $new_status = ($current_status === 'Active') ? 'Inactive' : 'Active';
    $conn->query("UPDATE users SET status = '$new_status' WHERE id = $uid");
    header("Location: admin_dashboard.php?page=employees&status=" . (isset($_POST['filter_status']) ? $_POST['filter_status'] : 'Active'));
    exit;
}

// Handle Update Employee Details
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_employee') {
    $uid = (int)$_POST['user_id'];
    $ename = $conn->real_escape_string($_POST['emp_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $ephone = $conn->real_escape_string($_POST['phone_number']);
    $edesi = $conn->real_escape_string($_POST['designation']);
    $ejoin = $conn->real_escape_string($_POST['joining_date']);
    $ebrn  = (int)$_POST['branch_id'];
    $edept = (int)$_POST['department_id'];
    $esal  = (int)$_POST['base_salary'];
    
    $photo_sql = "";
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/profiles/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
        $new_filename = 'P_' . $uid . '_' . time() . '.' . pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_dir . $new_filename)) {
            $photo_path = $upload_dir . $new_filename;
            $photo_sql = ", profile_photo = '$photo_path'";
        }
    }

    $pass_sql = "";
    if (!empty($_POST['emp_password'])) {
        $epass = password_hash($_POST['emp_password'], PASSWORD_DEFAULT);
        $pass_sql = ", password = '$epass'";
    }
    
    $conn->query("UPDATE users SET name = '$ename', email = '$email', phone_number = '$ephone', designation = '$edesi', joining_date = '$ejoin', branch_id = $ebrn, department_id = $edept, base_salary = $esal $photo_sql $pass_sql WHERE id = $uid");
    header("Location: admin_dashboard.php?page=employees&msg=Employee updated successfully");
    exit;
}

// Handle Add Branch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_branch') {
    $bname = $conn->real_escape_string($_POST['branch_name']);
    $radius = (int)$_POST['radius'];
    $lat = (float)$_POST['latitude'];
    $lng = (float)$_POST['longitude'];
    
    // Generate code BRx
    $res = $conn->query("SELECT MAX(id) as max_id FROM branches");
    $row = $res->fetch_assoc();
    $next_id = ($row['max_id'] ? $row['max_id'] : 0) + 1;
    $bcode = "BR" . $next_id;
    
    $conn->query("INSERT INTO branches (name, code, radius, latitude, longitude) VALUES ('$bname', '$bcode', $radius, $lat, $lng)");
    header("Location: admin_dashboard.php?page=branches");
    exit;
}

// Handle Update Settings
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_settings') {
    foreach ($_POST['settings'] as $key => $val) {
        $key = $conn->real_escape_string($key);
        $val = $conn->real_escape_string($val);
        $conn->query("UPDATE settings SET setting_value = '$val' WHERE setting_key = '$key'");
    }
    header("Location: admin_dashboard.php?page=settings&msg=Settings updated successfully");
    exit;
}

// Handle Change Admin Password
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'change_admin_password') {
    $new_pass = $_POST['new_password'];
    $conf_pass = $_POST['confirm_password'];
    
    if ($new_pass === $conf_pass) {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $uid = (int)$_SESSION['user_id'];
        $conn->query("UPDATE users SET password = '$hash' WHERE id = $uid");
        header("Location: admin_dashboard.php?page=settings&msg=Password updated successfully");
    } else {
        header("Location: admin_dashboard.php?page=settings&err=Passwords do not match");
    }
    exit;
}

// Handle Add Holiday
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_holiday') {
    $name = $conn->real_escape_string($_POST['holiday_name']);
    $date = $conn->real_escape_string($_POST['holiday_date']);
    $conn->query("INSERT INTO holidays (name, holiday_date) VALUES ('$name', '$date')");
    header("Location: admin_dashboard.php?page=holidays");
    exit;
}

// Handle Delete Holiday
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_holiday') {
    $hid = (int)$_POST['holiday_id'];
    $conn->query("DELETE FROM holidays WHERE id = $hid");
    header("Location: admin_dashboard.php?page=holidays");
    exit;
}

// Handle Advance Request Action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_advance', 'reject_advance'])) {
    $req_id = (int)$_POST['request_id'];
    $status = $_POST['action'] == 'approve_advance' ? 'Approved' : 'Rejected';
    $conn->query("UPDATE advance_requests SET status = '$status' WHERE id = $req_id");
    header("Location: admin_dashboard.php?page=advance_requests&msg=Advance request rejected.");
    exit;
}

// Handle Add Appreciation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_appreciation') {
    $user_id = (int)$_POST['user_id'];
    $month = $conn->real_escape_string($_POST['month']);
    $reason = $conn->real_escape_string($_POST['reason']);
    $conn->query("INSERT INTO appreciations (user_id, month, reason) VALUES ($user_id, '$month', '$reason')");
    header("Location: admin_dashboard.php?page=appreciation&msg=Appreciation added successfully!");
    exit;
}

// Handle Delete Appreciation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_appreciation') {
    $id = (int)$_POST['appreciation_id'];
    $conn->query("DELETE FROM appreciations WHERE id = $id");
    header("Location: admin_dashboard.php?page=appreciation&msg=Appreciation deleted.");
    exit;
}

// Data fetching
$all_branches_global = [];
$b_res = $conn->query("SELECT id, name FROM branches ORDER BY name ASC");
if($b_res) while($br = $b_res->fetch_assoc()) $all_branches_global[] = $br;

$selected_branch = isset($_GET['branch_filter']) ? (int)$_GET['branch_filter'] : 0;
$branch_query = $selected_branch > 0 ? " AND branch_id = $selected_branch " : "";

$search_term = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$search_query = "";
if ($search_term != '') {
    $search_query = " AND (name LIKE '%$search_term%' OR emp_id LIKE '%$search_term%' OR role LIKE '%$search_term%' OR designation LIKE '%$search_term%') ";
}

$users = [];
$res = $conn->query("SELECT * FROM users WHERE role IN ('Employee', 'HR') $branch_query $search_query ORDER BY name ASC");
if($res) {
    while($row = $res->fetch_assoc()) $users[] = $row;
}
$total_employees = count($users);

// Fetch today's attendance for all employees
$today = date('Y-m-d');
$attendance_data = [];
$att_res = $conn->query("SELECT * FROM attendance WHERE date = '$today'");
if($att_res) while($row = $att_res->fetch_assoc()) $attendance_data[$row['user_id']] = $row;

$present_today = count($attendance_data);
$late_today = 0;
foreach($attendance_data as $ad) {
    if ($ad['check_in'] && date('H:i', strtotime($ad['check_in'])) > '09:30') $late_today++;
}

$appreciations = [];
if ($page === 'appreciation') {
    $res = $conn->query("SELECT a.*, u.name as emp_name, u.emp_id FROM appreciations a JOIN users u ON a.user_id = u.id ORDER BY a.id DESC");
    if($res) {
        while($row = $res->fetch_assoc()) $appreciations[] = $row;
    }
}

$branches = [];
if ($page === 'branches') {
    $res = $conn->query("SELECT * FROM branches ORDER BY id DESC");
    if($res){
        while($row = $res->fetch_assoc()){
            $bid = (int)$row['id'];
            $c_res = $conn->query("SELECT COUNT(*) as c FROM users WHERE branch_id = $bid");
            $row['user_count'] = $c_res->fetch_assoc()['c'];
            $branches[] = $row;
        }
    }
}

$departments = [];
if ($page === 'departments') {
    $res = $conn->query("SELECT * FROM departments ORDER BY id DESC");
    if($res){
        while($row = $res->fetch_assoc()){
            $departments[] = $row;
        }
    }
}

$app_settings = [];
if ($page === 'settings') {
    $res = $conn->query("SELECT * FROM settings");
    while($row = $res->fetch_assoc()) {
        $app_settings[$row['setting_key']] = $row['setting_value'];
    }
}

$holidays = [];
if ($page === 'holidays') {
    $res = $conn->query("SELECT * FROM holidays ORDER BY holiday_date ASC");
    if($res) {
        while($row = $res->fetch_assoc()) $holidays[] = $row;
    }
}

$advance_requests = [];
if ($page === 'advance_requests') {
    $res = $conn->query("SELECT ar.*, u.name as emp_name, u.emp_id FROM advance_requests ar JOIN users u ON ar.user_id = u.id ORDER BY ar.id DESC");
    if($res) {
        while($row = $res->fetch_assoc()) $advance_requests[] = $row;
    }
}

$attendance_logs = [];
if ($page === 'attendance_logs') {
    $log_search = isset($_GET['log_search']) ? $conn->real_escape_string($_GET['log_search']) : '';
    $log_branch = isset($_GET['log_branch']) ? (int)$_GET['log_branch'] : 0;
    $log_month = isset($_GET['log_month']) ? $conn->real_escape_string($_GET['log_month']) : date('F Y');
    
    $where = "WHERE DATE_FORMAT(a.date, '%M %Y') = '$log_month'";
    if ($log_search) $where .= " AND (u.name LIKE '%$log_search%' OR u.emp_id LIKE '%$log_search%')";
    if ($log_branch) $where .= " AND u.branch_id = $log_branch";
    
    $res = $conn->query("SELECT a.*, u.name as emp_name, u.emp_id, b.name as branch_name 
                         FROM attendance a 
                         JOIN users u ON a.user_id = u.id 
                         LEFT JOIN branches b ON u.branch_id = b.id 
                         $where 
                         ORDER BY a.date DESC, a.id DESC LIMIT 500");
    if($res) while($row = $res->fetch_assoc()) $attendance_logs[] = $row;
}

// Notification Data
$pending_leaves = [];
$res = $conn->query("SELECT lr.*, u.name as emp_name FROM leave_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.status = 'Pending' ORDER BY lr.id DESC");
if($res) while($row = $res->fetch_assoc()) $pending_leaves[] = $row;

$pending_advances = [];
$res = $conn->query("SELECT ar.*, u.name as emp_name FROM advance_requests ar JOIN users u ON ar.user_id = u.id WHERE ar.status = 'Pending' ORDER BY ar.id DESC");
if($res) while($row = $res->fetch_assoc()) $pending_advances[] = $row;

$total_notifications = count($pending_leaves) + count($pending_advances);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - SmartAttend</title>
    <!-- Bootstrap CSS for grid spacing -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #0f1015;
            color: #ffffff;
            font-family: 'Inter', sans-serif;
            display: flex;
            min-height: 100vh;
            margin: 0;
            overflow-x: hidden;
        }
        /* Sidebar Styling */
        .sidebar {
            width: 250px;
            background-color: #12131a;
            padding: 25px 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid #1e1f2b;
            flex-shrink: 0;
        }
        .brand {
            display: flex;
            align-items: center;
            padding: 0 20px 30px 20px;
            gap: 12px;
        }
        .brand-icon {
            width: 24px;
            height: 40px;
            background: linear-gradient(180deg, #651fff, #311b92);
            border-radius: 12px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.5);
        }
        .brand-icon::after {
            content: '';
            width: 8px;
            height: 8px;
            background-color: #ff3d00;
            border-radius: 50%;
            position: absolute;
            top: 6px;
        }
        .brand-text {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin: 0;
        }
        .nav-item {
            padding: 14px 20px 14px 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            color: #8c8d9e;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
            position: relative;
        }
        .nav-item:hover, .nav-item.active {
            color: #ffffff;
            background: linear-gradient(90deg, rgba(41, 121, 255, 0.1), transparent);
        }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background-color: #2979ff;
            border-radius: 0 4px 4px 0;
        }
        .nav-item i {
            font-size: 18px;
        }
        .nav-icon-img {
            width: 18px;
            opacity: 0.7;
        }
        .nav-item.active .nav-icon-img { opacity: 1; }
        
        .mt-auto { margin-top: auto; }
        .btn-signout {
            margin: 20px 25px;
            background-color: #2c1d23;
            color: #ef9a9a;
            border: 1px solid #4a2128;
            padding: 12px;
            border-radius: 12px;
            text-align: center;
            font-weight: 600;
            text-decoration: none;
            display: block;
            transition: 0.2s;
        }
        .btn-signout:hover {
            background-color: #3b232a;
            color: #ff5252;
        }

        /* Main Content Styling */
        .main-content {
            flex: 1;
            padding: 35px 45px;
            background-color: #0d0f14;
            max-width: calc(100% - 250px);
        }
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 35px;
        }
        .top-header h2 { margin: 0; font-size: 24px; font-weight: 600; letter-spacing: 0.5px;}
        .top-header .header-date { color: #8c8d9e; font-size: 13px; font-weight: 500; }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }
        .card-summary {
            background-color: #161720;
            border: 1px solid #1e1f2b;
            border-radius: 18px;
            padding: 24px;
        }
        .card-title-sm {
            font-size: 11px;
            color: #6c6d7e;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        .card-value {
            font-size: 38px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 15px;
            font-family: inherit; 
            letter-spacing: -1px;
        }
        .val-blue { color: #2979ff; }
        .val-green { color: #00e676; }
        .val-orange { color: #ffb300; }
        .val-red { color: #ff5252; }
        .card-subtext {
            font-size: 13px;
            color: #8c8d9e;
        }

        /* Table Section */
        .table-wrapper {
            background-color: #161720;
            border: 1px solid #1e1f2b;
            border-radius: 18px;
            padding: 25px;
            overflow-x: auto;
        }
        .table-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 0 10px;
        }
        .table-header-flex h3 { margin: 0; font-size: 16px; font-weight: 600; }
        .table-header-flex .date { color: #6c6d7e; font-size: 13px; }

        .app-table {
            width: 100%;
            color: #ffffff;
            border-collapse: collapse;
        }
        .app-table th {
            color: #6c6d7e;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            padding: 15px 10px;
            border-bottom: 1px solid #1e1f2b;
            text-align: left;
            letter-spacing: 0.5px;
        }
        .app-table td {
            padding: 18px 10px;
            border-bottom: 1px solid #1e1f2b;
            font-size: 13px;
            color: #a3a4b0;
            vertical-align: middle;
        }
        .app-table tbody tr:last-child td { border-bottom: none; }
        .app-table tbody tr:hover { background-color: rgba(255, 255, 255, 0.02); }
        
        .emp-name-block { display: flex; flex-direction: column; }
        .emp-name { font-weight: 600; color: #e0e0e0; font-size: 14px;}
        .emp-role { font-size: 11px; color: #6c6d7e; margin-top: 3px;}

        .badge-absent {
            background-color: #2c2226;
            color: #a3abad;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
        }
        .text-dash { color: #4a4b5c; }
        
        /* Branch Page Specifics */
        .form-section-title { font-size: 16px; font-weight: 600; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
        .form-section-title i { color: #2979ff; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 11px; color: #8c8d9e; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; font-weight: 600; }
        .form-control-dark { width: 100%; background-color: #12131a; border: 1px solid #1e1f2b; border-radius: 8px; padding: 12px 15px; color: #ffffff; font-size: 14px; outline: none; transition: border 0.2s; }
        .form-control-dark:focus { border-color: #2979ff; }
        .form-control-dark::placeholder { color: #4a4b5c; }
        .btn-primary-grad { background: linear-gradient(90deg, #651fff, #2979ff); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; transition: opacity 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary-grad:hover { opacity: 0.9; }
        .pill-users { background-color: rgba(41, 121, 255, 0.1); color: #2979ff; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .btn-outline-action { background-color: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); color: #8c8d9e; padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
        .btn-outline-action:hover { background-color: rgba(41, 121, 255, 0.1); color: #2979ff; border-color: rgba(41, 121, 255, 0.3); }

        /* Light mode overrides for branch form */
        body.light-mode .form-control-dark { background-color: #f8f9fa; border-color: #ced4da; color: #212529; }
        body.light-mode .form-control-dark::placeholder { color: #adb5bd; }
        body.light-mode .btn-outline-action { background-color: #ffffff; border-color: #ced4da; color: #495057; }
        body.light-mode .btn-outline-action:hover { background-color: #e3f2fd; color: #0d47a1; border-color: #90caf9; }
        body.light-mode .pill-users { background-color: #e3f2fd; color: #0d47a1; border-color: #90caf9; }

        /* Floating Button */
        .theme-fab {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 45px;
            height: 45px;
            background-color: #212230;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            border: 1px solid #2e2f42;
            color: #ffb300;
        }

        /* Light Mode Styles */
        body.light-mode { background-color: #f4f6f9; color: #212529; }
        body.light-mode .sidebar { background-color: #ffffff; border-right-color: #e9ecef; }
        body.light-mode .brand-text { color: #212529; }
        body.light-mode .nav-item { color: #6c757d; }
        body.light-mode .nav-item:hover, body.light-mode .nav-item.active { 
            color: #2979ff; 
            background: linear-gradient(90deg, rgba(41, 121, 255, 0.05), transparent); 
        }
        body.light-mode .btn-signout { background-color: #fff0f0; border-color: #ffcdd2; color: #e53935; }
        body.light-mode .btn-signout:hover { background-color: #ffebee; color: #d32f2f; }
        body.light-mode .main-content { background-color: #f4f6f9; }
        body.light-mode .card-summary, body.light-mode .table-wrapper {
            background-color: #ffffff;
            border-color: #e9ecef;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        }
        body.light-mode .top-header h2, body.light-mode .table-header-flex h3 { color: #212529; }
        body.light-mode .card-title-sm { color: #8c8d9e; }
        body.light-mode .app-table th { color: #8c8d9e; border-bottom-color: #e9ecef; }
        body.light-mode .app-table td { color: #495057; border-bottom-color: #f1f3f5; }
        body.light-mode .emp-name { color: #212529; }
        body.light-mode .app-table tbody tr:hover { background-color: #f8f9fa; }
        body.light-mode .theme-fab { background-color: #ffffff; border-color: #e9ecef; box-shadow: 0 4px 12px rgba(0,0,0,0.1); color: #ffb300; }
        body.light-mode .badge-absent { background-color: #fff0f0; color: #e53935; }
        .net-payable { font-weight: 700; color: #ffffff; }
        body.light-mode .net-payable { color: #212529; }
        .badge-pending { background-color: rgba(255, 179, 0, 0.1); color: #ffb300; padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }

        /* Mobile Responsive Styles */
        .mobile-header {
            display: none;
            background-color: #12131a;
            padding: 15px 20px;
            border-bottom: 1px solid #1e1f2b;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1001;
        }
        .hamburger-btn {
            background: none;
            border: none;
            color: #ffffff;
            font-size: 26px;
            cursor: pointer;
            padding: 5px;
            display: flex;
            align-items: center;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .hamburger-btn:active {
            background: rgba(255,255,255,0.05);
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(4px);
            z-index: 1000;
        }

        @media (max-width: 992px) {
            body { flex-direction: column; }
            .sidebar {
                position: fixed;
                left: -260px;
                top: 0;
                bottom: 0;
                z-index: 1002;
                transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: 10px 0 30px rgba(0,0,0,0.5);
                width: 260px;
            }
            .sidebar.active {
                left: 0;
            }
            .main-content {
                max-width: 100%;
                padding: 20px;
            }
            .mobile-header {
                display: flex;
            }
            .sidebar-overlay.active {
                display: block;
            }
            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            .top-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
                margin-bottom: 25px;
            }
            .desktop-notif { display: none; }
            .card-value { font-size: 32px; }
            .table-header-flex { flex-direction: column; align-items: flex-start; gap: 15px; }
        }

        @media (max-width: 576px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }
            .top-header h2 { font-size: 20px; }
            .main-content { padding: 15px; }
            .card-summary { padding: 20px; }
        }

        /* Light Mode Adjustments */
        body.light-mode .mobile-header { background-color: #ffffff; border-color: #e9ecef; }
        body.light-mode .hamburger-btn { color: #212529; }
        body.light-mode .sidebar { box-shadow: 5px 0 20px rgba(0,0,0,0.05); }

        /* Notification Dropdown */
        .notif-wrapper { position: relative; }
        .notif-btn { position: relative; background: none; border: none; color: #8c8d9e; font-size: 22px; cursor: pointer; padding: 5px; display: flex; align-items: center; transition: color 0.2s; }
        .notif-btn:hover { color: #ffffff; }
        .notif-badge { position: absolute; top: 0; right: 0; background: #ff5252; color: white; font-size: 9px; font-weight: 800; padding: 1px 4px; border-radius: 10px; border: 2px solid #12131a; min-width: 16px; text-align: center; }
        .notif-dropdown { display: none; position: absolute; top: 100%; right: 0; width: 300px; background: #161720; border: 1px solid #1e1f2b; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.6); z-index: 1010; margin-top: 15px; overflow: hidden; animation: fadeInNotif 0.2s ease-out; }
        @keyframes fadeInNotif { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .notif-dropdown.active { display: block; }
        .notif-header { padding: 15px; border-bottom: 1px solid #1e1f2b; font-weight: 600; font-size: 14px; background: rgba(255,255,255,0.02); }
        .notif-list { max-height: 400px; overflow-y: auto; }
        .notif-item { padding: 15px; border-bottom: 1px solid #1e1f2b; text-decoration: none; display: flex; gap: 12px; transition: background 0.2s; color: inherit; }
        .notif-item:hover { background: rgba(255,255,255,0.04); color: inherit; }
        .notif-item:last-child { border-bottom: none; }
        .notif-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; }
        .notif-content { flex: 1; }
        .notif-title { font-size: 13px; font-weight: 600; color: #fff; margin-bottom: 3px; }
        .notif-time { font-size: 11px; color: #6c6d7e; }
        
        body.light-mode .notif-btn:hover { color: #212529; }
        body.light-mode .notif-dropdown { background: #ffffff; border-color: #e9ecef; box-shadow: 0 8px 30px rgba(0,0,0,0.1); }
        body.light-mode .notif-header { background: #f8f9fa; color: #212529; }
        body.light-mode .notif-title { color: #212529; }
        body.light-mode .notif-badge { border-color: #ffffff; }
        body.light-mode .notif-item:hover { background: #f8f9fa; }
        .sidebar {
    width: 250px;
    background-color: #12131a;
    padding: 25px 0;
    display: flex;
    flex-direction: column;
    border-right: 1px solid #1e1f2b;
    flex-shrink: 0;

    /* ADD THIS 👇 */
    height: 100vh;
    overflow-y: auto;
}
.sidebar {
    scroll-behavior: smooth;
}

/* Custom scrollbar (optional but professional) */
.sidebar::-webkit-scrollbar {
    width: 5px;
}

.sidebar::-webkit-scrollbar-thumb {
    background: #2e2f42;
    border-radius: 10px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: #444;
}
@media (max-width: 992px) {
    .sidebar {
        height: 100vh;
        overflow-y: auto;
    }
}
.mt-auto { margin-top: auto; }
    </style>
</head>
<body>
    <script>if (localStorage.getItem('theme') === 'light') { document.body.classList.add('light-mode'); }</script>

    <!-- Mobile Header -->
    <div class="mobile-header">
        <div class="brand" style="padding: 0; margin: 0;">
            <div class="brand-icon" style="height: 32px; width: 20px;"></div>
            <h1 class="brand-text" style="font-size: 18px;">SmartAttend</h1>
        </div>
        
        <div style="display: flex; align-items: center; gap: 15px;">
            <!-- Notifications -->
            <div class="notif-wrapper">
                <button class="notif-btn" id="notifToggle">
                    <i class="bi bi-bell"></i>
                    <?php if($total_notifications > 0): ?>
                        <span class="notif-badge"><?= $total_notifications ?></span>
                    <?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        Notifications (<?= $total_notifications ?>)
                    </div>
                    <div class="notif-list">
                        <?php if($total_notifications == 0): ?>
                            <div style="padding: 30px; text-align: center; color: #6c6d7e; font-size: 13px;">No new requests.</div>
                        <?php else: ?>
                            <?php foreach($pending_leaves as $pl): ?>
                                <a href="?page=leave_requests" class="notif-item">
                                    <div class="notif-icon" style="background: rgba(41, 121, 255, 0.1); color: #2979ff;">
                                        <i class="bi bi-calendar-event"></i>
                                    </div>
                                    <div class="notif-content">
                                        <div class="notif-title">Leave Request from <?= htmlspecialchars($pl['emp_name']) ?></div>
                                        <div class="notif-time"><?= date('d M, h:i A', strtotime($pl['created_at'])) ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                            <?php foreach($pending_advances as $pa): ?>
                                <a href="?page=advance_requests" class="notif-item">
                                    <div class="notif-icon" style="background: rgba(0, 230, 118, 0.1); color: #00e676;">
                                        <i class="bi bi-cash-coin"></i>
                                    </div>
                                    <div class="notif-content">
                                        <div class="notif-title">Advance Request from <?= htmlspecialchars($pa['emp_name']) ?> (₹<?= number_format($pa['amount']) ?>)</div>
                                        <div class="notif-time"><?= date('d M, h:i A', strtotime($pa['created_at'])) ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <button class="hamburger-btn" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
        </div>
    </div>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="mainSidebar">
        <div class="brand">
            <!-- Custom drawn icon based on screenshot -->
            <div class="brand-icon"></div>
            <h1 class="brand-text">SmartAttend</h1>
        </div>
        
        <a href="?page=dashboard" class="nav-item <?= $page=='dashboard'?'active':'' ?>">
            <i class="bi bi-bar-chart-fill" style="color: <?= $page=='dashboard'?'#4caf50':'' ?>;"></i>
            <span>Dashboard</span>
        </a>
        <a href="?page=employees" class="nav-item <?= $page=='employees'?'active':'' ?>">
            <i class="bi bi-people-fill" style="color: <?= $page=='employees'?'#2979ff':'' ?>;"></i>
            <span>Employees</span>
        </a>
        <a href="?page=attendance_logs" class="nav-item <?= $page=='attendance_logs'?'active':'' ?>">
            <i class="bi bi-journal-text" style="color: <?= $page=='attendance_logs'?'#2979ff':'' ?>;"></i>
            <span>Attendance Logs</span>
        </a>
        <a href="?page=leave_requests" class="nav-item <?= $page=='leave_requests'?'active':'' ?>">
            <i class="bi bi-calendar-event" style="color: <?= $page=='leave_requests'?'#2979ff':'' ?>;"></i>
            <span>Leave Requests</span>
        </a>
        <a href="?page=holidays" class="nav-item <?= $page=='holidays'?'active':'' ?>">
            <i class="bi bi-sun" style="color: <?= $page=='holidays'?'#ffb300':'' ?>;"></i>
            <span>Holidays</span>
        </a>
        <a href="?page=advance_requests" class="nav-item <?= $page=='advance_requests'?'active':'' ?>">
            <i class="bi bi-cash-coin" style="color: <?= $page=='advance_requests'?'#00e676':'' ?>;"></i>
            <span>Advance Requests</span>
        </a>
        <a href="?page=branches" class="nav-item <?= $page=='branches'?'active':'' ?>">
            <i class="bi bi-building" style="color: <?= $page=='branches'?'#2979ff':'' ?>;"></i>
            <span>Branches</span>
        </a>
        <a href="?page=departments" class="nav-item <?= $page=='departments'?'active':'' ?>">
            <i class="bi bi-diagram-3" style="color: <?= $page=='departments'?'#ff5252':'' ?>;"></i>
            <span>Departments</span>
        </a>
        <a href="?page=salary" class="nav-item <?= $page=='salary'?'active':'' ?>">
            <i class="bi bi-cash-coin" style="<?= $page=='salary'?'color: #ffb300':'' ?>"></i>
            <span>Salary Manager</span>
        </a>
        <a href="?page=appreciation" class="nav-item <?= $page=='appreciation'?'active':'' ?>">
            <i class="bi bi-trophy" style="color: <?= $page=='appreciation'?'#ffb300':'' ?>;"></i>
            <span>Appreciation</span>
        </a>
        <a href="?page=settings" class="nav-item <?= $page=='settings'?'active':'' ?>">
            <i class="bi bi-gear" style="<?= $page=='settings'?'color: #8c8d9e':'' ?>"></i>
            <span>Settings</span>
        </a>
        
        <div class="mt-auto">
            <a href="logout.php" class="btn-signout">Sign Out</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        
        <?php if ($page === 'dashboard'): ?>

        <div class="top-header">
            <h2>Dashboard</h2>
            <div style="display: flex; align-items: center; gap: 20px;">
                <!-- Desktop Notifications -->
                <div class="notif-wrapper desktop-notif">
                    <button class="notif-btn" id="notifToggleDesktop">
                        <i class="bi bi-bell"></i>
                        <?php if($total_notifications > 0): ?>
                            <span class="notif-badge"><?= $total_notifications ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdownDesktop">
                        <div class="notif-header">
                            Notifications (<?= $total_notifications ?>)
                        </div>
                        <div class="notif-list">
                            <?php if($total_notifications == 0): ?>
                                <div style="padding: 30px; text-align: center; color: #6c6d7e; font-size: 13px;">No new requests.</div>
                            <?php else: ?>
                                <?php foreach($pending_leaves as $pl): ?>
                                    <a href="?page=leave_requests" class="notif-item">
                                        <div class="notif-icon" style="background: rgba(41, 121, 255, 0.1); color: #2979ff;">
                                            <i class="bi bi-calendar-event"></i>
                                        </div>
                                        <div class="notif-content">
                                            <div class="notif-title">Leave Request from <?= htmlspecialchars($pl['emp_name']) ?></div>
                                            <div class="notif-time"><?= date('d M, h:i A', strtotime($pl['created_at'])) ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                                <?php foreach($pending_advances as $pa): ?>
                                    <a href="?page=advance_requests" class="notif-item">
                                        <div class="notif-icon" style="background: rgba(0, 230, 118, 0.1); color: #00e676;">
                                            <i class="bi bi-cash-coin"></i>
                                        </div>
                                        <div class="notif-content">
                                            <div class="notif-title">Advance Request from <?= htmlspecialchars($pa['emp_name']) ?> (₹<?= number_format($pa['amount']) ?>)</div>
                                            <div class="notif-time"><?= date('d M, h:i A', strtotime($pa['created_at'])) ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="header-date" id="topClock"></div>
            </div>
        </div>

        <div class="summary-cards">
            <div class="card-summary">
                <div class="card-title-sm">TOTAL EMPLOYEES</div>
                <div class="card-value val-blue"><?= $total_employees ?></div>
                <div class="card-subtext">Registered</div>
            </div>
            <div class="card-summary">
                <div class="card-title-sm">PRESENT TODAY</div>
                <div class="card-value val-green"><?= $present_today ?></div>
                <div class="card-subtext">Checked in</div>
            </div>
            <div class="card-summary">
                <div class="card-title-sm">LATE TODAY</div>
                <div class="card-value val-orange"><?= $late_today ?></div>
                <div class="card-subtext">After 09:30 AM</div>
            </div>
            <div class="card-summary">
                <div class="card-title-sm">TOTAL FINES</div>
                <?php
                $m_start = date('Y-m-01');
                $m_end = date('Y-m-t');
                $f_res = $conn->query("SELECT SUM(salary_deduction) as total FROM attendance WHERE date BETWEEN '$m_start' AND '$m_end'");
                $total_fines = $f_res->fetch_assoc()['total'] ?? 0;
                ?>
                <div class="card-value val-red">₹<?= number_format($total_fines) ?></div>
                <div class="card-subtext">This month (Global)</div>
            </div>
        </div>

        <div class="table-wrapper">
            <div class="table-header-flex">
                <div style="display: flex; align-items: center; gap: 20px;">
                    <h3>Today's Live Attendance</h3>
                    <form method="GET" style="margin: 0; display: flex; align-items: center; gap: 12px;">
                        <input type="hidden" name="page" value="dashboard">
                        
                        <!-- Search Box -->
                        <div style="position: relative; display: flex; align-items: center;">
                            <i class="bi bi-search" style="position: absolute; left: 12px; color: #8c8d9e; font-size: 13px;"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search_term) ?>" placeholder="Search name, ID, role..." 
                                style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 7px 12px 7px 35px; border-radius: 8px; font-size: 13px; outline: none; width: 220px; transition: border-color 0.2s;">
                        </div>

                        <!-- Branch Filter -->
                        <select name="branch_filter" onchange="this.form.submit()" style="background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255,255,255,0.1); color: #000; padding: 7px 12px; border-radius: 8px; font-size: 13px; outline: none; cursor: pointer;">
                            <option value="0">All Branches</option>
                            <?php foreach($all_branches_global as $abg): ?>
                                <option value="<?= $abg['id'] ?>" <?= $selected_branch == $abg['id'] ? 'selected' : '' ?>><?= htmlspecialchars($abg['name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <?php if($search_term != '' || $selected_branch > 0): ?>
                            <a href="?page=dashboard" style="color: #ff5252; font-size: 13px; text-decoration: none;"><i class="bi bi-x-circle"></i> Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="date" id="tableDate"></div>
            </div>
            <table class="app-table">
                <thead>
                    <tr>
                        <th>EMPLOYEE</th>
                        <th>ID</th>
                        <th>SELFIE</th>
                        <th>CHECK IN</th>
                        <th>CHECK OUT</th>
                        <th>BREAK</th>
                        <th>HOURS</th>
                        <th>STATUS</th>
                        <th>FINE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach($users as $u): 
                        $att = $attendance_data[$u['id']] ?? null;
                        $status_badge = '<span class="badge-absent">Absent</span>';
                        if ($att) {
                            $clr = $att['status'] == 'Present' ? '#00e676' : ($att['status'] == 'Half Day' ? '#ffb300' : '#ff5252');
                            $bg = $att['status'] == 'Present' ? 'rgba(0,230,118,0.1)' : ($att['status'] == 'Half Day' ? 'rgba(255,179,0,0.1)' : 'rgba(255,82,82,0.1)');
                            $status_badge = "<span style='color: $clr; background: $bg; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;'>".$att['status']."</span>";
                        }
                    ?>
                    <tr>
                        <td>
                            <div style="display: flex; flex-direction: column;">
                                <span style="font-weight: 600; color: #fff;"><?= htmlspecialchars($u['name']) ?></span>
                                <span style="font-size: 11px; color: #8c8d9e;"><?= htmlspecialchars($u['designation'] ?: $u['role']) ?></span>
                            </div>
                        </td>
                        <td style="font-family: monospace; font-weight: 500;"><?= htmlspecialchars($u['emp_id'] ?: 'E-XXXX') ?></td>
                        <td>
                            <?php if($att && $att['check_in_selfie']): ?>
                                <i class="bi bi-camera-fill" style="color: #2979ff; cursor: pointer; font-size: 18px;" onclick="showSelfie('<?= $att['check_in_selfie'] ?>')"></i>
                            <?php else: ?>
                                <span class="text-dash">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td style="color: #00e676; font-weight: 500;"><?= ($att && $att['check_in']) ? date('H:i', strtotime($att['check_in'])) : '&mdash;' ?></td>
                        <td style="color: #ff5252; font-weight: 500;"><?= ($att && $att['check_out']) ? date('H:i', strtotime($att['check_out'])) : '&mdash;' ?></td>
                        <td>
                            <?php if($att && $att['break_start']): ?>
                                <div style="font-size: 11px; color: #ffb300;">Start: <?= date('H:i', strtotime($att['break_start'])) ?></div>
                                <div style="font-size: 11px; color: #ffb300;">End: <?= $att['break_end'] ? date('H:i', strtotime($att['break_end'])) : 'Active' ?></div>
                            <?php else: ?>
                                <span class="text-dash">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><?= ($att && $att['working_hours']) ? round($att['working_hours'], 1) . 'h' : '&mdash;' ?></td>
                        <td><?= $status_badge ?></td>
                        <td style="color: #ff5252; font-weight: 600;"><?= ($att && $att['salary_deduction'] > 0) ? '₹' . number_format($att['salary_deduction']) : '&mdash;' ?></td>
                    </tr>
                    <?php 
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'branches'): ?>

        <div class="top-header">
            <h2>Branch Management</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <div class="card-summary" style="margin-bottom: 30px; padding: 25px;">
            <div class="form-section-title">
                <i class="bi bi-buildings"></i> Add New Branch
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_branch">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Branch Name</label>
                        <input type="text" name="branch_name" class="form-control-dark" placeholder="e.g. Mumbai Hub" required>
                    </div>
                    <div class="form-group">
                        <label>Radius (Meters)</label>
                        <input type="number" name="radius" class="form-control-dark" placeholder="100" required>
                    </div>
                    <div class="form-group">
                        <label>Latitude</label>
                        <input type="number" step="any" name="latitude" class="form-control-dark" placeholder="e.g. 19.0760" required>
                    </div>
                    <div class="form-group">
                        <label>Longitude</label>
                        <input type="number" step="any" name="longitude" class="form-control-dark" placeholder="e.g. 72.8777" required>
                    </div>
                </div>
                <button type="submit" class="btn-primary-grad">Create Branch <i class="bi bi-chevron-right"></i></button>
            </form>
        </div>

        <div class="table-wrapper">
            <div class="table-header-flex">
                <h3>Office Branches</h3>
            </div>
            <table class="app-table">
                <thead>
                    <tr>
                        <th>BRANCH NAME</th>
                        <th>RADIUS</th>
                        <th>COORDINATES (LAT, LNG)</th>
                        <th>TOTAL ASSIGNED</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($branches)): ?>
                    <tr><td colspan="5" style="text-align:center;">No branches added yet.</td></tr>
                    <?php else: foreach($branches as $b): ?>
                    <tr>
                        <td>
                            <div class="emp-name-block">
                                <span class="emp-name"><?= htmlspecialchars($b['name']) ?></span>
                                <span class="emp-role"><?= htmlspecialchars($b['code']) ?></span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($b['radius']) ?>m</td>
                        <td><?= htmlspecialchars($b['latitude']) ?>, <?= htmlspecialchars($b['longitude']) ?></td>
                        <td><span class="pill-users"><?= $b['user_count'] ?> Users</span></td>
                        <td>
                            <a href="?page=assign_users&branch_id=<?= $b['id'] ?>" class="btn-outline-action" style="text-decoration:none;">Assign Users</a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'assign_users'): 
            $branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
            $res = $conn->query("SELECT * FROM branches WHERE id = $branch_id");
            $branch = $res->fetch_assoc();
            if (!$branch) die("Branch not found");
            
            $u_res = $conn->query("SELECT * FROM users ORDER BY name ASC");
            $all_users = [];
            while($u = $u_res->fetch_assoc()) $all_users[] = $u;
        ?>
        <div class="top-header">
            <h2>Assign Users to <?= htmlspecialchars($branch['name']) ?></h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <div class="card-summary" style="margin-bottom: 30px; padding: 25px;">
            <form method="POST">
                <input type="hidden" name="action" value="assign_users">
                <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
                
                <table class="app-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">SELECT</th>
                            <th>EMPLOYEE</th>
                            <th>EMAIL</th>
                            <th>ROLE</th>
                            <th>CURRENT ASSIGNMENT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($all_users as $u): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="users[]" value="<?= $u['id'] ?>" <?= ($u['branch_id'] == $branch_id) ? 'checked' : '' ?> style="transform: scale(1.3); cursor: pointer;">
                            </td>
                            <td>
                                <div class="emp-name-block">
                                    <span class="emp-name"><?= htmlspecialchars($u['name']) ?></span>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><span class="pill-users"><?= htmlspecialchars($u['role']) ?></span></td>
                            <td>
                                <?php if($u['branch_id']): ?>
                                    <span style="color:#00e676;"><i class="bi bi-building"></i> Assigned (ID: <?= $u['branch_id'] ?>)</span>
                                <?php else: ?>
                                    <span style="color:#8c8d9e;">Unassigned</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top: 25px;">
                    <button type="submit" class="btn-primary-grad">Save Assignments <i class="bi bi-check-circle"></i></button>
                    <a href="?page=branches" style="color: #8c8d9e; margin-left: 20px; text-decoration: none; font-weight: 500;">Cancel</a>
                </div>
            </form>
        </div>

        <?php elseif ($page === 'departments'): ?>

        <div class="top-header">
            <h2>Department Management</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <div class="card-summary" style="margin-bottom: 30px; padding: 25px;">
            <div class="form-section-title">
                <i class="bi bi-diagram-3"></i> Add New Department
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_department">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Department Name</label>
                        <input type="text" name="department_name" class="form-control-dark" placeholder="e.g. Stock Manager, HR, Developer, Designer, etc." required>
                    </div>
                </div>
                <button type="submit" class="btn-primary-grad">Create Department <i class="bi bi-chevron-right"></i></button>
            </form>
        </div>

        <div class="table-wrapper">
            <div class="table-header-flex">
                <h3>Registered Departments</h3>
            </div>
            <table class="app-table">
                <thead>
                    <tr>
                        <th>DEPARTMENT NAME</th>
                        <th>CREATED ON</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($departments)): ?>
                    <tr><td colspan="2" style="text-align:center;">No departments added yet.</td></tr>
                    <?php else: foreach($departments as $d): ?>
                    <tr>
                        <td>
                            <div class="emp-name-block">
                                <span class="emp-name"><?= htmlspecialchars($d['name']) ?></span>
                            </div>
                        </td>
                        <td><?= date('d M Y, h:i A', strtotime($d['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'employees'): 
            $status_filter = isset($_GET['status']) ? $_GET['status'] : 'Active';
            $emp_res = $conn->query("SELECT u.*, b.name as branch_name, d.name as dept_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id LEFT JOIN departments d ON u.department_id = d.id WHERE u.role IN ('Employee', 'HR') AND u.status = '$status_filter' ORDER BY u.id DESC");
            $employees = [];
            if($emp_res) { while($row = $emp_res->fetch_assoc()) $employees[] = $row; }
            
            // fetch dropdowns
            $b_res = $conn->query("SELECT id, name FROM branches");
            $all_branches = [];
            if($b_res) { while($br = $b_res->fetch_assoc()) $all_branches[] = $br; }
            
            $d_res = $conn->query("SELECT id, name FROM departments");
            $all_depts = [];
            if($d_res) { while($dp = $d_res->fetch_assoc()) $all_depts[] = $dp; }
        ?>

        <div class="top-header">
            <h2>Employee Management</h2>
            <div style="display: flex; align-items: center; gap: 20px;">
                <!-- Desktop Notifications -->
                <div class="notif-wrapper desktop-notif">
                    <button class="notif-btn" id="notifToggleEmployees">
                        <i class="bi bi-bell"></i>
                        <?php if($total_notifications > 0): ?>
                            <span class="notif-badge"><?= $total_notifications ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdownEmployees">
                        <div class="notif-header">
                            Notifications (<?= $total_notifications ?>)
                        </div>
                        <div class="notif-list">
                            <?php if($total_notifications == 0): ?>
                                <div style="padding: 30px; text-align: center; color: #6c6d7e; font-size: 13px;">No new requests.</div>
                            <?php else: ?>
                                <?php foreach($pending_leaves as $pl): ?>
                                    <a href="?page=leave_requests" class="notif-item">
                                        <div class="notif-icon" style="background: rgba(41, 121, 255, 0.1); color: #2979ff;">
                                            <i class="bi bi-calendar-event"></i>
                                        </div>
                                        <div class="notif-content">
                                            <div class="notif-title">Leave Request from <?= htmlspecialchars($pl['emp_name']) ?></div>
                                            <div class="notif-time"><?= date('d M, h:i A', strtotime($pl['created_at'])) ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                                <?php foreach($pending_advances as $pa): ?>
                                    <a href="?page=advance_requests" class="notif-item">
                                        <div class="notif-icon" style="background: rgba(0, 230, 118, 0.1); color: #00e676;">
                                            <i class="bi bi-cash-coin"></i>
                                        </div>
                                        <div class="notif-content">
                                            <div class="notif-title">Advance Request from <?= htmlspecialchars($pa['emp_name']) ?> (₹<?= number_format($pa['amount']) ?>)</div>
                                            <div class="notif-time"><?= date('d M, h:i A', strtotime($pa['created_at'])) ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="header-date" id="topClock"></div>
            </div>
        </div>

        <div class="card-summary" style="margin-bottom: 30px; padding: 25px;">
            <div class="form-section-title" style="margin-bottom: 25px; font-size: 16px;">
                <i class="bi bi-plus-lg" style="margin-right: 8px;"></i> Add New Employee
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_employee">
                <div class="form-grid" style="column-gap: 30px; row-gap: 20px;">
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">FULL NAME</label>
                        <input type="text" name="emp_name" class="form-control-dark" placeholder="e.g. Rahul Sharma" required style="padding: 12px 15px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">EMAIL ADDRESS</label>
                        <input type="email" name="email" class="form-control-dark" placeholder="e.g. rahul@company.com" required style="padding: 12px 15px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">PHONE NUMBER</label>
                        <input type="text" name="phone_number" class="form-control-dark" placeholder="e.g. +91 9876543210" style="padding: 12px 15px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">JOINING DATE</label>
                        <input type="date" name="joining_date" class="form-control-dark" required style="padding: 12px 15px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">PASSWORD</label>
                        <input type="password" name="emp_password" class="form-control-dark" placeholder="Set password" required style="padding: 12px 15px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">DESIGNATION</label>
                        <input type="text" name="designation" class="form-control-dark" placeholder="e.g. Developer" style="padding: 12px 15px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">MONTHLY SALARY (₹)</label>
                        <input type="number" name="base_salary" class="form-control-dark" placeholder="e.g. 25000" required style="padding: 12px 15px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">DEPARTMENT</label>
                        <select name="department_id" class="form-control-dark" style="padding: 12px 15px;">
                            <option value="0">Select Department...</option>
                            <?php foreach($all_depts as $dp): ?>
                                <option value="<?= $dp['id'] ?>"><?= htmlspecialchars($dp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">BRANCH</label>
                        <select name="branch_id" class="form-control-dark" style="padding: 12px 15px;">
                            <option value="0">Select Branch...</option>
                            <?php foreach($all_branches as $br): ?>
                                <option value="<?= $br['id'] ?>"><?= htmlspecialchars($br['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">PROFILE PHOTO</label>
                        <input type="file" name="profile_photo" class="form-control-dark" accept=".jpg,.jpeg,.png" style="padding: 9px 15px;">
                    </div>
                </div>
                <button type="submit" class="btn-primary-grad" style="margin-top: 25px; padding: 12px 25px; background: linear-gradient(90deg, #651fff, #834bff); border: none; font-weight: 500;">Add Employee &rarr;</button>
            </form>
        </div>

        <div class="employee-tabs" style="display: flex; gap: 10px; margin-bottom: 20px;">
            <a href="?page=employees&status=Active" class="btn-outline-action <?= $status_filter === 'Active' ? 'active-tab' : '' ?>" style="text-decoration: none; padding: 8px 20px; border-radius: 20px; <?= $status_filter === 'Active' ? 'background: rgba(41, 121, 255, 0.2); color: #2979ff; border-color: #2979ff;' : '' ?>">
                <i class="bi bi-person-check-fill"></i> Active Employees
            </a>
            <a href="?page=employees&status=Inactive" class="btn-outline-action <?= $status_filter === 'Inactive' ? 'active-tab' : '' ?>" style="text-decoration: none; padding: 8px 20px; border-radius: 20px; <?= $status_filter === 'Inactive' ? 'background: rgba(255, 82, 82, 0.2); color: #ff5252; border-color: #ff5252;' : '' ?>">
                <i class="bi bi-person-x-fill"></i> Inactive Employees
            </a>
        </div>

        <div class="table-wrapper">
            <div class="table-header-flex" style="align-items: center;">
                <h3><?= $status_filter ?> Employees</h3>
                <div class="input-group" style="width: 250px; background: rgba(0,0,0,0.2); border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); overflow: hidden; display: flex; align-items: center;">
                    <span style="padding: 8px 15px; color: #8c8d9e;"><i class="bi bi-search"></i></span>
                    <input type="text" placeholder="Search..." style="background: transparent; border: none; color: #fff; box-shadow: none; width: 100%; outline: none;">
                </div>
            </div>
            <table class="app-table">
                <thead>
                    <tr>
                        <th style="font-size: 11px; text-transform: uppercase;">PHOTO</th>
                        <th style="font-size: 11px; text-transform: uppercase;">NAME & ID</th>
                        <th style="font-size: 11px; text-transform: uppercase;">CONTACT</th>
                        <th style="font-size: 11px; text-transform: uppercase;">JOINED</th>
                        <th style="font-size: 11px; text-transform: uppercase;">DESIGNATION</th>
                        <th style="font-size: 11px; text-transform: uppercase;">SALARY</th>
                        <th style="font-size: 11px; text-transform: uppercase;">STATUS</th>
                        <th style="font-size: 11px; text-transform: uppercase;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($employees)): ?>
                    <tr><td colspan="7" style="text-align:center;">No <?= strtolower($status_filter) ?> employees found.</td></tr>
                    <?php else: foreach($employees as $e): ?>
                    <tr>
                        <td>
                            <?php if ($e['profile_photo']): ?>
                                <img src="<?= htmlspecialchars($e['profile_photo']) ?>" style="width: 35px; height: 35px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(255,255,255,0.1);">
                            <?php else: ?>
                                <div style="width: 35px; height: 35px; border-radius: 50%; background: #2979ff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #fff;"><?= substr($e['name'], 0, 1) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="emp-name-block">
                                <span class="emp-name"><?= htmlspecialchars($e['name']) ?></span>
                                <span style="color: #8c8d9e; font-family: monospace; font-size: 11px;"><?= $e['emp_id'] ?: 'E-XXXX' ?></span>
                            </div>
                        </td>
                        <td>
                            <div style="font-size: 12px; color: #fff;"><?= htmlspecialchars($e['email']) ?></div>
                            <div style="font-size: 11px; color: #8c8d9e;"><?= htmlspecialchars($e['phone_number'] ?: 'No Phone') ?></div>
                        </td>
                        <td style="color: #a3a4b0; font-size: 12px;"><?= $e['joining_date'] ? date('d M Y', strtotime($e['joining_date'])) : '—' ?></td>
                        <td>
                            <div style="font-size: 13px; color: #fff;"><?= htmlspecialchars($e['designation'] ?: '—') ?></div>
                            <div style="font-size: 11px; color: #8c8d9e;"><?= htmlspecialchars($e['dept_name'] ?: '—') ?> @ <?= htmlspecialchars($e['branch_name'] ?: '—') ?></div>
                        </td>
                        <td style="font-weight: 600; color: #00e676;">₹<?= number_format($e['base_salary'] ?: 0) ?></td>
                        <td>
                            <?php if ($e['status'] === 'Active'): ?>
                                <span class="badge-pending" style="color: #00e676; background: rgba(0, 230, 118, 0.1); border-color: rgba(0, 230, 118, 0.3);">Active</span>
                            <?php else: ?>
                                <span class="badge-pending" style="color: #ff5252; background: rgba(255, 82, 82, 0.1); border-color: rgba(255, 82, 82, 0.3);">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex; gap:8px;">
                                <!-- Edit Button -->
                                <button type="button" class="btn-outline-action" 
                                    onclick='openEditModal(<?= json_encode($e) ?>)'
                                    style="padding: 4px 8px; font-size: 14px; color: #2979ff; border-color: rgba(41, 121, 255, 0.3);">
                                    <i class="bi bi-pencil-square"></i>
                                </button>

                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="toggle_employee_status">
                                    <input type="hidden" name="user_id" value="<?= $e['id'] ?>">
                                    <input type="hidden" name="current_status" value="<?= $e['status'] ?>">
                                    <input type="hidden" name="filter_status" value="<?= $status_filter ?>">
                                    <button type="submit" class="btn-outline-action" style="padding: 4px 10px; font-size: 11px;">
                                        <?= $e['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'leave_requests'): 
            $lr_res = $conn->query("SELECT lr.*, u.name as emp_name, u.emp_id FROM leave_requests lr JOIN users u ON lr.user_id = u.id ORDER BY lr.id DESC");
            $leave_requests = [];
            if($lr_res) { while($row = $lr_res->fetch_assoc()) $leave_requests[] = $row; }
        ?>
        
        <div class="top-header">
            <h2>Leave Requests</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <div class="table-wrapper" style="margin-top: 30px;">
            <div class="table-header-flex">
                <h3 style="font-size: 15px;">Employee Leave Requests</h3>
            </div>
            <table class="app-table">
                <thead>
                    <tr>
                        <th style="font-size: 11px; text-transform: uppercase;">EMPLOYEE</th>
                        <th style="font-size: 11px; text-transform: uppercase;">DATE</th>
                        <th style="font-size: 11px; text-transform: uppercase;">REASON</th>
                        <th style="font-size: 11px; text-transform: uppercase;">STATUS</th>
                        <th style="font-size: 11px; text-transform: uppercase;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($leave_requests)): ?>
                    <tr><td colspan="5" style="text-align:center;">No leave requests found.</td></tr>
                    <?php else: foreach($leave_requests as $lr): 
                        $clr = $lr['status'] == 'Approved' ? '#00e676' : ($lr['status'] == 'Rejected' ? '#ff5252' : '#ffb300');
                        $bg = $lr['status'] == 'Approved' ? 'rgba(0,230,118,0.1)' : ($lr['status'] == 'Rejected' ? 'rgba(255,82,82,0.1)' : 'rgba(255,179,0,0.1)');
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight: 500; font-size: 14px;"><?= htmlspecialchars($lr['emp_name']) ?></div>
                            <div style="color: #8c8d9e; font-size: 11px; font-family: monospace;"><?= htmlspecialchars($lr['emp_id'] ?: 'E-XXXX') ?></div>
                        </td>
                        <td style="color: #a3a4b0; font-size: 13px;">
                            <?= $lr['leave_from'] . ($lr['leave_from'] !== $lr['leave_to'] ? ' to ' . $lr['leave_to'] : '') ?>
                        </td>
                        <td style="color: #fff; font-size: 13px;">
                            <?= htmlspecialchars($lr['reason']) ?>
                            <?php if($lr['document_path']): ?>
                                <a href="<?= htmlspecialchars($lr['document_path']) ?>" target="_blank" title="View Document" style="margin-left: 8px; color: #a3a4b0; font-size: 16px;"><i class="bi bi-paperclip"></i></a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="color: <?= $clr ?>; background: <?= $bg ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase;"><?= htmlspecialchars($lr['status']) ?></span>
                        </td>
                        <td>
                            <?php if($lr['status'] === 'Pending'): ?>
                            <div style="display:flex; gap: 8px;">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="approve_leave">
                                    <input type="hidden" name="request_id" value="<?= $lr['id'] ?>">
                                    <button type="submit" style="background: rgba(41, 121, 255, 0.15); color: #2979ff; border: 1px solid rgba(41, 121, 255, 0.3); padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor:pointer;">Approve</button>
                                </form>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="reject_leave">
                                    <input type="hidden" name="request_id" value="<?= $lr['id'] ?>">
                                    <button type="submit" style="background: rgba(255, 82, 82, 0.15); color: #ff5252; border: 1px solid rgba(255, 82, 82, 0.3); padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor:pointer;">Reject</button>
                                </form>
                            </div>
                            <?php else: ?>
                            <span style="color: #6c6d7d; font-size: 14px;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'attendance_logs'): ?>
        <div class="top-header">
            <h2>Attendance Logs (Global)</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <div class="card-summary" style="margin-bottom: 25px; padding: 20px;">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="attendance_logs">
                <div class="col-md-3">
                    <label class="form-label small text-uppercase fw-bold" style="color: #8c8d9e;">Search Employee</label>
                    <input type="text" name="log_search" class="form-control-dark" placeholder="Name or ID..." value="<?= htmlspecialchars($log_search ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-uppercase fw-bold" style="color: #8c8d9e;">Branch</label>
                    <select name="log_branch" class="form-control-dark">
                        <option value="0">All Branches</option>
                        <?php foreach($all_branches_global as $br): ?>
                            <option value="<?= $br['id'] ?>" <?= (isset($log_branch) && $log_branch == $br['id']) ? 'selected' : '' ?>><?= htmlspecialchars($br['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-uppercase fw-bold" style="color: #8c8d9e;">Month</label>
                    <select name="log_month" class="form-control-dark">
                        <?php
                        for ($i = 0; $i < 6; $i++) {
                            $m = date('F Y', strtotime("-$i months"));
                            $sel = (isset($log_month) && $m == $log_month) ? 'selected' : '';
                            echo "<option value='$m' $sel>$m</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn-primary-grad w-100">Filter Logs</button>
                </div>
            </form>
        </div>

        <div class="table-wrapper">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>DATE</th>
                        <th>EMPLOYEE</th>
                        <th>BRANCH</th>
                        <th>IN / OUT</th>
                        <th>BREAK</th>
                        <th>HOURS</th>
                        <th>STATUS</th>
                        <th>SELFIE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($attendance_logs)): ?>
                        <tr><td colspan="7" style="text-align:center; padding: 30px;">No logs found for the selected criteria.</td></tr>
                    <?php else: foreach($attendance_logs as $log): 
                        $clr = $log['status'] == 'Present' ? '#00e676' : ($log['status'] == 'Half Day' ? '#ffb300' : '#ff5252');
                        $bg = $log['status'] == 'Present' ? 'rgba(0,230,118,0.1)' : ($log['status'] == 'Half Day' ? 'rgba(255,179,0,0.1)' : 'rgba(255,82,82,0.1)');
                        
                        $display_hours = $log['working_hours'];
                        if ($display_hours === null && $log['check_in'] && $log['check_out']) {
                            $display_hours = (strtotime($log['check_out']) - strtotime($log['check_in'])) / 3600;
                        }
                    ?>
                    <tr>
                        <td style="color: #fff; font-weight: 500;"><?= date('d M Y', strtotime($log['date'])) ?></td>
                        <td>
                            <div style="font-weight: 600; color: #fff;"><?= htmlspecialchars($log['emp_name']) ?></div>
                            <div style="font-size: 11px; color: #8c8d9e;"><?= htmlspecialchars($log['emp_id']) ?></div>
                        </td>
                        <td><span class="pill-users" style="font-size: 10px;"><?= htmlspecialchars($log['branch_name'] ?: 'N/A') ?></span></td>
                        <td>
                            <div style="font-size: 12px; color: #00e676;">In: <?= $log['check_in'] ? date('H:i', strtotime($log['check_in'])) : '--:--' ?></div>
                            <div style="font-size: 12px; color: #ff5252;">Out: <?= $log['check_out'] ? date('H:i', strtotime($log['check_out'])) : '--:--' ?></div>
                        </td>
                        <td>
                            <?php if($log['break_start']): ?>
                                <div style="font-size: 11px; color: #ffb300;">S: <?= date('H:i', strtotime($log['break_start'])) ?></div>
                                <div style="font-size: 11px; color: #ffb300;">E: <?= $log['break_end'] ? date('H:i', strtotime($log['break_end'])) : '--' ?></div>
                            <?php else: ?>
                                --
                            <?php endif; ?>
                        </td>
                        <td style="color: #fff;"><?= ($display_hours !== null) ? round($display_hours, 1) . 'h' : '--' ?></td>
                        <td>
                            <span style="color: <?= $clr ?>; background: <?= $bg ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;"><?= $log['status'] ?></span>
                        </td>
                        <td>
                            <?php if($log['check_in_selfie']): ?>
                                <i class="bi bi-camera-fill" style="color: #2979ff; cursor: pointer; font-size: 18px;" onclick="showSelfie('<?= $log['check_in_selfie'] ?>')"></i>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'salary'): 
            $selected_month = isset($_GET['month']) ? $_GET['month'] : date('F Y');
            $months = [];
            for ($i = 0; $i < 6; $i++) {
                $months[] = date('F Y', strtotime("-$i months"));
            }
            if(!in_array($selected_month, $months)) $months[] = $selected_month;

            $safe_month = $conn->real_escape_string($selected_month);

            // AUTO-SYNC: Always update existing salary records from attendance on page load
            $existing_count = $conn->query("SELECT COUNT(*) as c FROM salaries WHERE month_year = '$safe_month'")->fetch_assoc()['c'];
            if ($existing_count > 0) {
                syncSalaryFromAttendance($conn, $selected_month);
            }

            $s_res = $conn->query("SELECT s.*, u.name, u.role FROM salaries s JOIN users u ON s.user_id = u.id WHERE s.month_year = '$safe_month' ORDER BY s.user_id ASC");
            $salary_records = [];
            while($s = $s_res->fetch_assoc()) $salary_records[] = $s;
            
            $needs_generation = count($salary_records) === 0;
        ?>
        <div class="top-header">
            <h2>Salary Manager</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <div class="card-summary" style="margin-bottom: 20px; padding: 25px;">
            <div class="form-section-title" style="margin-bottom: 15px;">
                <i class="bi bi-cash-stack" style="color: #ffb300;"></i> Monthly Salary Sheet
            </div>
            <form method="POST" action="admin_dashboard.php">
                <input type="hidden" name="action" value="generate_salaries">
                <div style="display: flex; gap: 15px; align-items: center;">
                    <select name="month" class="form-control-dark" style="width: 200px; padding: 10px 15px;" onchange="window.location.href='?page=salary&month='+encodeURIComponent(this.value)">
                        <?php foreach($months as $m): ?>
                            <option value="<?= htmlspecialchars($m) ?>" <?= $m === $selected_month ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if($needs_generation): ?>
                        <button type="submit" class="btn-primary-grad" style="padding: 10px 20px;">Generate Dataset &rarr;</button>
                    <?php else: ?>
                        <div style="padding: 10px 20px; font-weight: 600; color: #00e676; background: rgba(0, 230, 118, 0.1); border-radius: 8px;">Generated <i class="bi bi-check-circle"></i></div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="table-wrapper">
            <div class="table-header-flex">
                <h3>Salary Breakdown - <?= htmlspecialchars($selected_month) ?></h3>
                <a href="?action=export_salary_csv&month=<?= urlencode($selected_month) ?>" class="btn-outline-action" style="color: #2979ff; border-color: #2979ff; text-decoration: none;"><i class="bi bi-download"></i> Export CSV</a>
            </div>
            <form method="POST" action="admin_dashboard.php">
                <input type="hidden" name="action" value="update_salaries">
                <input type="hidden" name="month" value="<?= htmlspecialchars($selected_month) ?>">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>EMPLOYEE</th>
                            <th>ID</th>
                            <th>BASE SALARY</th>
                            <th>PRESENT</th>
                            <th>HALF</th>
                            <th>ABSENT</th>
                            <th>LATE FINES</th>
                            <th>OT BONUS</th>
                            <th>NET PAYABLE</th>
                            <th>STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if(empty($salary_records)): 
                        ?>
                        <tr><td colspan="8" style="text-align:center;">No dataset generated for this month. Please click Generate Dataset.</td></tr>
                        <?php 
                        else:
                        $emp_id_counter = 1;
                        foreach($salary_records as $s): 
                            $formatted_id = "EMP" . str_pad($emp_id_counter, 3, "0", STR_PAD_LEFT);
                        ?>
                        <tr class="salary-row" data-sid="<?= $s['id'] ?>">
                            <td>
                                <div class="emp-name-block">
                                    <span class="emp-name"><?= htmlspecialchars($s['name']) ?></span>
                                    <span class="emp-role"><?= htmlspecialchars($s['role']) ?></span>
                                </div>
                            </td>
                            <td><?= $formatted_id ?>
                                <input type="hidden" name="salary_ids[]" value="<?= $s['id'] ?>">
                            </td>
                            <td class="base-val" data-base="<?= $s['base_salary'] ?>">₹<?= number_format($s['base_salary']) ?></td>
                            <td>
                                <input type="number" step="1" name="present[<?= $s['id'] ?>]" value="<?= $s['present_days'] ?>" class="form-control-dark calc-input p-count" readonly style="width: 55px; padding: 6px; text-align: center; opacity: 0.7; cursor: not-allowed;">
                            </td>
                            <td>
                                <input type="number" step="1" name="half[<?= $s['id'] ?>]" value="<?= $s['half_days'] ?>" class="form-control-dark calc-input h-count" readonly style="width: 55px; padding: 6px; text-align: center; opacity: 0.7; cursor: not-allowed;">
                            </td>
                            <td>
                                <input type="number" step="1" name="absent[<?= $s['id'] ?>]" value="<?= $s['absent_days'] ?>" class="form-control-dark a-count" readonly style="width: 55px; padding: 6px; text-align: center; opacity: 0.7; cursor: not-allowed;">
                            </td>
                            <td>
                                <input type="number" name="fine[<?= $s['id'] ?>]" value="<?= $s['late_fines'] ?>" class="form-control-dark calc-input f-count" readonly style="width: 80px; padding: 6px; text-align: center; opacity: 0.7; cursor: not-allowed;">
                            </td>
                            <td>
                                <input type="number" name="ot[<?= $s['id'] ?>]" value="<?= $s['ot_bonus'] ?>" class="form-control-dark calc-input o-count" style="width: 80px; padding: 6px; text-align: center;">
                            </td>
                            <td class="net-payable" style="font-weight: 700; color: #00e676;">₹<?= number_format($s['net_payable']) ?></td>
                            <td><span class="badge-pending"><?= htmlspecialchars($s['status']) ?></span></td>
                        </tr>
                        <?php 
                            $emp_id_counter++;
                        endforeach; endif; 
                        ?>
                    </tbody>
                </table>
                <?php if(!empty($salary_records)): ?>
                <div style="margin-top: 25px; text-align: right;">
                    <button type="submit" class="btn-primary-grad" style="padding: 12px 25px;">Save Breakdown <i class="bi bi-save" style="margin-left: 5px;"></i></button>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <script>
            // Real-time calculation "portion" script
            document.querySelectorAll('.calc-input').forEach(input => {
                input.addEventListener('input', function() {
                    const tr = this.closest('tr');
                    const base = parseFloat(tr.querySelector('.base-val').dataset.base);
                    
                    const present = parseFloat(tr.querySelector('input[name^="present"]').value) || 0;
                    const fine = parseFloat(tr.querySelector('input[name^="fine"]').value) || 0;
                    const ot = parseFloat(tr.querySelector('input[name^="ot"]').value) || 0;
                    
                    // Division by 26 logic applied dynamically 
                    const net = Math.round((base / 26) * present) - fine + ot;
                    
                    tr.querySelector('.net-payable').textContent = '₹' + net.toLocaleString('en-IN');
                });
            });
        </script>

        <?php elseif ($page === 'advance_requests'): ?>
        <div class="top-header">
            <h2>Advance Money Requests</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <div class="table-wrapper" style="margin-top: 30px;">
            <div class="table-header-flex">
                <h3 style="font-size: 15px;">Pending & Past Advance Requests</h3>
            </div>
            <table class="app-table">
                <thead>
                    <tr>
                        <th style="font-size: 11px; text-transform: uppercase;">EMPLOYEE</th>
                        <th style="font-size: 11px; text-transform: uppercase;">AMOUNT</th>
                        <th style="font-size: 11px; text-transform: uppercase;">NEEDED BY</th>
                        <th style="font-size: 11px; text-transform: uppercase;">REASON</th>
                        <th style="font-size: 11px; text-transform: uppercase;">DATE REQUESTED</th>
                        <th style="font-size: 11px; text-transform: uppercase;">STATUS</th>
                        <th style="font-size: 11px; text-transform: uppercase;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($advance_requests)): ?>
                    <tr><td colspan="7" style="text-align:center;">No advance requests found.</td></tr>
                    <?php else: foreach($advance_requests as $ar): 
                        $clr = $ar['status'] == 'Approved' ? '#00e676' : ($ar['status'] == 'Rejected' ? '#ff5252' : '#ffb300');
                        $bg = $ar['status'] == 'Approved' ? 'rgba(0,230,118,0.1)' : ($ar['status'] == 'Rejected' ? 'rgba(255,82,82,0.1)' : 'rgba(255,179,0,0.1)');
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight: 500; font-size: 14px;"><?= htmlspecialchars($ar['emp_name']) ?></div>
                            <div style="color: #8c8d9e; font-size: 11px; font-family: monospace;"><?= htmlspecialchars($ar['emp_id'] ?: 'E-XXXX') ?></div>
                        </td>
                        <td style="font-weight: 700; color: #fff;">₹<?= number_format($ar['amount']) ?></td>
                        <td style="color: #ffb300; font-weight: 600; font-size: 13px;">
                            <?= $ar['needed_date'] ? date('d M Y', strtotime($ar['needed_date'])) : '—' ?>
                        </td>
                        <td style="color: #a3a4b0; font-size: 13px; max-width: 200px;">
                            <?= htmlspecialchars($ar['reason']) ?>
                        </td>
                        <td style="color: #8c8d9e; font-size: 12px;">
                            <?= date('d M Y', strtotime($ar['created_at'])) ?>
                        </td>
                        <td>
                            <span style="color: <?= $clr ?>; background: <?= $bg ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase;"><?= htmlspecialchars($ar['status']) ?></span>
                        </td>
                        <td>
                            <?php if($ar['status'] === 'Pending'): ?>
                            <div style="display:flex; gap: 8px;">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="approve_advance">
                                    <input type="hidden" name="request_id" value="<?= $ar['id'] ?>">
                                    <button type="submit" style="background: rgba(41, 121, 255, 0.15); color: #2979ff; border: 1px solid rgba(41, 121, 255, 0.3); padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor:pointer;">Approve</button>
                                </form>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="reject_advance">
                                    <input type="hidden" name="request_id" value="<?= $ar['id'] ?>">
                                    <button type="submit" style="background: rgba(255, 82, 82, 0.15); color: #ff5252; border: 1px solid rgba(255, 82, 82, 0.3); padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 500; cursor:pointer;">Reject</button>
                                </form>
                            </div>
                            <?php else: ?>
                            <span style="color: #6c6d7d; font-size: 14px;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'holidays'): ?>
        <div class="top-header">
            <h2>Holiday Management</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <div class="card-summary" style="margin-bottom: 30px; padding: 25px;">
            <div class="form-section-title">
                <i class="bi bi-plus-lg"></i> Add New Holiday
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_holiday">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Holiday Name</label>
                        <input type="text" name="holiday_name" class="form-control-dark" placeholder="e.g. Independence Day" required>
                    </div>
                    <div class="form-group">
                        <label>Holiday Date</label>
                        <input type="date" name="holiday_date" class="form-control-dark" required>
                    </div>
                </div>
                <button type="submit" class="btn-primary-grad">Save Holiday <i class="bi bi-check-lg"></i></button>
            </form>
        </div>

        <div class="table-wrapper">
            <div class="table-header-flex">
                <h3>List of Holidays</h3>
            </div>
            <table class="app-table">
                <thead>
                    <tr>
                        <th>HOLIDAY NAME</th>
                        <th>DATE</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($holidays)): ?>
                    <tr><td colspan="3" style="text-align:center;">No holidays added yet.</td></tr>
                    <?php else: foreach($holidays as $h): ?>
                    <tr>
                        <td>
                            <div class="emp-name-block">
                                <span class="emp-name"><?= htmlspecialchars($h['name']) ?></span>
                            </div>
                        </td>
                        <td><?= date('d M Y (l)', strtotime($h['holiday_date'])) ?></td>
                        <td>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete this holiday?')">
                                <input type="hidden" name="action" value="delete_holiday">
                                <input type="hidden" name="holiday_id" value="<?= $h['id'] ?>">
                                <button type="submit" class="btn-outline-action" style="color: #ff5252; border-color: rgba(255,82,82,0.2);">
                                    <i class="bi bi-trash3"></i> Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'appreciation'): ?>
        <div class="top-header">
            <h2>Employee Appreciation</h2>
            <div style="display: flex; align-items: center; gap: 20px;">
                <!-- Desktop Notifications -->
                <div class="notif-wrapper desktop-notif">
                    <button class="notif-btn" id="notifToggleAppreciation">
                        <i class="bi bi-bell"></i>
                        <?php if($total_notifications > 0): ?>
                            <span class="notif-badge"><?= $total_notifications ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdownAppreciation">
                        <div class="notif-header">Notifications (<?= $total_notifications ?>)</div>
                        <div class="notif-list">
                            <?php if($total_notifications == 0): ?>
                                <div style="padding: 30px; text-align: center; color: #6c6d7e; font-size: 13px;">No new requests.</div>
                            <?php else: ?>
                                <?php foreach($pending_leaves as $pl): ?>
                                    <a href="?page=leave_requests" class="notif-item">
                                        <div class="notif-icon" style="background: rgba(41, 121, 255, 0.1); color: #2979ff;"><i class="bi bi-calendar-event"></i></div>
                                        <div class="notif-content">
                                            <div class="notif-title">Leave Request from <?= htmlspecialchars($pl['emp_name']) ?></div>
                                            <div class="notif-time"><?= date('d M, h:i A', strtotime($pl['created_at'])) ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                                <?php foreach($pending_advances as $pa): ?>
                                    <a href="?page=advance_requests" class="notif-item">
                                        <div class="notif-icon" style="background: rgba(0, 230, 118, 0.1); color: #00e676;"><i class="bi bi-cash-coin"></i></div>
                                        <div class="notif-content">
                                            <div class="notif-title">Advance Request from <?= htmlspecialchars($pa['emp_name']) ?></div>
                                            <div class="notif-time"><?= date('d M, h:i A', strtotime($pa['created_at'])) ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="header-date" id="topClock"></div>
            </div>
        </div>

        <div class="card-summary" style="margin-bottom: 30px; padding: 30px;">
            <div class="form-section-title" style="margin-bottom: 25px;">
                <i class="bi bi-award-fill" style="color: #ffb300; margin-right: 10px;"></i> Appreciate an Employee
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_appreciation">
                <div class="row g-4">
                    <div class="col-md-4">
                        <label class="form-label" style="font-size: 11px; color: #8c8d9e; text-transform: uppercase; font-weight: 600;">Select Employee</label>
                        <select name="user_id" class="form-control-dark" required style="padding: 12px; background-color: #1a1a24; border: 1px solid #2e2f42; color: #fff; width: 100%; border-radius: 8px;">
                            <option value="">Choose Employee...</option>
                            <?php foreach($users as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['emp_id'] ?: 'E-XX') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="font-size: 11px; color: #8c8d9e; text-transform: uppercase; font-weight: 600;">Recognition Month</label>
                        <select name="month" class="form-control-dark" required style="padding: 12px; background-color: #1a1a24; border: 1px solid #2e2f42; color: #fff; width: 100%; border-radius: 8px;">
                            <?php 
                            for ($i = 0; $i < 6; $i++) {
                                $m = date('F Y', strtotime("-$i months"));
                                echo "<option value=\"$m\">$m</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label" style="font-size: 11px; color: #8c8d9e; text-transform: uppercase; font-weight: 600;">Appreciation Reason / Message</label>
                        <textarea name="reason" class="form-control-dark" rows="3" required style="padding: 15px; background-color: #1a1a24; border: 1px solid #2e2f42; color: #fff; width: 100%; border-radius: 8px;" placeholder="e.g. Exceptional performance in project delivery and teamwork..."></textarea>
                    </div>
                </div>
                <button type="submit" class="btn-primary-grad" style="margin-top: 25px; padding: 12px 30px; background: linear-gradient(90deg, #ffb300, #ff8f00); border: none; font-weight: 700; color: #1a1a24;">Post Appreciation <i class="bi bi-star-fill" style="margin-left: 8px;"></i></button>
            </form>
        </div>

        <div class="table-wrapper">
            <div class="table-header-flex">
                <h3 style="font-size: 15px;">Recent Appreciations</h3>
            </div>
            <table class="app-table">
                <thead>
                    <tr>
                        <th style="font-size: 11px; text-transform: uppercase;">EMPLOYEE</th>
                        <th style="font-size: 11px; text-transform: uppercase;">MONTH</th>
                        <th style="font-size: 11px; text-transform: uppercase;">REASON</th>
                        <th style="font-size: 11px; text-transform: uppercase;">DATE POSTED</th>
                        <th style="font-size: 11px; text-transform: uppercase;">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($appreciations)): ?>
                    <tr><td colspan="5" style="text-align:center;">No appreciations posted yet.</td></tr>
                    <?php else: foreach($appreciations as $ap): ?>
                    <tr>
                        <td>
                            <div style="font-weight: 600; font-size: 14px; color: #fff;"><?= htmlspecialchars($ap['emp_name']) ?></div>
                            <div style="font-size: 11px; color: #8c8d9e;"><?= htmlspecialchars($ap['emp_id'] ?: 'E-XXXX') ?></div>
                        </td>
                        <td><span style="background: rgba(255, 179, 0, 0.1); color: #ffb300; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600;"><?= htmlspecialchars($ap['month']) ?></span></td>
                        <td style="color: #a3a4b0; font-size: 13px; max-width: 300px;"><?= htmlspecialchars($ap['reason']) ?></td>
                        <td style="color: #8c8d9e; font-size: 12px;"><?= date('d M Y', strtotime($ap['created_at'])) ?></td>
                        <td>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete this appreciation?');">
                                <input type="hidden" name="action" value="delete_appreciation">
                                <input type="hidden" name="appreciation_id" value="<?= $ap['id'] ?>">
                                <button type="submit" style="background: none; border: none; color: #ff5252; cursor: pointer; font-size: 18px;"><i class="bi bi-trash3"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'settings'): ?>
        <div class="top-header">
            <h2>Settings</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <?php if(isset($_GET['msg'])): ?>
            <div class="alert alert-success" style="background: rgba(0, 230, 118, 0.1); color: #00e676; border: 1px solid rgba(0, 230, 118, 0.2); border-radius: 12px; margin-bottom: 25px;"><?= htmlspecialchars($_GET['msg']) ?></div>
        <?php endif; ?>
        <?php if(isset($_GET['err'])): ?>
            <div class="alert alert-danger" style="background: rgba(255, 82, 82, 0.1); color: #ff5252; border: 1px solid rgba(255, 82, 82, 0.2); border-radius: 12px; margin-bottom: 25px;"><?= htmlspecialchars($_GET['err']) ?></div>
        <?php endif; ?>

        <div class="card-summary" style="margin-bottom: 30px; padding: 30px;">
            <div class="form-section-title" style="margin-bottom: 35px; font-size: 18px; color: #fff;">
                <i class="bi bi-gear-fill" style="margin-right: 12px; color: #8c8d9e;"></i> Office & Policy Settings
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_settings">
                
                <div style="display: flex; flex-direction: column; gap: 30px;">
                    <!-- Check-in Time -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 15px; font-weight: 500; color: #fff;">Office Check-in Time</div>
                            <div style="font-size: 12px; color: #6c6d7e; margin-top: 4px;">Standard check-in deadline</div>
                        </div>
                        <div style="position: relative; width: 200px;">
                            <input type="time" name="settings[checkin_time]" value="<?= htmlspecialchars($app_settings['checkin_time'] ?? '09:30') ?>" class="form-control-dark" style="padding: 10px 15px; text-align: right; appearance: none;">
                            <i class="bi bi-clock" style="position: absolute; right: 40px; top: 50%; transform: translateY(-50%); color: #6c6d7e; font-size: 14px; pointer-events: none;"></i>
                        </div>
                    </div>

                    <!-- Check-out Time -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 15px; font-weight: 500; color: #fff;">Office Check-out Time</div>
                            <div style="font-size: 12px; color: #6c6d7e; margin-top: 4px;">End of shift</div>
                        </div>
                        <div style="position: relative; width: 200px;">
                            <input type="time" name="settings[checkout_time]" value="<?= htmlspecialchars($app_settings['checkout_time'] ?? '18:30') ?>" class="form-control-dark" style="padding: 10px 15px; text-align: right;">
                            <i class="bi bi-clock" style="position: absolute; right: 40px; top: 50%; transform: translateY(-50%); color: #6c6d7e; font-size: 14px; pointer-events: none;"></i>
                        </div>
                    </div>

                    <!-- Late Fine -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 15px; font-weight: 500; color: #fff;">Late Fine per Day (₹)</div>
                            <div style="font-size: 12px; color: #6c6d7e; margin-top: 4px;">Deducted if check-in after deadline</div>
                        </div>
                        <div style="width: 200px;">
                            <input type="number" name="settings[late_fine]" value="<?= htmlspecialchars($app_settings['late_fine'] ?? '100') ?>" class="form-control-dark" style="padding: 10px 15px; text-align: right;">
                        </div>
                    </div>

                    <!-- Overtime Rate -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 15px; font-weight: 500; color: #fff;">Overtime Rate (₹/hr)</div>
                            <div style="font-size: 12px; color: #6c6d7e; margin-top: 4px;">After 9 working hours</div>
                        </div>
                        <div style="width: 200px;">
                            <input type="number" name="settings[ot_rate]" value="<?= htmlspecialchars($app_settings['ot_rate'] ?? '50') ?>" class="form-control-dark" style="padding: 10px 15px; text-align: right;">
                        </div>
                    </div>

                    <!-- Office Locations -->
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-size: 15px; font-weight: 500; color: #fff;">Office Locations Configured</div>
                            <div style="font-size: 12px; color: #6c6d7e; margin-top: 4px;">Managed under Branches tab</div>
                        </div>
                        <div>
                            <span class="badge-pending" style="color: #00e676; background: rgba(0, 230, 118, 0.1); border-color: rgba(0, 230, 118, 0.3); padding: 5px 15px;">Dynamic</span>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 40px; text-align: right;">
                    <button type="submit" class="btn-primary-grad" style="padding: 12px 30px;">Save Policy Settings <i class="bi bi-check2-circle" style="margin-left: 8px;"></i></button>
                </div>
            </form>
        </div>

        <!-- Change Password Section -->
        <div class="card-summary" style="padding: 30px;">
            <div class="form-section-title" style="margin-bottom: 35px; font-size: 18px; color: #fff;">
                <i class="bi bi-key-fill" style="margin-right: 12px; color: #ffb300;"></i> Change Admin Password
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="change_admin_password">
                <div class="form-grid">
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 10px; font-weight: 500; text-transform: uppercase;">NEW PASSWORD</label>
                        <input type="password" name="new_password" class="form-control-dark" placeholder="Enter new password" required style="padding: 14px 18px;">
                    </div>
                    <div class="form-group">
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 10px; font-weight: 500; text-transform: uppercase;">CONFIRM PASSWORD</label>
                        <input type="password" name="confirm_password" class="form-control-dark" placeholder="Confirm password" required style="padding: 14px 18px;">
                    </div>
                </div>
                <div style="margin-top: 25px; text-align: left;">
                    <button type="submit" class="btn-primary-grad" style="padding: 12px 30px; background: linear-gradient(90deg, #ffb300, #ff8f00);">Update Password <i class="bi bi-shield-lock" style="margin-left: 8px;"></i></button>
                </div>
            </form>
        </div>

        <?php endif; ?>

    </div>

    <!-- Theme toggler FAB -->
    <div class="theme-fab">
        <i class="bi bi-moon-stars-fill"></i>
    </div>

    <script>
        function updateClocks() {
            const now = new Date();
            
            // Format for top right: "Wed, 22 Apr, 2026, 10:22 am"
            const optionsTop = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
            const topClock = document.getElementById('topClock');
            if (topClock) topClock.textContent = now.toLocaleString('en-GB', optionsTop);
            
            // Format for table: "Wednesday, 22 April 2026"
            const optionsTable = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
            const tableDate = document.getElementById('tableDate');
            if (tableDate) tableDate.textContent = now.toLocaleString('en-GB', optionsTable);
        }
        
        updateClocks();
        setInterval(updateClocks, 60000); // Update every minute is enough for these formats
    </script>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Edit Employee Modal -->
    <div class="modal fade" id="editEmployeeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="background: #161720; border: 1px solid #2e2f42; color: #fff; border-radius: 16px; overflow: hidden;">
                <div class="modal-header" style="border-bottom: 1px solid #2e2f42; padding: 20px;">
                    <h5 class="modal-title" id="editModalLabel" style="font-weight: 700;">Edit Employee Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body" style="padding: 25px;">
                        <input type="hidden" name="action" value="update_employee">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Employee ID (Read Only)</label>
                                <input type="text" id="edit_emp_id" class="form-control-dark" readonly style="opacity: 0.6; cursor: not-allowed; border-style: dashed;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Full Name</label>
                                <input type="text" name="emp_name" id="edit_user_name" class="form-control-dark" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Email Address</label>
                                <input type="email" name="email" id="edit_email" class="form-control-dark" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Phone Number</label>
                                <input type="text" name="phone_number" id="edit_phone" class="form-control-dark">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Designation</label>
                                <input type="text" name="designation" id="edit_designation" class="form-control-dark">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Joining Date</label>
                                <input type="date" name="joining_date" id="edit_joining_date" class="form-control-dark">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Monthly Salary (₹)</label>
                                <input type="number" name="base_salary" id="edit_salary" class="form-control-dark" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Branch</label>
                                <select name="branch_id" id="edit_branch_id" class="form-control-dark">
                                    <option value="0">Unassigned</option>
                                    <?php foreach($all_branches_global as $b): ?>
                                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Department</label>
                                <select name="department_id" id="edit_dept_id" class="form-control-dark">
                                    <option value="0">No Department</option>
                                    <?php foreach($all_depts as $d): ?>
                                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Change Password (Optional)</label>
                                <input type="password" name="emp_password" class="form-control-dark" placeholder="Leave blank to keep current">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="color: #8c8d9e; font-size: 11px; text-transform: uppercase; font-weight: 600;">Update Photo</label>
                                <input type="file" name="profile_photo" class="form-control-dark" accept=".jpg,.jpeg,.png">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid #2e2f42; padding: 20px;">
                        <button type="button" class="btn-outline-action" data-bs-dismiss="modal" style="border: none;">Cancel</button>
                        <button type="submit" class="btn-primary-grad" style="padding: 10px 25px; min-width: 120px;">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Function to populate and open Edit Modal
        function openEditModal(emp) {
            document.getElementById('edit_user_id').value = emp.id;
            document.getElementById('edit_emp_id').value = emp.emp_id || 'E-XXXX';
            document.getElementById('edit_user_name').value = emp.name;
            document.getElementById('edit_email').value = emp.email;
            document.getElementById('edit_phone').value = emp.phone_number || '';
            document.getElementById('edit_designation').value = emp.designation || '';
            document.getElementById('edit_joining_date').value = emp.joining_date || '';
            document.getElementById('edit_branch_id').value = emp.branch_id || 0;
            document.getElementById('edit_dept_id').value = emp.department_id || 0;
            document.getElementById('edit_salary').value = emp.base_salary || 0;
            
            const myModal = new bootstrap.Modal(document.getElementById('editEmployeeModal'));
            myModal.show();
        }

        function showSelfie(src) {
            document.getElementById('selfieImage').src = src;
            new bootstrap.Modal(document.getElementById('selfieViewerModal')).show();
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Theme Toggle
            const themeFab = document.querySelector('.theme-fab');
            if (themeFab) {
                const icon = themeFab.querySelector('i');
                if (localStorage.getItem('theme') === 'light') {
                    document.body.classList.add('light-mode');
                    if(icon) icon.classList.replace('bi-moon-stars-fill', 'bi-sun-fill');
                }

                themeFab.addEventListener('click', () => {
                    document.body.classList.toggle('light-mode');
                    if (document.body.classList.contains('light-mode')) {
                        localStorage.setItem('theme', 'light');
                        if(icon) icon.classList.replace('bi-moon-stars-fill', 'bi-sun-fill');
                    } else {
                        localStorage.setItem('theme', 'dark');
                        if(icon) icon.classList.replace('bi-sun-fill', 'bi-moon-stars-fill');
                    }
                });
            }

            // Sidebar Toggle for Mobile
            const sidebarToggle = document.getElementById('sidebarToggle');
            const mainSidebar = document.getElementById('mainSidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const navItems = document.querySelectorAll('.nav-item');

            if (sidebarToggle && mainSidebar && sidebarOverlay) {
                const toggleSidebar = () => {
                    mainSidebar.classList.toggle('active');
                    sidebarOverlay.classList.toggle('active');
                    if (mainSidebar.classList.contains('active')) {
                        document.body.style.overflow = 'hidden';
                    } else {
                        document.body.style.overflow = '';
                    }
                };

                sidebarToggle.onclick = toggleSidebar;
                sidebarOverlay.onclick = toggleSidebar;

                // Close sidebar when clicking a nav item on mobile
                navItems.forEach(item => {
                    item.onclick = () => {
                        if (window.innerWidth <= 992 && mainSidebar.classList.contains('active')) {
                            toggleSidebar();
                        }
                    };
                });
            }

            // Notification Toggle logic
            const setupNotif = (btnId, dropdownId) => {
                const btn = document.getElementById(btnId);
                const dropdown = document.getElementById(dropdownId);
                if (btn && dropdown) {
                    btn.onclick = (e) => {
                        e.stopPropagation();
                        dropdown.classList.toggle('active');
                    };
                    document.addEventListener('click', (e) => {
                        if (!btn.contains(e.target) && !dropdown.contains(e.target)) {
                            dropdown.classList.remove('active');
                        }
                    });
                }
            };
            setupNotif('notifToggle', 'notifDropdown');
            setupNotif('notifToggleDesktop', 'notifDropdownDesktop');
            setupNotif('notifToggleEmployees', 'notifDropdownEmployees');

            // Salary Live Calculation
            const calcInputs = document.querySelectorAll('.calc-input');
            calcInputs.forEach(input => {
                input.addEventListener('input', () => {
                    const row = input.closest('.salary-row');
                    const base = parseFloat(row.querySelector('.base-val').dataset.base);
                    const p = parseFloat(row.querySelector('.p-count').value) || 0;
                    const h = parseFloat(row.querySelector('.h-count').value) || 0;
                    const o = parseFloat(row.querySelector('.o-count').value) || 0;
                    
                    // NET = (paid_days × daily) + OT [fines shown for reference, not deducted]
                    const paidDays = p + (h * 0.5);
                    const daily = base / 26;
                    const net = Math.round((daily * paidDays) + o);
                    
                    row.querySelector('.net-payable').textContent = '₹' + net.toLocaleString('en-IN');
                });
            });
        });
    </script>
    <!-- Selfie Viewer Modal -->
    <div class="modal fade" id="selfieViewerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background-color: #161720; border: 1px solid #2e2f42;">
                <div class="modal-body p-0 text-center">
                    <img id="selfieImage" src="" alt="Selfie" style="width: 100%; border-radius: 12px;">
                    <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal"></button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
