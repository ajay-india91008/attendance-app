<?php
session_start();
require 'db.php';

// HR Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'HR') {
    header("Location: login.php");
    exit;
}

$hr_branch_id = (int)$_SESSION['branch_id'];
$hr_user_id = (int)$_SESSION['user_id'];
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Handle CSV Export (Filtered by Branch)
if (isset($_GET['action']) && $_GET['action'] == 'export_salary_csv') {
    $month = isset($_GET['month']) ? $conn->real_escape_string($_GET['month']) : date('F Y');
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=salary_export_' . str_replace(' ', '_', $month) . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['EMPLOYEE', 'ID', 'BASE SALARY', 'PRESENT', 'HALF', 'ABSENT', 'LATE FINES', 'OT BONUS', 'NET PAYABLE', 'STATUS']);
    
    $res = $conn->query("SELECT s.*, u.name as emp_name FROM salaries s JOIN users u ON s.user_id = u.id WHERE s.month_year = '$month' AND u.branch_id = $hr_branch_id ORDER BY s.user_id ASC");
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

// Helper: sync attendance into salaries for branch employees
function syncBranchSalary($conn, $month, $branch_id) {
    $safe_month = $conn->real_escape_string($month);
    $u_res = $conn->query("SELECT * FROM users WHERE branch_id = $branch_id AND role = 'Employee'");
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
    syncBranchSalary($conn, $month, $hr_branch_id);
    header("Location: hr_dashboard.php?page=salary&month=" . urlencode($month));
    exit;
}

// Fetch HR's own today attendance for buttons
$today = date('Y-m-d');
$my_att_res = $conn->query("SELECT * FROM attendance WHERE user_id = $hr_user_id AND date = '$today'");
$my_today_attendance = $my_att_res ? $my_att_res->fetch_assoc() : null;

// Fetch HR's own stats for personal report
$selected_month = isset($_GET['month']) ? $conn->real_escape_string($_GET['month']) : date('F Y');
$my_stats_res = $conn->query("SELECT 
    COUNT(CASE WHEN status = 'Present' THEN 1 END) as present_count,
    COUNT(CASE WHEN status = 'Half Day' THEN 1 END) as half_day_count,
    SUM(salary_deduction) as total_deduction
    FROM attendance 
    WHERE user_id = $hr_user_id AND DATE_FORMAT(date, '%M %Y') = '$selected_month'");
$my_stats = $my_stats_res->fetch_assoc();
$my_paid_days = (int)$my_stats['present_count'] + ((int)$my_stats['half_day_count'] * 0.5);
$hr_base_salary_row = $conn->query("SELECT base_salary FROM users WHERE id = $hr_user_id")->fetch_assoc();
$hr_base_salary = $hr_base_salary_row['base_salary'] ?? 0;
$my_net_earned = ($hr_base_salary / 26) * $my_paid_days;

// Handle Update Salaries
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_salaries') {
    $month = $_POST['month'];
    if (isset($_POST['salary_ids']) && is_array($_POST['salary_ids'])) {
        foreach ($_POST['salary_ids'] as $sid) {
            $sid = (int)$sid;
            // Security: Ensure this salary record belongs to HR's branch
            $check = $conn->query("SELECT s.id FROM salaries s JOIN users u ON s.user_id = u.id WHERE s.id = $sid AND u.branch_id = $hr_branch_id");
            if ($check->num_rows == 0) continue;

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
    header("Location: hr_dashboard.php?page=salary&month=" . urlencode($month));
    exit;
}

// Handle Add Department
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_department') {
    $dname = $conn->real_escape_string($_POST['department_name']);
    $conn->query("INSERT INTO departments (name) VALUES ('$dname')");
    header("Location: hr_dashboard.php?page=departments");
    exit;
}

// Handle Leave Request Action (Filtered by Branch)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_leave', 'reject_leave'])) {
    $req_id = (int)$_POST['request_id'];
    $check = $conn->query("SELECT lr.id FROM leave_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.id = $req_id AND u.branch_id = $hr_branch_id");
    if ($check->num_rows > 0) {
        $status = $_POST['action'] == 'approve_leave' ? 'Approved' : 'Rejected';
        $conn->query("UPDATE leave_requests SET status = '$status' WHERE id = $req_id");
    }
    header("Location: hr_dashboard.php?page=leave_requests");
    exit;
}

// Handle Add Employee (Force Branch ID to HR's Branch)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_employee') {
    $ename = $conn->real_escape_string($_POST['emp_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $epass = password_hash($_POST['emp_password'], PASSWORD_DEFAULT);
    $edesi = $conn->real_escape_string($_POST['designation']);
    $ephone = $conn->real_escape_string($_POST['phone_number']);
    $ejoin = $conn->real_escape_string($_POST['joining_date']);
    $esal  = (int)$_POST['base_salary'];
    $edept = isset($_POST['department_id']) && $_POST['department_id'] != '0' ? (int)$_POST['department_id'] : 'NULL';
    
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
                 VALUES ('$ename', '$email', '$ephone', '$epass', 'Employee', $esal, $hr_branch_id, '$edesi', $edept, '$ejoin', $photo_path)");
    
    $new_id = $conn->insert_id;
    $new_emp_id = 'E-' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
    $conn->query("UPDATE users SET emp_id = '$new_emp_id' WHERE id = $new_id");
    
    header("Location: hr_dashboard.php?page=employees");
    exit;
}

// Handle Toggle Employee Status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'toggle_employee_status') {
    $uid = (int)$_POST['user_id'];
    $check = $conn->query("SELECT id FROM users WHERE id = $uid AND branch_id = $hr_branch_id");
    if ($check->num_rows > 0) {
        $current_status = $_POST['current_status'];
        $new_status = ($current_status === 'Active') ? 'Inactive' : 'Active';
        $conn->query("UPDATE users SET status = '$new_status' WHERE id = $uid");
    }
    header("Location: hr_dashboard.php?page=employees&status=" . (isset($_POST['filter_status']) ? $_POST['filter_status'] : 'Active'));
    exit;
}

// Handle Update Employee Details
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_employee') {
    $uid = (int)$_POST['user_id'];
    // Security: Ensure this employee record belongs to HR's branch
    $check = $conn->query("SELECT id FROM users WHERE id = $uid AND branch_id = $hr_branch_id");
    if ($check->num_rows > 0) {
        $ename = $conn->real_escape_string($_POST['emp_name']);
        $email = $conn->real_escape_string($_POST['email']);
        $ephone = $conn->real_escape_string($_POST['phone_number']);
        $edesi = $conn->real_escape_string($_POST['designation']);
        $ejoin = $conn->real_escape_string($_POST['joining_date']);
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
        
        $conn->query("UPDATE users SET name = '$ename', email = '$email', phone_number = '$ephone', designation = '$edesi', joining_date = '$ejoin', department_id = $edept, base_salary = $esal $photo_sql $pass_sql WHERE id = $uid");
    }
    header("Location: hr_dashboard.php?page=employees&msg=Employee updated successfully");
    exit;
}

