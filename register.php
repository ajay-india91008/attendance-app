<?php
session_start();
require 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $conn->real_escape_string($_POST['role']);
    
    $check = $conn->query("SELECT id FROM users WHERE email='$email'");
    if ($check->num_rows > 0) {
        $message = "<div class='alert alert-danger' style='font-size:14px; padding:10px;'>Email already registered.</div>";
    } else {
        $sql = "INSERT INTO users (name, email, password, role) VALUES ('$name', '$email', '$password', '$role')";
        if ($conn->query($sql) === TRUE) {
            $message = "<div class='alert alert-success' style='font-size:14px; padding:10px;'>Registration successful. <a href='login.php'>Login here</a></div>";
        } else {
            $message = "<div class='alert alert-danger' style='font-size:14px; padding:10px;'>Error: " . $conn->error . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - AttendPro</title>
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
            text-align: center;
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
            margin-bottom: 15px;
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
        .form-control, .form-select {
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
        .form-select option {
            background-color: #1b203c;
            color: white;
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
            margin-top: 15px;
            margin-bottom: 15px;
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn-login:hover {
            opacity: 0.9;
        }
        .btn-login:active {
            transform: scale(0.98);
        }
        .register-link {
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
            color: #8c8d9e;
        }
        .register-link a {
            color: #2979ff;
            text-decoration: none;
            font-weight: 600;
        }
        .theme-fab { position: fixed; bottom: 30px; right: 30px; width: 45px; height: 45px; background-color: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 50%; color: #ffb300; display: flex; justify-content: center; align-items: center; cursor: pointer; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
        
        /* Light Mode */
        body.light-mode { background: #f4f6f9; color: #212529; }
        body.light-mode .welcome-text { color: #212529; }
        body.light-mode .form-label { color: #6c757d; }
        body.light-mode .login-card { background: #ffffff; border-color: #e9ecef; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        body.light-mode .input-group { background: #f8f9fa; border-color: #ced4da; }
        body.light-mode .input-group:focus-within { border-color: #2979ff; }
        body.light-mode .form-control, body.light-mode .form-select { color: #212529; }
        body.light-mode .form-select option { background-color: #fff; color: #212529; }
        body.light-mode .theme-fab { background-color: #ffffff; border-color: #e9ecef; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <script>if (localStorage.getItem('theme') === 'light') { document.body.classList.add('light-mode'); }</script>

    <div class="login-card">
        <div class="welcome-text">
            Create Account
        </div>

        <?= $message ?>

        <form method="POST">
            <div class="form-label">Full Name</div>
            <div class="input-group">
                <i class="bi bi-person"></i>
                <input type="text" name="name" class="form-control" placeholder="Enter your full name" required>
            </div>

            <div class="form-label">Email Address</div>
            <div class="input-group">
                <i class="bi bi-envelope"></i>
                <input type="email" name="email" class="form-control" placeholder="Enter email address" required>
            </div>

            <div class="form-label">Password</div>
            <div class="input-group">
                <i class="bi bi-lock"></i>
                <input type="password" name="password" class="form-control" placeholder="Create a password" required>
            </div>

            <div class="form-label">Role Profile</div>
            <div class="input-group" style="padding-left:15px">
                <i class="bi bi-tag" style="padding-left:0;"></i>
                <select name="role" class="form-select" required>
                    <option value="Employee">Employee</option>
                    <option value="HR">HR</option>
                    <option value="Admin">Admin</option>
                </select>
            </div>

            <button type="submit" class="btn-login">Register Now</button>
        </form>

        <div class="register-link">
            Already have an account? <a href="login.php">Sign In</a>
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
    </script>
</body>
</html>
