<?php
$host = 'localhost';
date_default_timezone_set('Asia/Kolkata');
$user = 'root';
$pass = ''; // Default XAMPP password is empty
$dbname = 'attendance';

// Create connection
$conn = new mysqli($host, $user, $pass);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$conn->query("CREATE DATABASE IF NOT EXISTS $dbname");
$conn->select_db($dbname);

// Create branches table if not exists
$sql = "CREATE TABLE IF NOT EXISTS branches (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(10) NOT NULL,
    radius INT NOT NULL DEFAULT 100,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Insert demo branches if they don't exist
$demo_branches = [
    ['New Delhi', 'NDL', 100, 28.6139, 77.2090],
    ['Mumbai', 'MUM', 100, 19.0760, 72.8777]
];
foreach ($demo_branches as $db) {
    $bname = $db[0];
    $check = $conn->query("SELECT id FROM branches WHERE name='$bname'");
    if ($check->num_rows == 0) {
        $conn->query("INSERT INTO branches (name, code, radius, latitude, longitude) VALUES ('{$db[0]}', '{$db[1]}', {$db[2]}, {$db[3]}, {$db[4]})");
    }
}

// Ensure branch_id is in users table
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'branch_id'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN branch_id INT NULL DEFAULT NULL");
}

// Ensure base_salary is in users table
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'base_salary'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN base_salary INT NOT NULL DEFAULT 25000");
}

// Create salaries table if not exists
$sql = "CREATE TABLE IF NOT EXISTS salaries (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    month_year VARCHAR(50) NOT NULL,
    base_salary INT NOT NULL,
    present_days INT NOT NULL DEFAULT 26,
    half_days INT NOT NULL DEFAULT 0,
    absent_days INT NOT NULL DEFAULT 0,
    late_fines INT NOT NULL DEFAULT 0,
    ot_bonus INT NOT NULL DEFAULT 0,
    net_payable INT NOT NULL,
    status VARCHAR(50) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Ensure columns exist if table was already created
$res = $conn->query("SHOW COLUMNS FROM salaries LIKE 'half_days'");
if ($res && $res->num_rows == 0) {
    $conn->query("ALTER TABLE salaries ADD COLUMN half_days INT NOT NULL DEFAULT 0 AFTER present_days");
    $conn->query("ALTER TABLE salaries ADD COLUMN absent_days INT NOT NULL DEFAULT 0 AFTER half_days");
}
$res = $conn->query("SHOW COLUMNS FROM salaries LIKE 'advance_deduction'");
if ($res && $res->num_rows == 0) {
    $conn->query("ALTER TABLE salaries ADD COLUMN advance_deduction INT NOT NULL DEFAULT 0 AFTER ot_bonus");
}

// Create departments table if not exists
$sql = "CREATE TABLE IF NOT EXISTS departments (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Create users table if not exists with Role support
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'HR', 'Employee') NOT NULL DEFAULT 'Employee',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Insert demo users if they don't exist
$demo_users = [
    ['Ajeet Kumar', 'admin@example.com', 'admin123', 'Admin'],
    ['Anita Singh', 'hr@example.com', 'hr123', 'HR'],
    ['Priya Sharma', 'employee@example.com', 'emp123', 'Employee']
];
foreach ($demo_users as $du) {
    $email = $du[1];
    $check = $conn->query("SELECT id FROM users WHERE email='$email'");
    if ($check->num_rows == 0) {
        $hash = password_hash($du[2], PASSWORD_DEFAULT);
        $conn->query("INSERT INTO users (name, email, password, role) VALUES ('{$du[0]}', '{$du[1]}', '$hash', '{$du[3]}')");
    }
}

// Assign Anita Singh (HR) to New Delhi branch
$res = $conn->query("SELECT id FROM branches WHERE name='New Delhi'");
if ($res && $res->num_rows > 0) {
    $branch = $res->fetch_assoc();
    $bid = $branch['id'];
    $conn->query("UPDATE users SET branch_id = $bid WHERE email='hr@example.com'");
}

// Ensure new complex mapping fields are available
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'emp_id'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN emp_id VARCHAR(50) NULL UNIQUE AFTER id");
    $conn->query("ALTER TABLE users ADD COLUMN designation VARCHAR(100) NULL AFTER name");
    $conn->query("ALTER TABLE users ADD COLUMN department_id INT(11) NULL AFTER branch_id");
    
    // Auto-populate retroactive IDs instantly starting at E-0001
    $conn->query("UPDATE users SET emp_id = CONCAT('E-', LPAD(id, 4, '0')) WHERE emp_id IS NULL");
}

// Ensure status is in users table
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'Active'");
}

// Ensure phone_number and joining_date are in users table
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'phone_number'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN phone_number VARCHAR(20) NULL AFTER email");
}
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'joining_date'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN joining_date DATE NULL AFTER designation");
}
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_photo'");
if ($result && $result->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL AFTER status");
}

// Ensure break columns are in attendance table
$conn->query("SHOW COLUMNS FROM attendance LIKE 'break_start'");
if ($conn->affected_rows == 0) {
    $conn->query("ALTER TABLE attendance ADD COLUMN break_start DATETIME NULL DEFAULT NULL, ADD COLUMN break_end DATETIME NULL DEFAULT NULL");
}

// Create leave_requests table if not exists
$sql = "CREATE TABLE IF NOT EXISTS leave_requests (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    leave_from DATE NOT NULL,
    leave_to DATE NOT NULL,
    reason TEXT NOT NULL,
    document_path VARCHAR(255) DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);
// Create settings table if not exists
$sql = "CREATE TABLE IF NOT EXISTS settings (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Insert default settings if not exists
$default_settings = [
    'checkin_time' => '09:30',
    'checkout_time' => '18:30',
    'late_fine' => '100',
    'ot_rate' => '50'
];

foreach ($default_settings as $key => $val) {
    $check = $conn->query("SELECT id FROM settings WHERE setting_key='$key'");
    if ($check->num_rows == 0) {
        $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$val')");
    }
}

// Create holidays table if not exists
$sql = "CREATE TABLE IF NOT EXISTS holidays (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    holiday_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Create advance_requests table if not exists
$sql = "CREATE TABLE IF NOT EXISTS advance_requests (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    amount INT(11) NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    needed_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Create appreciations table
$sql = "CREATE TABLE IF NOT EXISTS appreciations (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    month VARCHAR(50) NOT NULL,
    reason TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);
?>
