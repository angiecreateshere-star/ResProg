<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Allow administrators only.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';

// Create a new teacher or student account.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';

        if (
            $fullName === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($password) < 8 ||
            !in_array($role, ['teacher', 'student'], true)
        ) {
            $error = 'Please provide valid information. Password must be at least 8 characters.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users
                    (full_name, email, password, role, is_active)
                    VALUES (:full_name, :email, :password, :role, 1)'
                );

                $stmt->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role
                ]);

                $success = 'User account created successfully.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'This email address is already registered.';
                } else {
                    error_log('ResProg user creation error: ' . $e->getMessage());
                    $error = 'Unable to create the account. Please try again.';
                }
            }
        }
    }

    // Activate or deactivate an account.
    if ($action === 'toggle_status') {
        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if (!$userId) {
            $error = 'Invalid user account.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                     WHERE id = :id AND role IN ('teacher', 'student')"
                );

                $stmt->execute(['id' => $userId]);

                if ($stmt->rowCount() > 0) {
                    $success = 'Account status updated.';
                } else {
                    $error = 'Account not found or its status was not changed.';
                }
            } catch (PDOException $e) {
                error_log('ResProg account status error: ' . $e->getMessage());
                $error = 'Unable to update account status.';
            }
        }
    }
}

// Get all accounts except the administrator accounts.
$stmt = $pdo->query(
    "SELECT id, full_name, email, role, is_active, created_at
     FROM users
     WHERE role IN ('teacher', 'student')
     ORDER BY created_at DESC"
);

$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | ResProg</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #1e293b;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            padding: 20px 6%;
            background: #173b67;
            color: white;
        }

        header h1 {
            margin: 0;
            font-size: 24px;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        main {
            width: 90%;
            max-width: 1100px;
            margin: 30px auto;
        }

        .panel {
            margin-bottom: 25px;
            padding: 25px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 5px 18px rgba(15, 23, 42, 0.04);
        }

        h2 {
            margin-top: 0;
            color: #173b67;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }

        button, .button {
            display: inline-block;
            padding: 11px 16px;
            border: none;
            border-radius: 8px;
            background: #173b67;
            color: white;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }

        button:hover, .button:hover {
            background: #245486;
        }

        .form-submit {
            margin-top: 18px;
        }

        .message {
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 8px;
            font-size: 14px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th, td {
            padding: 13px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #f8fafc;
            color: #334155;
        }

        .status {
            font-weight: bold;
        }

        .active {
            color: #15803d;
        }

        .inactive {
            color: #b91c1c;
        }

        .status-button {
            padding: 8px 11px;
            font-size: 12px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #173b67;
            text-decoration: none;
            font-weight: bold;
        }

        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 18px;
            }
        }
    </style>
    <link rel="stylesheet" href="assets/resprog.css">
</head>
<body>

<header>
    <h1>ResProg | User Management</h1>
    <a href="dashboard.php">Back to Dashboard</a>
</header>

<main>
    <a class="back-link" href="dashboard.php">← Return to Dashboard</a>

    <?php if ($success !== ''): ?>
        <div class="message success">
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="message error" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Create User Account</h2>
        <p>Create an account for a Research Teacher / Adviser or Student.</p>

        <form action="users.php" method="POST">
            <input type="hidden" name="action" value="create_user">

            <div class="form-grid">
                <div>
                    <label for="full_name">Full Name</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        maxlength="150"
                        required
                    >
                </div>

                <div>
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="190"
                        required
                    >
                </div>

                <div>
                    <label for="password">Temporary Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div>
                    <label for="role">Account Role</label>
                    <select id="role" name="role" required>
                        <option value="">Select a role</option>
                        <option value="teacher">Research Teacher / Adviser</option>
                        <option value="student">Student</option>
                    </select>
                </div>
            </div>

            <button class="form-submit" type="submit">
                Create Account
            </button>
        </form>
    </section>

    <section class="panel">
        <h2>Registered Users</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($users) === 0): ?>
                        <tr>
                            <td colspan="5">No teacher or student accounts yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= $user['role'] === 'teacher'
                                        ? 'Research Teacher / Adviser'
                                        : 'Student' ?>
                                </td>
                                <td>
                                    <?php if ((int) $user['is_active'] === 1): ?>
                                        <span class="status active">Active</span>
                                    <?php else: ?>
                                        <span class="status inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form action="users.php" method="POST"
                                          onsubmit="return confirm('Change this account status?');">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id"
                                               value="<?= (int) $user['id'] ?>">

                                        <button class="status-button" type="submit">
                                            <?= (int) $user['is_active'] === 1
                                                ? 'Deactivate'
                                                : 'Activate' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

</body>
</html>