// Handle Update Settings
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_settings') {
    foreach ($_POST['settings'] as $key => $val) {
        $key = $conn->real_escape_string($key);
        $val = $conn->real_escape_string($val);
        $conn->query("UPDATE settings SET setting_value = '$val' WHERE setting_key = '$key'");
    }
    header("Location: hr_dashboard.php?page=settings&msg=Settings updated successfully");
    exit;
}

// Handle Change HR Password
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'change_hr_password') {
    $new_pass = $_POST['new_password'];
    $conf_pass = $_POST['confirm_password'];
    if ($new_pass === $conf_pass) {
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $uid = (int)$_SESSION['user_id'];
        $conn->query("UPDATE users SET password = '$hash' WHERE id = $uid");
        header("Location: hr_dashboard.php?page=settings&msg=Password updated successfully");
    } else {
        header("Location: hr_dashboard.php?page=settings&err=Passwords do not match");
    }
    exit;
}

// Handle Advance Request Action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_advance', 'reject_advance'])) {
    $req_id = (int)$_POST['request_id'];
    $check = $conn->query("SELECT ar.id FROM advance_requests ar JOIN users u ON ar.user_id = u.id WHERE ar.id = $req_id AND u.branch_id = $hr_branch_id");
    if ($check->num_rows > 0) {
        $status = $_POST['action'] == 'approve_advance' ? 'Approved' : 'Rejected';
        $conn->query("UPDATE advance_requests SET status = '$status' WHERE id = $req_id");
    }
    header("Location: hr_dashboard.php?page=advance_requests&msg=Advance request updated.");
    exit;
}

// Handle Add Appreciation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_appreciation') {
    $user_id = (int)$_POST['user_id'];
    $check = $conn->query("SELECT id FROM users WHERE id = $user_id AND branch_id = $hr_branch_id");
    if ($check->num_rows > 0) {
        $month = $conn->real_escape_string($_POST['month']);
        $reason = $conn->real_escape_string($_POST['reason']);
        $conn->query("INSERT INTO appreciations (user_id, month, reason) VALUES ($user_id, '$month', '$reason')");
    }
    header("Location: hr_dashboard.php?page=appreciation&msg=Appreciation added successfully!");
    exit;
}

// Handle Delete Appreciation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'delete_appreciation') {
    $id = (int)$_POST['appreciation_id'];
    $check = $conn->query("SELECT a.id FROM appreciations a JOIN users u ON a.user_id = u.id WHERE a.id = $id AND u.branch_id = $hr_branch_id");
    if ($check->num_rows > 0) {
        $conn->query("DELETE FROM appreciations WHERE id = $id");
    }
    header("Location: hr_dashboard.php?page=appreciation&msg=Appreciation deleted.");
    exit;
}

// Data fetching (Filtered by Branch)
$search_term = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$search_query = "";
if ($search_term != '') {
    $search_query = " AND (name LIKE '%$search_term%' OR emp_id LIKE '%$search_term%' OR role LIKE '%$search_term%' OR designation LIKE '%$search_term%') ";
}

$users = [];
$res = $conn->query("SELECT * FROM users WHERE role IN ('Employee', 'HR') AND branch_id = $hr_branch_id $search_query ORDER BY name ASC");
if($res) while($row = $res->fetch_assoc()) $users[] = $row;
$total_employees = count($users);

// Fetch today's attendance for all branch employees
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
    $res = $conn->query("SELECT a.*, u.name as emp_name, u.emp_id FROM appreciations a JOIN users u ON a.user_id = u.id WHERE u.branch_id = $hr_branch_id ORDER BY a.id DESC");
    if($res) while($row = $res->fetch_assoc()) $appreciations[] = $row;
}

$departments = [];
if ($page === 'departments') {
    $res = $conn->query("SELECT * FROM departments ORDER BY id DESC");
    if($res) while($row = $res->fetch_assoc()) $departments[] = $row;
}

$app_settings = [];
if ($page === 'settings') {
    $res = $conn->query("SELECT * FROM settings");
    while($row = $res->fetch_assoc()) $app_settings[$row['setting_key']] = $row['setting_value'];
}

