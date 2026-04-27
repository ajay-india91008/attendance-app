<?php
session_start();
require 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email_or_id = $conn->real_escape_string($_POST['email']);
    $password = $_POST['password'];
    
    $result = $conn->query("SELECT * FROM users WHERE email='$email_or_id' OR emp_id='$email_or_id'");
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            if ($user['status'] === 'Inactive') {
                $error = "Your account is inactive. Please contact admin.";
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['profile_photo'] = $user['profile_photo'];
                $_SESSION['branch_id'] = $user['branch_id'];
                
                if ($user['role'] === 'Admin') {
                    header("Location: admin_dashboard.php");
                } elseif ($user['role'] === 'HR') {
                    header("Location: hr_dashboard.php");
                } else {
                    header("Location: index.php");
                }
                exit;
            }
        } else {
            $error = "Invalid password.";
        }
    } else {
        $error = "No user found with that email.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AttendPro</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #101530, #1b203c);
            color: #ffffff;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .app-logo {
            width: 75px;
            height: 75px;
            background: linear-gradient(135deg, #4d66ff, #293db3);
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            margin: 0 auto 15px auto;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            color: white;
        }
        .app-title {
            font-weight: 700;
            font-size: 32px;
            margin-bottom: 5px;
            text-align: center;
        }
        .app-subtitle {
            color: #a3a4b0;
            font-size: 15px;
            margin-bottom: 35px;
            text-align: center;
        }
        .login-card {
            background: rgba(43, 48, 77, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
            padding: 35px 30px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            backdrop-filter: blur(15px);
        }
        .welcome-text {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-label {
            font-size: 14px;
            font-weight: 600;
            color: #d1d2e0;
            margin-bottom: 8px;
        }
        .input-group {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            transition: border 0.3s;
        }
        .input-group:focus-within {
            border-color: #4d66ff;
        }
        .input-group i {
            color: #8c8d9e;
            padding: 12px 15px;
            font-size: 18px;
            margin-top: 2px;
        }
        .form-control {
            background: transparent;
            border: none;
            color: #ffffff;
            padding: 14px 15px 14px 0;
            outline: none;
            box-shadow: none !important;
        }
        .form-control::placeholder {
            color: #6c6d7e;
        }
        .btn-login {
            background: linear-gradient(90deg, #2979ff, #651fff);
            border: none;
            border-radius: 14px;
            padding: 16px;
            width: 100%;
            font-weight: 600;
            font-size: 17px;
            color: white;
            margin-top: 10px;
            margin-bottom: 25px;
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn-login:hover {
            opacity: 0.9;
        }
        .btn-login:active {
            transform: scale(0.98);
        }
        .demo-divider {
            text-align: center;
            position: relative;
            margin: 25px 0;
        }
        .demo-divider::before {
            content: "";
            position: absolute;
            left: 0;
            top: 50%;
            width: 25%;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
        }
        .demo-divider::after {
            content: "";
            position: absolute;
            right: 0;
            top: 50%;
            width: 25%;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
        }
        .demo-divider span {
            color: #8c8d9e;
            font-size: 13px;
            padding: 0;
        }
        .demo-cards {
            display: flex;
            gap: 12px;
            justify-content: space-between;
        }
        .demo-card {
            flex: 1;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 15px 5px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .demo-card:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        .demo-card.admin { background: rgba(101, 31, 255, 0.1); border-color: rgba(101, 31, 255, 0.3); color: #b388ff;}
        .demo-card.hr { background: rgba(255, 145, 0, 0.1); border-color: rgba(255, 145, 0, 0.3); color: #ffd180; }
        .demo-card.employee { background: rgba(0, 230, 118, 0.1);  border-color: rgba(0, 230, 118, 0.3); color: #69f0ae;}
        
        .demo-icon {
            font-size: 24px;
            margin-bottom: 5px;
            display: inline-block;
        }
        .demo-title {
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 2px;
            color: #ffffff;
        }
        .demo-name {
            font-size: 11px;
            opacity: 0.8;
        }
        .register-link {
            text-align: center;
            margin-top: 25px;
            font-size: 14px;
            color: #8c8d9e;
        }
        .register-link a {
            color: #2979ff;
            text-decoration: none;
            font-weight: 600;
        }
        /* Theme FAB */
        .theme-fab { position: fixed; bottom: 30px; right: 30px; width: 45px; height: 45px; background-color: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 50%; color: #ffb300; display: flex; justify-content: center; align-items: center; cursor: pointer; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        
        /* Light Mode */
        body.light-mode { background: #f4f6f9; color: #212529; }
        body.light-mode .app-title, body.light-mode .welcome-text { color: #212529; }
        body.light-mode .app-subtitle, body.light-mode .form-label, body.light-mode .demo-name { color: #6c757d; }
        body.light-mode .login-card { background: #ffffff; border-color: #e9ecef; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        body.light-mode .input-group { background: #f8f9fa; border-color: #ced4da; }
        body.light-mode .input-group:focus-within { border-color: #2979ff; }
        body.light-mode .form-control, body.light-mode .form-select { color: #212529; }
        body.light-mode .form-select option { background-color: #fff; color: #212529; }
        body.light-mode .demo-divider span { background: #ffffff; color: #6c757d;}
        body.light-mode .demo-divider::before, body.light-mode .demo-divider::after { background: #e9ecef; }
        body.light-mode .demo-card { background: #ffffff; border-color: #ced4da; }
        body.light-mode .demo-title { color: #212529; }
        body.light-mode .theme-fab { background-color: #ffffff; border-color: #e9ecef; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <script>if (localStorage.getItem('theme') === 'light') { document.body.classList.add('light-mode'); }</script>

    <div class="app-logo">
        <i class="bi bi-buildings"></i>
    </div>
    <div class="app-title">AttendPro</div>
    <div class="app-subtitle">Employee Attendance Management</div>

    <div class="login-card">
        <div class="welcome-text">
            Welcome Back! <span style="font-size:28px;">👋</span>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger" style="font-size:14px; padding:10px;"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <div class="form-label">Employee ID / Email</div>
            <div class="input-group">
                <i class="bi bi-person"></i>
                <input type="text" name="email" id="emailInput" class="form-control" placeholder="e.g. E-0001 or name@example.com" required>
            </div>

            <div class="form-label">Password</div>
            <div class="input-group">
                <i class="bi bi-lock"></i>
                <input type="password" name="password" id="passwordInput" class="form-control" placeholder="Enter your password" required>
                <i class="bi bi-eye" style="cursor:pointer;" onclick="togglePassword()"></i>
            </div>

            <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="demo-divider">
            <span>Quick Demo Login</span>
        </div>

        <div class="demo-cards">
            <div class="demo-card admin" onclick="fillDemo('admin@example.com', 'admin123')">
                <div class="demo-icon">👑</div>
                <div class="demo-title">Admin</div>
                <div class="demo-name">Ajeet Kumar</div>
            </div>
            <div class="demo-card hr" onclick="fillDemo('hr@example.com', 'hr123')">
                <div class="demo-icon">💼</div>
                <div class="demo-title">HR</div>
                <div class="demo-name">Anita Singh</div>
            </div>
            <div class="demo-card employee" onclick="fillDemo('employee@example.com', 'emp123')">
                <div class="demo-icon">👤</div>
                <div class="demo-title">Employee</div>
                <div class="demo-name">Priya Sharma</div>
            </div>
        </div>

        <div class="register-link">
            Don't have an account? <a href="register.php">Register Here</a>
        </div>
    </div>

    <!-- Theme Toggle FAB -->
    <button class="theme-fab">
        <i class="bi bi-moon-stars-fill"></i>
    </button>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const themeFab = document.querySelector('.theme-fab');
            const icon = themeFab.querySelector('i');
            if (localStorage.getItem('theme') === 'light') {
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

        function togglePassword() {
            var x = document.getElementById("passwordInput");
            if (x.type === "password") {
                x.type = "text";
            } else {
                x.type = "password";
            }
        }

        function fillDemo(email, pass) {
            document.getElementById("emailInput").value = email;
            document.getElementById("passwordInput").value = pass;
            // Un-comment below to auto submit
            // document.getElementById("loginForm").submit();
        }
    </script>
</body>
</html>
