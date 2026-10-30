
<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Students only.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'student'
) {
    header('Location: login.php');
    exit;
}

$studentId = (int) $_SESSION['user_id'];
$message = '';
$error = '';

// Get groups assigned to this student.
$groupsStmt = $pdo->prepare("
    SELECT rg.id, rg.group_name, rg.research_title
    FROM research_groups AS rg
    INNER JOIN group_members AS gm
        ON gm.group_id = rg.id
    WHERE gm.student_id = ?
    ORDER BY rg.group_name ASC
");
$groupsStmt->execute([$studentId]);
$groups = $groupsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
    $note = trim($_POST['progress_note'] ?? '');
    $percentageInput = $_POST['progress_percentage'] ?? '';

    if (
        !$groupId ||
        $note === '' ||
        $percentageInput === '' ||
        !is_numeric($percentageInput)
    ) {
        $error = 'Please complete all required fields.';
    } else {
        $percentage = filter_var(
            $percentageInput,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 100]]
        );

        if ($percentage === false) {
            $error = 'Progress percentage must be a whole number from 0 to 100.';
        } else {
            // Confirm that the student belongs to the selected group.
            $checkGroup = $pdo->prepare("
                SELECT rg.id
                FROM research_groups AS rg
                INNER JOIN group_members AS gm
                    ON gm.group_id = rg.id
                WHERE rg.id = ?
                  AND gm.student_id = ?
            ");
            $checkGroup->execute([$groupId, $studentId]);

            if (!$checkGroup->fetch()) {
                $error = 'You can only submit updates for your assigned groups.';
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO progress_updates
                        (group_id, updated_by, progress_note,
                         progress_percentage, review_status)
                    VALUES (?, ?, ?, ?, 'Pending')
                ");

                $insert->execute([
                    $groupId,
                    $studentId,
                    $note,
                    $percentage
                ]);

                $message = 'Progress update submitted! It is now waiting for teacher validation.';
            }
        }
    }
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Progress Update | ResProg</title>

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
            max-width: 800px;
            margin: auto;
        }

        h1, h2 { color: #16345c; }

        .card {
            background: white;
            padding: 24px;
            margin-top: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: bold;
        }

        select, textarea, input {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccd6e2;
            border-radius: 7px;
            font: inherit;
        }

        textarea {
            min-height: 120px;
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

        button:hover, .back:hover { background: #173f69; }

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

    <a class="back" href="student_dashboard.php">
        &larr; Back to Student Dashboard
    </a>

    <h1>Submit Research Progress</h1>
    <p class="muted">
        Describe your group's progress. Your Research Teacher will review it.
    </p>

    <div class="card">
        <h2>Progress Update Form</h2>

        <?php if ($message !== ''): ?>
            <p class="success"><?= e($message) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="error"><?= e($error) ?></p>
        <?php endif; ?>

        <?php if (empty($groups)): ?>
            <p>You have not been assigned to a research group yet.</p>
        <?php else: ?>
            <form method="POST">
                <label for="group_id">Research Group *</label>
                <select name="group_id" id="group_id" required>
                    <option value="">Select your group</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>">
                            <?= e($group['group_name']) ?>
                            — <?= e($group['research_title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="progress_note">Progress Description *</label>
                <textarea
                    name="progress_note"
                    id="progress_note"
                    placeholder="Describe what your group has completed so far..."
                    required
                ></textarea>

                <label for="progress_percentage">
                    Estimated Progress (%) *
                </label>
                <input
                    type="number"
                    name="progress_percentage"
                    id="progress_percentage"
                    min="0"
                    max="100"
                    step="1"
                    placeholder="Example: 25"
                    required
                >

                <button type="submit">Submit Progress Update</button>
            </form>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