$holidays = [];
if ($page === 'holidays') {
    $res = $conn->query("SELECT * FROM holidays ORDER BY holiday_date ASC");
    if($res) while($row = $res->fetch_assoc()) $holidays[] = $row;
}

$advance_requests = [];
if ($page === 'advance_requests') {
    $res = $conn->query("SELECT ar.*, u.name as emp_name, u.emp_id FROM advance_requests ar JOIN users u ON ar.user_id = u.id WHERE u.branch_id = $hr_branch_id ORDER BY ar.id DESC");
    if($res) while($row = $res->fetch_assoc()) $advance_requests[] = $row;
}

$attendance_logs = [];
if ($page === 'attendance_logs') {
    $log_search = isset($_GET['log_search']) ? $conn->real_escape_string($_GET['log_search']) : '';
    $log_month = isset($_GET['log_month']) ? $conn->real_escape_string($_GET['log_month']) : date('F Y');
    
    $where = "WHERE u.branch_id = $hr_branch_id AND DATE_FORMAT(a.date, '%M %Y') = '$log_month'";
    if ($log_search) $where .= " AND (u.name LIKE '%$log_search%' OR u.emp_id LIKE '%$log_search%')";
    
    $res = $conn->query("SELECT a.*, u.name as emp_name, u.emp_id 
                         FROM attendance a 
                         JOIN users u ON a.user_id = u.id 
                         $where 
                         ORDER BY a.date DESC, a.id DESC LIMIT 300");
    if($res) while($row = $res->fetch_assoc()) $attendance_logs[] = $row;
}

// Notification Data (Filtered by Branch)
$pending_leaves = [];
$res = $conn->query("SELECT lr.*, u.name as emp_name FROM leave_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.status = 'Pending' AND u.branch_id = $hr_branch_id ORDER BY lr.id DESC");
if($res) while($row = $res->fetch_assoc()) $pending_leaves[] = $row;

$pending_advances = [];
$res = $conn->query("SELECT ar.*, u.name as emp_name FROM advance_requests ar JOIN users u ON ar.user_id = u.id WHERE ar.status = 'Pending' AND u.branch_id = $hr_branch_id ORDER BY ar.id DESC");
if($res) while($row = $res->fetch_assoc()) $pending_advances[] = $row;

$total_notifications = count($pending_leaves) + count($pending_advances);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Dashboard - SmartAttend</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        /* [STYLES COPIED FROM admin_dashboard.php] */
        body { background-color: #0f1015; color: #ffffff; font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; margin: 0; overflow-x: hidden; }
        .sidebar { width: 250px; background-color: #12131a; padding: 25px 0; display: flex; flex-direction: column; border-right: 1px solid #1e1f2b; flex-shrink: 0; }
        .brand { display: flex; align-items: center; padding: 0 20px 30px 20px; gap: 12px; }
        .brand-icon { width: 24px; height: 40px; background: linear-gradient(180deg, #651fff, #311b92); border-radius: 12px; position: relative; display: flex; align-items: center; justify-content: center; box-shadow: inset 0 0 5px rgba(0,0,0,0.5); }
        .brand-icon::after { content: ''; width: 8px; height: 8px; background-color: #ff3d00; border-radius: 50%; position: absolute; top: 6px; }
        .brand-text { font-size: 20px; font-weight: 800; letter-spacing: 0.5px; margin: 0; }
        .nav-item { padding: 14px 20px 14px 25px; display: flex; align-items: center; gap: 15px; color: #8c8d9e; text-decoration: none; font-weight: 500; font-size: 14px; transition: all 0.2s; position: relative; }
        .nav-item:hover, .nav-item.active { color: #ffffff; background: linear-gradient(90deg, rgba(41, 121, 255, 0.1), transparent); }
        .nav-item.active::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background-color: #2979ff; border-radius: 0 4px 4px 0; }
        .nav-item i { font-size: 18px; }
        .mt-auto { margin-top: auto; }
        .btn-signout { margin: 20px 25px; background-color: #2c1d23; color: #ef9a9a; border: 1px solid #4a2128; padding: 12px; border-radius: 12px; text-align: center; font-weight: 600; text-decoration: none; display: block; transition: 0.2s; }
        .btn-signout:hover { background-color: #3b232a; color: #ff5252; }
        .main-content { flex: 1; padding: 35px 45px; background-color: #0d0f14; max-width: calc(100% - 250px); }
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 35px; }
        .top-header h2 { margin: 0; font-size: 24px; font-weight: 600; letter-spacing: 0.5px;}
        .top-header .header-date { color: #8c8d9e; font-size: 13px; font-weight: 500; }
        .summary-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 40px; }
        .card-summary { background-color: #161720; border: 1px solid #1e1f2b; border-radius: 18px; padding: 24px; }
        .card-title-sm { font-size: 11px; color: #6c6d7e; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; font-weight: 600; }
        .card-value { font-size: 38px; font-weight: 800; line-height: 1; margin-bottom: 15px; letter-spacing: -1px; }
        .val-blue { color: #2979ff; } .val-green { color: #00e676; } .val-orange { color: #ffb300; } .val-red { color: #ff5252; }
        .table-wrapper { background-color: #161720; border: 1px solid #1e1f2b; border-radius: 18px; padding: 25px; overflow-x: auto; }
        .table-header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 0 10px; }
        .table-header-flex h3 { margin: 0; font-size: 16px; font-weight: 600; }
        .app-table { width: 100%; color: #ffffff; border-collapse: collapse; }
        .app-table th { color: #6c6d7e; font-size: 11px; font-weight: 600; text-transform: uppercase; padding: 15px 10px; border-bottom: 1px solid #1e1f2b; text-align: left; }
        .app-table td { padding: 18px 10px; border-bottom: 1px solid #1e1f2b; font-size: 13px; color: #a3a4b0; vertical-align: middle; }
        .badge-absent { background-color: #2c2226; color: #a3abad; padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
        .badge-pending { background-color: rgba(255, 179, 0, 0.1); color: #ffb300; padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; display: inline-block; }
        .form-control-dark { width: 100%; background-color: #12131a; border: 1px solid #1e1f2b; border-radius: 8px; padding: 12px 15px; color: #ffffff; font-size: 14px; outline: none; }
        .btn-primary-grad { background: linear-gradient(90deg, #651fff, #2979ff); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .theme-fab { position: fixed; bottom: 30px; right: 30px; width: 45px; height: 45px; background-color: #212230; border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; border: 1px solid #2e2f42; color: #ffb300; }
        body.light-mode { background-color: #f4f6f9; color: #212529; }
        body.light-mode .sidebar { background-color: #ffffff; border-right-color: #e9ecef; }
        body.light-mode .brand-text { color: #212529; }
        body.light-mode .main-content { background-color: #f4f6f9; }
        body.light-mode .card-summary, body.light-mode .table-wrapper { background-color: #ffffff; border-color: #e9ecef; }
        body.light-mode .nav-item { color: #6c757d; }
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
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group label { display: block; font-size: 11px; color: #8c8d9e; text-transform: uppercase; margin-bottom: 8px; font-weight: 600; }
        /* Hamburger Button */
.menu-btn {
    display: none;
    background: none;
    border: none;
    color: #fff;
    font-size: 26px;
    cursor: pointer;
}

/* Overlay */
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    z-index: 998;
    display: none;
}

/* Mobile Responsive */
@media (max-width: 992px) {

    .menu-btn {
        display: block;
    }

    .sidebar {
        position: fixed;
        top: 0;
        left: -260px;
        height: 100%;
        z-index: 999;
        transition: all 0.3s ease;
    }

    .sidebar.active {
        left: 0;
    }

    .main-content {
        max-width: 100%;
        padding: 20px;
    }

    .summary-cards {
        grid-template-columns: 1fr 1fr;
    }
}

/* Extra small devices */
@media (max-width: 576px) {
    .summary-cards {
        grid-template-columns: 1fr;
    }
}
.sidebar-overlay {
    backdrop-filter: blur(3px);
}
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
    <div id="sidebarOverlay" class="sidebar-overlay"></div>
    <script>if (localStorage.getItem('theme') === 'light') { document.body.classList.add('light-mode'); }</script>
<button id="menuToggle" class="menu-btn">
    <i class="bi bi-list"></i>
</button>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="brand">
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
        <a href="?page=my_attendance" class="nav-item <?= $page=='my_attendance'?'active':'' ?>">
            <i class="bi bi-person-badge" style="color: <?= $page=='my_attendance'?'#00e676':'' ?>;"></i>
            <span>My Attendance</span>
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
            <h2>Dashboard (<?= htmlspecialchars($_SESSION['name']) ?>)</h2>
            <div style="display: flex; align-items: center; gap: 15px;">
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
                <div class="card-title-sm">BRANCH EMPLOYEES</div>
                <div class="card-value val-blue"><?= $total_employees ?></div>
                <div class="card-subtext">Registered in your branch</div>
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
                <div class="card-title-sm">BRANCH FINES</div>
                <?php
                $m_start = date('Y-m-01');
                $m_end = date('Y-m-t');
                $f_res = $conn->query("SELECT SUM(salary_deduction) as total FROM attendance a JOIN users u ON a.user_id = u.id WHERE u.branch_id = $hr_branch_id AND a.date BETWEEN '$m_start' AND '$m_end'");
                $total_fines = $f_res->fetch_assoc()['total'] ?? 0;
                ?>
                <div class="card-value val-red">₹<?= number_format($total_fines) ?></div>
                <div class="card-subtext">This month</div>
            </div>
        </div>

        <div class="table-wrapper">
            <div class="table-header-flex">
                <div style="display: flex; align-items: center; gap: 20px;">
                    <h3>Today's Live Attendance (Your Branch)</h3>
                    <form method="GET" style="margin: 0; display: flex; align-items: center; gap: 12px;">
                        <input type="hidden" name="page" value="dashboard">
                        
                        <!-- Search Box -->
                        <div style="position: relative; display: flex; align-items: center;">
                            <i class="bi bi-search" style="position: absolute; left: 12px; color: #8c8d9e; font-size: 13px;"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search_term) ?>" placeholder="Search name, ID, role..." 
                                style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 7px 12px 7px 35px; border-radius: 8px; font-size: 13px; outline: none; width: 220px; transition: border-color 0.2s;">
                        </div>

                        <?php if($search_term != ''): ?>
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
                    <?php foreach($users as $u): 
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
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'employees'): 
            $status_filter = isset($_GET['status']) ? $_GET['status'] : 'Active';
            $emp_res = $conn->query("SELECT u.*, d.name as dept_name FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.role IN ('Employee', 'HR') AND u.branch_id = $hr_branch_id AND u.status = '$status_filter' ORDER BY u.id DESC");
            $employees = [];
            while($row = $emp_res->fetch_assoc()) $employees[] = $row;
            
            $d_res = $conn->query("SELECT id, name FROM departments");
            $all_depts = [];
            while($dp = $d_res->fetch_assoc()) $all_depts[] = $dp;
        ?>

        <div class="top-header">
            <h2>Employee Management (Branch)</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <div class="card-summary" style="margin-bottom: 30px; padding: 25px;">
            <div class="form-section-title" style="margin-bottom: 25px; font-size: 16px;">
                <i class="bi bi-plus-lg" style="margin-right: 8px;"></i> Add New Employee to Branch
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
                        <label style="font-size: 11px; color: #8c8d9e; margin-bottom: 8px; font-weight: 600; text-transform: uppercase;">PROFILE PHOTO</label>
                        <input type="file" name="profile_photo" class="form-control-dark" accept=".jpg,.jpeg,.png" style="padding: 9px 15px;">
                    </div>
                </div>
                <button type="submit" class="btn-primary-grad" style="margin-top: 25px; padding: 12px 25px;">Add Employee &rarr;</button>
            </form>
        </div>

        <div class="table-wrapper">
            <table class="app-table">
                <thead>
                    <tr>
                        <th style="font-size: 11px; text-transform: uppercase;">PHOTO</th>
                        <th style="font-size: 11px; text-transform: uppercase;">NAME & ID</th>
                        <th style="font-size: 11px; text-transform: uppercase;">CONTACT</th>
                        <th style="font-size: 11px; text-transform: uppercase;">JOINED</th>
                        <th style="font-size: 11px; text-transform: uppercase;">DESIGNATION</th>
                        <th style="font-size: 11px; text-transform: uppercase;">SALARY</th>
                        <th style="font-size: 11px; text-transform: uppercase;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($employees as $e): ?>
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
                            <div style="font-size: 11px; color: #8c8d9e;"><?= htmlspecialchars($e['dept_name'] ?: '—') ?></div>
                        </td>
                        <td style="font-weight: 600; color: #00e676;">₹<?= number_format($e['base_salary']) ?></td>
                        <td>
                            <div style="display:flex; gap:8px;">
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
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'my_attendance'): ?>
        <div class="top-header">
            <h2>My Personal Attendance</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <!-- Personal Action Buttons -->
        <div class="card-summary" style="margin-bottom: 25px; padding: 30px; text-align: center;">
            <div style="display: flex; gap: 15px; justify-content: center;">
                <button class="btn-primary-grad" style="padding: 15px 40px; border-radius: 30px; <?= ($my_today_attendance && $my_today_attendance['check_in']) ? 'opacity:0.5; pointer-events:none;' : '' ?>" onclick="handlePersonalAttendance('check_in')">
                    <i class="bi bi-fingerprint"></i> IN
                </button>
                
                <?php 
                    $b_text = 'BREAK'; $b_class = 'btn-secondary'; $b_dis = 'disabled';
                    if ($my_today_attendance && $my_today_attendance['check_in'] && !$my_today_attendance['check_out']) {
                        $b_dis = '';
                        if ($my_today_attendance['break_start'] && !$my_today_attendance['break_end']) { $b_text = 'END BREAK'; $b_class = 'btn-warning'; }
                        elseif ($my_today_attendance['break_start'] && $my_today_attendance['break_end']) { $b_text = 'BREAK DONE'; $b_dis = 'disabled'; }
                        else { $b_text = 'START BREAK'; }
                    }
                ?>
                <button class="btn-primary-grad" style="background: linear-gradient(90deg, #ffb300, #ff8f00); padding: 15px 40px; border-radius: 30px;" id="hrBreakBtn" onclick="handleHRBreak()" <?= $b_dis ?>>
                    <i class="bi bi-cup-hot-fill"></i> <?= $b_text ?>
                </button>

                <button class="btn-primary-grad" style="background: linear-gradient(90deg, #ff5252, #ff1744); padding: 15px 40px; border-radius: 30px; <?= ($my_today_attendance && $my_today_attendance['check_in'] && !$my_today_attendance['check_out']) ? '' : 'opacity:0.5; pointer-events:none;' ?>" onclick="handlePersonalAttendance('check_out')">
                    <i class="bi bi-box-arrow-right"></i> OUT
                </button>
            </div>
            <div style="margin-top: 15px; color: #8c8d9e; font-size: 13px;">Use these buttons to mark your own daily attendance.</div>
        </div>

        <!-- Personal Stats Grid -->
        <div class="stats-grid" style="margin-bottom: 25px;">
            <div class="stat-card">
                <div class="stat-value"><?= $my_paid_days ?></div>
                <div class="stat-label">MY PAID DAYS</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">₹<?= number_format($my_net_earned) ?></div>
                <div class="stat-label">ESTIMATED SALARY</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #ffb300;"><?= (int)$my_stats['present_count'] ?> P / <?= (int)$my_stats['half_day_count'] ?> H</div>
                <div class="stat-label">ATTENDANCE STATUS</div>
            </div>
        </div>

        <!-- Personal Attendance History -->
        <div class="table-wrapper">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>DATE</th>
                        <th>IN / OUT</th>
                        <th>BREAK</th>
                        <th>HOURS</th>
                        <th>STATUS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $my_history_res = $conn->query("SELECT * FROM attendance WHERE user_id = $hr_user_id AND DATE_FORMAT(date, '%M %Y') = '$selected_month' ORDER BY date DESC");
                    if($my_history_res && $my_history_res->num_rows > 0):
                        while($row = $my_history_res->fetch_assoc()):
                            $clr = $row['status'] == 'Present' ? '#00e676' : ($row['status'] == 'Half Day' ? '#ffb300' : '#ff5252');
                            $bg = $row['status'] == 'Present' ? 'rgba(0,230,118,0.1)' : ($row['status'] == 'Half Day' ? 'rgba(255,179,0,0.1)' : 'rgba(255,82,82,0.1)');
                            $disp_hrs = $row['working_hours'];
                            if ($disp_hrs === null && $row['check_in'] && $row['check_out']) {
                                $disp_hrs = (strtotime($row['check_out']) - strtotime($row['check_in'])) / 3600;
                            }
                    ?>
                    <tr>
                        <td style="color: #fff;"><?= date('d M Y', strtotime($row['date'])) ?></td>
                        <td>
                            <div style="font-size: 12px; color: #00e676;">In: <?= date('H:i', strtotime($row['check_in'])) ?></div>
                            <div style="font-size: 12px; color: #ff5252;">Out: <?= $row['check_out'] ? date('H:i', strtotime($row['check_out'])) : '--:--' ?></div>
                        </td>
                        <td>
                            <?php if($row['break_start']): ?>
                                <div style="font-size: 11px; color: #ffb300;">S: <?= date('H:i', strtotime($row['break_start'])) ?></div>
                                <div style="font-size: 11px; color: #ffb300;">E: <?= $row['break_end'] ? date('H:i', strtotime($row['break_end'])) : '--' ?></div>
                            <?php else: ?> -- <?php endif; ?>
                        </td>
                        <td style="color: #fff;"><?= $disp_hrs !== null ? round($disp_hrs, 1) . 'h' : '--' ?></td>
                        <td>
                            <span style="color: <?= $clr ?>; background: <?= $bg ?>; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;"><?= $row['status'] ?></span>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="5" style="text-align:center; padding: 30px;">No records found for this month.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'attendance_logs'): ?>
        <div class="top-header">
            <h2>Attendance Logs (My Branch)</h2>
            <div class="header-date" id="topClock"></div>
        </div>

        <div class="card-summary" style="margin-bottom: 25px; padding: 20px;">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="attendance_logs">
                <div class="col-md-4">
                    <label class="form-label small text-uppercase fw-bold" style="color: #8c8d9e;">Search Employee</label>
                    <input type="text" name="log_search" class="form-control-dark" placeholder="Name or ID..." value="<?= htmlspecialchars($log_search ?? '') ?>">
                </div>
                <div class="col-md-4">
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
                <div class="col-md-4 d-flex align-items-end">
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
                        <th>IN / OUT</th>
                        <th>BREAK</th>
                        <th>HOURS</th>
                        <th>STATUS</th>
                        <th>SELFIE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($attendance_logs)): ?>
                        <tr><td colspan="6" style="text-align:center; padding: 30px;">No logs found for the selected criteria.</td></tr>
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
            $safe_month = $conn->real_escape_string($selected_month);

            // AUTO-SYNC: Always update existing salary records from attendance on page load
            $existing_count = $conn->query("SELECT COUNT(*) as c FROM salaries s JOIN users u ON s.user_id = u.id WHERE s.month_year = '$safe_month' AND u.branch_id = $hr_branch_id")->fetch_assoc()['c'];
            if ($existing_count > 0) {
                syncBranchSalary($conn, $selected_month, $hr_branch_id);
            }

            $s_res = $conn->query("SELECT s.*, u.name, u.role FROM salaries s JOIN users u ON s.user_id = u.id WHERE s.month_year = '$safe_month' AND u.branch_id = $hr_branch_id ORDER BY s.user_id ASC");
            $salary_records = [];
            while($s = $s_res->fetch_assoc()) $salary_records[] = $s;
        ?>
        <div class="top-header"><h2>Salary Manager</h2><div class="header-date" id="topClock"></div></div>
        <div class="card-summary" style="margin-bottom: 20px; padding: 25px;">
            <div class="table-header-flex">
                <h3>Salary Breakdown - <?= htmlspecialchars($selected_month) ?></h3>
                <a href="?action=export_salary_csv&month=<?= urlencode($selected_month) ?>" class="btn-outline-action" style="color: #2979ff; border-color: #2979ff; text-decoration: none;"><i class="bi bi-download"></i> Export CSV</a>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="generate_salaries">
                <input type="hidden" name="month" value="<?= htmlspecialchars($selected_month) ?>">
                <button type="submit" class="btn-primary-grad">Generate/Refresh Salary Sheet for <?= htmlspecialchars($selected_month) ?></button>
            </form>
        </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_salaries">
                <input type="hidden" name="month" value="<?= htmlspecialchars($selected_month) ?>">
                <table class="app-table">
                    <thead>
                        <tr>
                            <th>EMPLOYEE</th>
                            <th>BASE</th>
                            <th>PRESENT</th>
                            <th>HALF</th>
                            <th>ABSENT</th>
                            <th>FINES</th>
                            <th>OT</th>
                            <th>NET</th>
                            <th>STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($salary_records)): ?>
                            <tr><td colspan="9" style="text-align:center; padding: 20px;">No salary sheet generated.</td></tr>
                        <?php else: foreach($salary_records as $s): ?>
                        <tr class="salary-row">
                            <td>
                                <div style="font-weight: 600; color: #fff;"><?= htmlspecialchars($s['name']) ?></div>
                                <input type="hidden" name="salary_ids[]" value="<?= $s['id'] ?>">
                            </td>
                            <td class="base-val" data-base="<?= $s['base_salary'] ?>">₹<?= number_format($s['base_salary']) ?></td>
                            <td><input type="number" name="present[<?= $s['id'] ?>]" value="<?= $s['present_days'] ?>" class="form-control-dark calc-input p-count" readonly style="width: 50px; padding: 5px; text-align: center; opacity: 0.7; cursor: not-allowed;"></td>
                            <td><input type="number" name="half[<?= $s['id'] ?>]" value="<?= $s['half_days'] ?>" class="form-control-dark calc-input h-count" readonly style="width: 50px; padding: 5px; text-align: center; opacity: 0.7; cursor: not-allowed;"></td>
                            <td><input type="number" name="absent[<?= $s['id'] ?>]" value="<?= $s['absent_days'] ?>" class="form-control-dark a-count" readonly style="width: 50px; padding: 5px; text-align: center; opacity: 0.7; cursor: not-allowed;"></td>
                            <td><input type="number" name="fine[<?= $s['id'] ?>]" value="<?= $s['late_fines'] ?>" class="form-control-dark calc-input f-count" readonly style="width: 70px; padding: 5px; text-align: center; opacity: 0.7; cursor: not-allowed;"></td>
                            <td><input type="number" name="ot[<?= $s['id'] ?>]" value="<?= $s['ot_bonus'] ?>" class="form-control-dark calc-input o-count" style="width: 70px; padding: 5px; text-align: center;"></td>
                            <td class="net-payable val-green" style="font-weight: 700;">₹<?= number_format($s['net_payable']) ?></td>
                            <td><span class="badge-pending"><?= $s['status'] ?></span></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <?php if(!empty($salary_records)): ?>
                    <button type="submit" class="btn-primary-grad" style="margin-top: 20px;">Save All Changes <i class="bi bi-save"></i></button>
                <?php endif; ?>
            </form>
        </div>

        <?php elseif ($page === 'leave_requests'): 
            $lr_res = $conn->query("SELECT lr.*, u.name as emp_name, u.emp_id FROM leave_requests lr JOIN users u ON lr.user_id = u.id WHERE u.branch_id = $hr_branch_id ORDER BY lr.id DESC");
            $leave_requests = [];
            if($lr_res) { while($row = $lr_res->fetch_assoc()) $leave_requests[] = $row; }
        ?>
        <div class="top-header"><h2>Leave Requests</h2><div class="header-date" id="topClock"></div></div>
        <div class="table-wrapper">
            <table class="app-table">
                <thead><tr><th>EMPLOYEE</th><th>DATE</th><th>REASON</th><th>STATUS</th><th>ACTIONS</th></tr></thead>
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

        <?php elseif ($page === 'advance_requests'): ?>
        <div class="top-header"><h2>Advance Requests</h2><div class="header-date" id="topClock"></div></div>
        <div class="table-wrapper">
            <table class="app-table">
                <thead>
                    <tr>
                        <th style="font-size: 11px; text-transform: uppercase;">EMPLOYEE</th>
                        <th style="font-size: 11px; text-transform: uppercase;">AMOUNT</th>
                        <th style="font-size: 11px; text-transform: uppercase;">REASON</th>
                        <th style="font-size: 11px; text-transform: uppercase;">STATUS</th>
                        <th style="font-size: 11px; text-transform: uppercase;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($advance_requests)): ?>
                    <tr><td colspan="5" style="text-align:center;">No advance requests found.</td></tr>
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
                        <td style="color: #a3a4b0; font-size: 13px; max-width: 200px;"><?= htmlspecialchars($ar['reason']) ?></td>
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

        <?php elseif ($page === 'holidays'): ?>
        <div class="top-header"><h2>Holidays</h2><div class="header-date" id="topClock"></div></div>
        <div class="table-wrapper">
            <table class="app-table">
                <thead><tr><th>HOLIDAY</th><th>DATE</th></tr></thead>
                <tbody>
                    <?php foreach($holidays as $h): ?>
                    <tr><td><?= htmlspecialchars($h['name']) ?></td><td><?= date('d M Y', strtotime($h['holiday_date'])) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'departments'): ?>
        <div class="top-header"><h2>Departments</h2><div class="header-date" id="topClock"></div></div>
        <div class="table-wrapper">
            <table class="app-table">
                <thead><tr><th>DEPARTMENT</th><th>CREATED AT</th></tr></thead>
                <tbody>
                    <?php foreach($departments as $d): ?>
                    <tr><td><?= htmlspecialchars($d['name']) ?></td><td><?= $d['created_at'] ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($page === 'settings'): ?>
        <div class="top-header"><h2>Settings</h2><div class="header-date" id="topClock"></div></div>
        <div class="card-summary" style="padding: 30px;">
            <div class="form-section-title"><i class="bi bi-key-fill"></i> Change Your Password</div>
            <form method="POST">
                <input type="hidden" name="action" value="change_hr_password">
                <div class="form-grid">
                    <div class="form-group"><label>New Password</label><input type="password" name="new_password" class="form-control-dark" required></div>
                    <div class="form-group"><label>Confirm Password</label><input type="password" name="confirm_password" class="form-control-dark" required></div>
                </div>
                <button type="submit" class="btn-primary-grad" style="margin-top: 20px;">Update Password</button>
            </form>
        </div>
        <?php else: ?>
            <div class="card-summary"><h3>Content for <?= ucfirst($page) ?> (Filtered by Branch)</h3><p>This section is being adapted for branch-specific HR management.</p></div>
        <?php endif; ?>

    </div>

    <div class="theme-fab"><i class="bi bi-moon-stars-fill"></i></div>

    <!-- Attendance Verification Modal -->
    <div class="modal fade" id="attendanceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background-color: #252634; color: #fff; border: 1px solid #2e2f42;">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title" id="attModalTitle">My Verification</h5>
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
        setInterval(updateClocks, 60000);

        document.addEventListener('DOMContentLoaded', () => {
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
            setupNotif('notifToggleAppreciation', 'notifDropdownAppreciation');

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

            // Theme Toggle
            const themeFab = document.querySelector('.theme-fab');
            if (themeFab) {
                themeFab.onclick = () => {
                    document.body.classList.toggle('light-mode');
                    const icon = themeFab.querySelector('i');
                    if (document.body.classList.contains('light-mode')) {
                        localStorage.setItem('theme', 'light');
                        if(icon) icon.classList.replace('bi-moon-stars-fill', 'bi-sun-fill');
                    } else {
                        localStorage.setItem('theme', 'dark');
                        if(icon) icon.classList.replace('bi-sun-fill', 'bi-moon-stars-fill');
                    }
                };
            }
        });

        // Function to populate and open Edit Modal
        function openEditModal(emp) {
            document.getElementById('edit_user_id').value = emp.id;
            document.getElementById('edit_emp_id').value = emp.emp_id || 'E-XXXX';
            document.getElementById('edit_user_name').value = emp.name;
            document.getElementById('edit_email').value = emp.email;
            document.getElementById('edit_phone').value = emp.phone_number || '';
            document.getElementById('edit_designation').value = emp.designation || '';
            document.getElementById('edit_joining_date').value = emp.joining_date || '';
            document.getElementById('edit_dept_id').value = emp.department_id || 0;
            document.getElementById('edit_salary').value = emp.base_salary || 0;
            
            const myModal = new bootstrap.Modal(document.getElementById('editEmployeeModal'));
            myModal.show();
        }
        function showSelfie(src) {
            document.getElementById('selfieImage').src = src;
            new bootstrap.Modal(document.getElementById('selfieViewerModal')).show();
        }

        // Personal Attendance JS for HR
        let hrCurrentAction = '';
        let hrStream = null;
        let hrAttModal = null;
        let statusMsg = null;
        let captureBtn = null;
        let attModalEl = null;

        document.addEventListener('DOMContentLoaded', () => {
            attModalEl = document.getElementById('attendanceModal');
            if (attModalEl) {
                hrAttModal = new bootstrap.Modal(attModalEl);
                statusMsg = document.getElementById('statusMsg');
                captureBtn = document.getElementById('captureBtn');

                captureBtn.addEventListener('click', async () => {
                    statusMsg.innerText = "Verifying location...";
                    captureBtn.disabled = true;

                    const video = document.getElementById('video');
                    const canvas = document.getElementById('canvas');
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0);
                    const imageData = canvas.toDataURL('image/png');

                    if (hrStream) hrStream.getTracks().forEach(track => track.stop());

                    if (!navigator.geolocation) {
                        alert("Geolocation not supported.");
                        return;
                    }

                    navigator.geolocation.getCurrentPosition(async (pos) => {
                        const formData = new FormData();
                        formData.append('action', hrCurrentAction);
                        formData.append('latitude', pos.coords.latitude);
                        formData.append('longitude', pos.coords.longitude);
                        formData.append('selfie', imageData);

                        try {
                            const res = await fetch('attendance_process.php', { method: 'POST', body: formData });
                            const data = await res.json();
                            if (data.success) {
                                alert(data.message);
                                location.reload();
                            } else {
                                alert("Error: " + data.message);
                                captureBtn.disabled = false;
                                statusMsg.innerText = data.message;
                            }
                        } catch (e) {
                            alert("Processing error.");
                            captureBtn.disabled = false;
                        }
                    }, (err) => {
                        alert("Location error: " + err.message);
                        captureBtn.disabled = false;
                    });
                });

                attModalEl.addEventListener('hidden.bs.modal', () => {
                    if (hrStream) hrStream.getTracks().forEach(track => track.stop());
                    captureBtn.disabled = false;
                });
            }
        });

        function handlePersonalAttendance(action) {
            hrCurrentAction = action;
            document.getElementById('attModalTitle').innerText = action === 'check_in' ? 'My Check-In Verification' : 'My Check-Out Verification';
            if (statusMsg) statusMsg.innerText = "Requesting location and camera...";
            if (hrAttModal) hrAttModal.show();
            startHRCamera();
        }

        async function startHRCamera() {
            const video = document.getElementById('video');
            try {
                hrStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
                video.srcObject = hrStream;
                statusMsg.innerText = "Camera ready. Please look at the camera.";
            } catch (err) {
                statusMsg.innerText = "Error: Camera access denied.";
            }
        }

        function handleHRBreak() {
            const btn = document.getElementById('hrBreakBtn');
            const action = btn.innerText.includes('START') ? 'start_break' : 'end_break';
            const formData = new FormData();
            formData.append('action', action);
            fetch('attendance_process.php', { method: 'POST', body: formData })
            .then(res => res.json()).then(data => {
                if(data.success) { alert(data.message); location.reload(); }
                else alert("Error: " + data.message);
            });
        }
    </script>
    <!-- Edit Employee Modal -->
    <div class="modal fade" id="editEmployeeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="background: #161720; border: 1px solid #2e2f42; color: #fff; border-radius: 16px; overflow: hidden;">
                <div class="modal-header" style="border-bottom: 1px solid #2e2f42; padding: 20px;">
                    <h5 class="modal-title" id="editModalLabel" style="font-weight: 700;">Edit Employee Profile (Branch)</h5>
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
    <script>
    const menuBtn = document.getElementById('menuToggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    menuBtn.addEventListener('click', () => {
        sidebar.classList.toggle('active');
        overlay.style.display = sidebar.classList.contains('active') ? 'block' : 'none';
    });

    overlay.addEventListener('click', () => {
        sidebar.classList.remove('active');
        overlay.style.display = 'none';
    });
</script>
</body>
</html>
