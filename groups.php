<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Admin-only access.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';

// Create a research group.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupName = trim($_POST['group_name'] ?? '');
    $researchTitle = trim($_POST['research_title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($groupName === '' || $researchTitle === '') {
        $error = 'Please enter the group name and research title.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO research_groups
                    (group_name, research_title, description, status, created_by)
                 VALUES
                    (:group_name, :research_title, :description, :status, :created_by)'
            );

            $stmt->execute([
                'group_name' => $groupName,
                'research_title' => $researchTitle,
                'description' => $description,
                'status' => 'Planning',
                'created_by' => $_SESSION['user_id']
            ]);

            // Prevent the browser from submitting the form again on refresh.
            header('Location: groups.php?created=1');
            exit;
        } catch (PDOException $e) {
            error_log('ResProg group creation error: ' . $e->getMessage());
            $error = 'Unable to create the research group. Please try again.';
        }
    }
}

if (isset($_GET['created']) && $_GET['created'] === '1') {
    $success = 'Research group created successfully.';
}

// Retrieve all research groups.
try {
    $stmt = $pdo->query(
        'SELECT
            rg.id,
            rg.group_name,
            rg.research_title,
            rg.description,
            rg.status,
            rg.created_at,
            u.full_name AS creator_name
         FROM research_groups rg
         LEFT JOIN users u ON rg.created_by = u.id
         ORDER BY rg.created_at DESC'
    );

    $groups = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('ResProg group listing error: ' . $e->getMessage());
    $groups = [];
    $error = 'Unable to load research groups.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Groups | ResProg</title>

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

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #173b67;
            text-decoration: none;
            font-weight: bold;
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

        label {
            display: block;
            margin: 16px 0 7px;
            font-size: 14px;
            font-weight: bold;
        }

        input, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: Arial, sans-serif;
            font-size: 14px;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        button {
            margin-top: 18px;
            padding: 12px 18px;
            border: none;
            border-radius: 8px;
            background: #173b67;
            color: white;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #245486;
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
            vertical-align: top;
        }

        th {
            background: #f8fafc;
            color: #334155;
        }

        td {
            overflow-wrap: anywhere;
        }

        .group-status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #eaf2ff;
            color: #173b67;
            font-size: 12px;
            font-weight: bold;
        }

        .empty {
            color: #64748b;
            text-align: center;
        }

        @media (max-width: 600px) {
            .panel {
                padding: 18px;
            }
        }
    </style>
    <link rel="stylesheet" href="assets/resprog.css">
</head>
<body>

<header>
    <h1>ResProg | Research Groups</h1>
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
        <h2>Create Research Group</h2>
        <p>Enter the research group's information below.</p>

        <form action="groups.php" method="POST">
            <label for="group_name">Research Group Name</label>
            <input
                type="text"
                id="group_name"
                name="group_name"
                maxlength="150"
                placeholder="e.g. Group 1"
                required
            >

            <label for="research_title">Research Title</label>
            <input
                type="text"
                id="research_title"
                name="research_title"
                maxlength="255"
                placeholder="Enter the approved research title"
                required
            >

            <label for="description">Description (Optional)</label>
            <textarea
                id="description"
                name="description"
                placeholder="Brief description of the research project"
            ></textarea>

            <button type="submit">Create Research Group</button>
        </form>
    </section>

    <section class="panel">
        <h2>Research Groups List</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Group Name</th>
                        <th>Research Title</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Created By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($groups) === 0): ?>
                        <tr>
                            <td colspan="5" class="empty">
                                No research groups have been created yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($groups as $group): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($group['group_name'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($group['research_title'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($group['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <span class="group-status">
                                        <?= htmlspecialchars($group['status'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <?= htmlspecialchars($group['creator_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
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