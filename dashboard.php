<?php
session_start();

// Block visitors who are not logged in.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin', 'teacher', 'student'], true)
) {
    header('Location: login.php');
    exit;
}

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'];

$roleNames = [
    'admin' => 'Administrator',
    'teacher' => 'Research Teacher / Adviser',
    'student' => 'Student'
];

$displayRole = $roleNames[$role] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard | ResProg</title>

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
            gap: 16px;
            padding: 20px 6%;
            background: #173b67;
            color: white;
        }

        header h1 {
            margin: 0;
            font-size: 25px;
        }

        header span {
            font-size: 13px;
            color: #dbeafe;
        }

        main {
            width: 88%;
            max-width: 1100px;
            margin: 35px auto;
        }

        .welcome {
            margin-bottom: 25px;
        }

        .welcome h2 {
            margin-bottom: 8px;
            color: #173b67;
        }

        .welcome p {
            color: #64748b;
            line-height: 1.6;
        }

        .card {
            padding: 25px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 5px 18px rgba(15, 23, 42, 0.04);
        }

        .role {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 20px;
            background: #eaf2ff;
            color: #173b67;
            font-size: 13px;
            font-weight: bold;
        }

        .note {
            margin-top: 20px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.7;
        }

        .logout-button {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 18px;
            background: #173b67;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
        }

        .logout-button:hover {
            background: #245486;
        }

        .manage-button {
            display: inline-block;
            margin: 20px 10px 0 0;
            padding: 10px 18px;
            background: #eaf2ff;
            color: #173b67;
            border: 1px solid #cbdcf5;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
        }

        .manage-button:hover {
            background: #dbeafe;
        }

        @media (max-width: 600px) {
            header {
                padding: 18px 5%;
            }

            main {
                width: 90%;
                margin-top: 25px;
            }

            .card {
                padding: 20px;
            }
        }
    </style>
    <link rel="stylesheet" href="assets/resprog.css">
</head>
<body>

<header>
    <h1>ResProg</h1>
    <span>Research Progress System</span>
</header>

<main>
    <section class="welcome">
        <h2>
            Welcome, <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>!
        </h2>

        <p>You have successfully logged in to ResProg.</p>
    </section>

    <section class="card">
        <h3>Your Account</h3>
        <a href="index.php" class="manage-button">Landing Page</a>

        <p>
            Role:
            <span class="role">
                <?= htmlspecialchars($displayRole, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </p>

        <?php if ($role === 'admin'): ?>
            <p>You are logged in as the ResProg Administrator.</p>

            <p class="note">
                Manage user accounts, research groups, student assignments,
                and adviser assignments from the Administrator dashboard.
            </p>

            <a href="users.php" class="manage-button">
                Manage User Accounts
            </a>
            <a href="groups.php" class="manage-button">
                Manage Research Groups
            </a>
            <a href="assign_groups.php" class="manage-button">
                Assign Students and Teachers
            </a>

        <?php elseif ($role === 'teacher'): ?>
            <p>You are logged in as a Research Teacher / Adviser.</p>

            <p class="note">
                The Teacher dashboard will show assigned research groups,
                progress milestones, and adviser feedback.
            </p>
            <a href="teacher_dashboard.php" class="manage-button">
                Open Teacher Dashboard
            </a>
            <a href="research_documents.php" class="manage-button">
                Review Student Manuscripts
            </a>

        <?php else: ?>
            <p>You are logged in as a Student.</p>
            <a href="student_dashboard.php" class="manage-button">
    Open Student Dashboard
</a>

            <p class="note">
                The Student dashboard will show your research group,
                milestones, progress, timetable, requirements, and feedback.
            </p>
        <?php endif; ?>

        <a href="logout.php" class="logout-button">Log Out</a>
    </section>
</main>

</body>
</html>