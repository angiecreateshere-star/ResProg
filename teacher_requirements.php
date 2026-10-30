
<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Allow Research Teachers only.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'teacher'
) {
    header('Location: login.php');
    exit;
}

$teacherId = (int) $_SESSION['user_id'];
$message = '';
$error = '';

// Add a requirement.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
    $title = trim($_POST['requirement_title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $dueDate = trim($_POST['due_date'] ?? '');

    if (!$groupId || $title === '') {
        $error = 'Please select a research group and enter a requirement title.';
    } else {
        // Confirm that this group belongs to the logged-in teacher.
        $checkGroup = $pdo->prepare("
            SELECT rg.id
            FROM research_groups AS rg
            INNER JOIN group_advisers AS ga
                ON ga.group_id = rg.id
            WHERE rg.id = ?
              AND ga.teacher_id = ?
        ");
        $checkGroup->execute([$groupId, $teacherId]);

        if (!$checkGroup->fetch()) {
            $error = 'You can only add requirements to your assigned groups.';
        } else {
            $validDate = true;

            if ($dueDate !== '') {
                $dateObject = DateTime::createFromFormat('!Y-m-d', $dueDate);
                $validDate = $dateObject &&
                    $dateObject->format('Y-m-d') === $dueDate;
            }

            if (!$validDate) {
                $error = 'Please enter a valid due date.';
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO research_requirements
                        (group_id, requirement_title, description,
                         due_date, created_by)
                    VALUES (?, ?, ?, ?, ?)
                ");

                $insert->execute([
                    $groupId,
                    $title,
                    $description !== '' ? $description : null,
                    $dueDate !== '' ? $dueDate : null,
                    $teacherId
                ]);

                $message = 'Research requirement added successfully.';
            }
        }
    }
}

// Get groups assigned to this teacher.
$groupsStmt = $pdo->prepare("
    SELECT rg.id, rg.group_name, rg.research_title
    FROM research_groups AS rg
    INNER JOIN group_advisers AS ga
        ON ga.group_id = rg.id
    WHERE ga.teacher_id = ?
    ORDER BY rg.group_name ASC
");
$groupsStmt->execute([$teacherId]);
$groups = $groupsStmt->fetchAll();

// Get requirements for groups assigned to this teacher.
$requirementsStmt = $pdo->prepare("
    SELECT rr.id,
           rr.group_id,
           rr.requirement_title,
           rr.description,
           rr.due_date,
           rr.created_at,
           rg.group_name
    FROM research_requirements AS rr
    INNER JOIN research_groups AS rg
        ON rg.id = rr.group_id
    INNER JOIN group_advisers AS ga
        ON ga.group_id = rg.id
    WHERE ga.teacher_id = ?
    ORDER BY rr.due_date ASC, rr.id DESC
");
$requirementsStmt->execute([$teacherId]);
$requirements = $requirementsStmt->fetchAll();

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Requirements | ResProg</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 25px;
            background: #f4f7fb;
            color: #243247;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 1000px;
            margin: auto;
        }

        h1 { color: #16345c; }
        h2 { color: #24476b; }

        .card {
            background: white;
            padding: 24px;
            margin: 20px 0;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(20, 45, 75, 0.05);
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: bold;
        }

        input, select, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccd6e2;
            border-radius: 7px;
            font: inherit;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        button, .back {
            display: inline-block;
            margin-top: 18px;
            padding: 11px 18px;
            border: 0;
            border-radius: 7px;
            background: #245b91;
            color: white;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover, .back:hover {
            background: #173f69;
        }

        .success {
            padding: 12px;
            background: #e6f6ed;
            color: #17633a;
            border-radius: 7px;
        }

        .error {
            padding: 12px;
            background: #fdeaea;
            color: #a12626;
            border-radius: 7px;
        }

        .requirement {
            border-left: 4px solid #2b8a85;
            background: #f8fafc;
            padding: 16px;
            margin-top: 12px;
            border-radius: 6px;
            overflow-wrap: anywhere;
        }

        .muted { color: #64748b; }

        @media (max-width: 600px) {
            body { padding: 14px; }
            .card { padding: 17px; }
        }
    </style>
    <link rel="stylesheet" href="assets/resprog.css">
</head>
<body>
<div class="container">

    <a class="back" href="teacher_dashboard.php">
        &larr; Back to Teacher Dashboard
    </a>

    <h1>Research Requirements</h1>
    <p class="muted">
        Add and view requirements for your assigned research groups.
    </p>

    <div class="card">
        <h2>Add Requirement</h2>

        <?php if ($message !== ''): ?>
            <p class="success"><?= e($message) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="error"><?= e($error) ?></p>
        <?php endif; ?>

        <?php if (empty($groups)): ?>
            <p>You do not have an assigned research group yet.</p>
        <?php else: ?>
            <form method="POST">
                <label for="group_id">Research Group *</label>
                <select name="group_id" id="group_id" required>
                    <option value="">Select a research group</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>">
                            <?= e($group['group_name']) ?>
                            — <?= e($group['research_title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="requirement_title">Requirement Title *</label>
                <input
                    type="text"
                    name="requirement_title"
                    id="requirement_title"
                    maxlength="255"
                    required
                >

                <label for="description">Description (optional)</label>
                <textarea
                    name="description"
                    id="description"
                    placeholder="Explain what the students need to complete..."
                ></textarea>

                <label for="due_date">Due Date (optional)</label>
                <input type="date" name="due_date" id="due_date">

                <button type="submit">Save Requirement</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Saved Requirements</h2>

        <?php if (empty($requirements)): ?>
            <p class="muted">No research requirements have been added yet.</p>
        <?php else: ?>
            <?php foreach ($requirements as $item): ?>
                <div class="requirement">
                    <h3><?= e($item['requirement_title']) ?></h3>

                    <p>
                        <strong>Group:</strong>
                        <?= e($item['group_name']) ?>
                    </p>

                    <?php if (!empty($item['description'])): ?>
                        <p>
                            <strong>Description:</strong><br>
                            <?= nl2br(e($item['description'])) ?>
                        </p>
                    <?php endif; ?>

                    <p>
                        <strong>Due Date:</strong>
                        <?= e($item['due_date'] ?: 'Not set') ?>
                    </p>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
