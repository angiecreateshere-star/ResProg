<?php
session_start();

require_once __DIR__ . '/config/db.php';

$roleDestinations = [
    'student' => 'student_dashboard.php',
    'teacher' => 'teacher_dashboard.php',
    'admin' => 'dashboard.php'
];

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if (isset($roleDestinations[$_SESSION['role']])) {
        header('Location: ' . $roleDestinations[$_SESSION['role']]);
        exit;
    }
}

$error = '';
$selectedRole = $_POST['role'] ?? 'student';
$availableRoles = ['student', 'teacher', 'admin'];
if (!in_array($selectedRole, $availableRoles, true)) {
    $selectedRole = 'student';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '' || !in_array($_POST['role'] ?? '', $availableRoles, true)) {
        $error = 'Please choose your role and enter your email and password.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, email, password, role
                 FROM users
                 WHERE email = :email AND is_active = 1
                 LIMIT 1'
            );

            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if (
                $user &&
                $user['role'] === $selectedRole &&
                password_verify($password, $user['password'])
            ) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                header('Location: ' . $roleDestinations[$user['role']]);
                exit;
            }

            $error = 'Invalid email or password.';
        } catch (PDOException $e) {
            error_log('ResProg login error: ' . $e->getMessage());
            $error = 'Unable to process login. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | ResProg</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1e293b;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 36px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(15, 23, 42, 0.08);
        }

        .brand {
            margin: 0 0 8px;
            color: #173b67;
            font-size: 30px;
            text-align: center;
        }

        .subtitle {
            margin: 0 0 28px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            text-align: center;
        }

        label {
            display: block;
            margin: 16px 0 8px;
            font-size: 14px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
        }

        button {
            width: 100%;
            margin-top: 24px;
            padding: 13px;
            border: none;
            border-radius: 8px;
            background: #173b67;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover { background: #245486; }

        .error {
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 8px;
            background: #fef2f2;
            color: #b91c1c;
            font-size: 14px;
        }

        .footer {
            margin: 24px 0 0;
            color: #64748b;
            font-size: 12px;
            text-align: center;
        }

        .role-select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: white;
            color: #1e293b;
            font-size: 15px;
        }

        .landing-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            margin-top: 16px;
            padding: 10px 16px;
            border: 1px solid #31547b;
            border-radius: 10px;
            background: #0c2344;
            color: #c9e6ff;
            font-weight: 700;
            text-decoration: none;
            transition: border-color .18s ease, background .18s ease, transform .18s ease;
        }

        .landing-link:hover {
            transform: translateY(-1px);
            border-color: #38d8ed;
            background: #12345c;
            color: white;
        }

    </style>
    <link rel="stylesheet" href="assets/resprog.css">
</head>
<body>
    <main class="login-card">
        <h1 class="brand">ResProg</h1>

        <p class="subtitle">
            Research Progress Tracking and Document Management System
        </p>

        <p class="muted" style="text-align:center">Sign in to your ResProg workspace</p>

        <?php if ($error !== ''): ?>
            <p class="error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <label for="role">I am signing in as</label>
            <select class="role-select" id="role" name="role" required>
                <option value="student" <?= $selectedRole === 'student' ? 'selected' : '' ?>>Student</option>
                <option value="teacher" <?= $selectedRole === 'teacher' ? 'selected' : '' ?>>Research Teacher</option>
                <option value="admin" <?= $selectedRole === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>

            <label for="email">Email Address</label>
            <input
                type="email"
                id="email"
                name="email"
                placeholder="Enter your email"
                autocomplete="username"
                required
            >

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter your password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Log In</button>
        </form>

        <p class="footer">
            ResProg · Research Progress Made Organized
        </p>
        <a class="landing-link" href="index.php">← Back to Landing Page</a>
    </main>
</body>
</html>