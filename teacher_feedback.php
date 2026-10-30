
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
$fullName = $_SESSION['full_name'] ?? 'Research Teacher';

$errorMessage = '';
$successMessage = '';

// Get only groups assigned to this teacher.
$stmt = $pdo->prepare("
    SELECT rg.id, rg.group_name, rg.research_title
    FROM research_groups AS rg
    INNER JOIN group_advisers AS ga
        ON ga.group_id = rg.id
    WHERE ga.teacher_id = ?
    ORDER BY rg.group_name ASC
");
$stmt->execute([$teacherId]);
$groups = $stmt->fetchAll();

// Process submitted feedback.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_feedback'])
) {
    $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
    $feedback = trim($_POST['feedback'] ?? '');

    if (!$groupId || $feedback === '') {
        $errorMessage = 'Please select a research group and enter feedback.';
    } else {
        // Verify that this group is assigned to this teacher.
        $checkGroup = $pdo->prepare("
            SELECT id
            FROM research_groups
            WHERE id = ?
              AND id IN (
                  SELECT group_id
                  FROM group_advisers
                  WHERE teacher_id = ?
              )
        ");
        $checkGroup->execute([$groupId, $teacherId]);

        if (!$checkGroup->fetch()) {
            $errorMessage = 'You are not assigned to that research group.';
        } else {
            $insert = $pdo->prepare("
                INSERT INTO adviser_feedback
                    (group_id, teacher_id, feedback)
                VALUES (?, ?, ?)
            ");
            $insert->execute([$groupId, $teacherId, $feedback]);

            $successMessage = 'Adviser feedback saved successfully!';
        }
    }
}

// Get feedback for the assigned groups.
$feedbackStmt = $pdo->prepare("
    SELECT feedback, created_at
    FROM adviser_feedback
    WHERE group_id = ? AND teacher_id = ?
    ORDER BY created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adviser Feedback | ResProg</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            color: #243247;
        }

        header {
            background: #16345c;
            color: white;
            padding: 22px 7%;
        }

        header h1 {
            margin: 0 0 6px;
        }

        header p {
            margin: 0;
            color: #dce7f5;
        }

        main {
            width: 86%;
            max-width: 1000px;
            margin: 30px auto;
        }

        .links {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
        }

        a {
            color: #174a7e;
        }

        .group-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-top: 12px;
            font-weight: bold;
        }

        select,
        textarea {
            width: 100%;
            padding: 10px;
            margin-top: 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font: inherit;
        }

        button {
            margin-top: 16px;
            padding: 12px 18px;
            background: #16345c;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }

        .feedback-item {
            background: #f8fafc;
            border-left: 4px solid #2b8a85;
            padding: 14px;
            margin-top: 12px;
            border-radius: 6px;
        }

        .message {
            padding: 12px;
            border-radius: 6px;
            background: #e8f5e9;
            color: #166534;
        }

        .error {
            padding: 12px;
            border-radius: 6px;
            background: #fef2f2;
            color: #b91c1c;
        }

        .empty {
            color: #64748b;
        }

        @media (max-width: 600px) {
            main {
                width: 92%;
            }
        }
    </style>
    <link rel="stylesheet" href="assets/resprog.css">
</head>
<body>
    <header>
        <h1>ResProg</h1>
        <p>Research Adviser Feedback</p>
    </header>

    <main>
        <div class="links">
            <a href="dashboard.php">← Back to Dashboard</a>
            <a href="logout.php">Log Out</a>
        </div>

        <h2>
            Welcome,
            <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>!
        </h2>

        <p>Provide feedback to help your assigned research groups improve.</p>

        <?php if ($errorMessage !== ''): ?>
            <p class="error">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <?php if ($successMessage !== ''): ?>
            <p class="message">
                <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <section class="group-card">
            <h3>Give Adviser Feedback</h3>

            <?php if (empty($groups)): ?>
                <p class="empty">No research groups are assigned to you yet.</p>
            <?php else: ?>
                <form method="POST" action="teacher_feedback.php">
                    <label for="group_id">Research Group</label>
                    <select id="group_id" name="group_id" required>
                        <option value="">Select a research group</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>">
                                <?= htmlspecialchars(
                                    $group['group_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="feedback">Feedback</label>
                    <textarea
                        id="feedback"
                        name="feedback"
                        rows="5"
                        maxlength="5000"
                        required
                        placeholder="Write your feedback and recommendations here..."
                    ></textarea>

                    <button type="submit" name="save_feedback">
                        Save Feedback
                    </button>
                </form>
            <?php endif; ?>
        </section>

        <h2>Previous Feedback</h2>

        <?php foreach ($groups as $group): ?>
            <section class="group-card">
                <h3>
                    <?= htmlspecialchars(
                        $group['group_name'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </h3>

                <p>
                    <strong>Research Title:</strong>
                    <?= htmlspecialchars(
                        $group['research_title'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>

                <?php
                $feedbackStmt->execute([$group['id'], $teacherId]);
                $feedbackList = $feedbackStmt->fetchAll();
                ?>

                <?php if (empty($feedbackList)): ?>
                    <p class="empty">No feedback has been recorded yet.</p>
                <?php else: ?>
                    <?php foreach ($feedbackList as $item): ?>
                        <div class="feedback-item">
                            <p>
                                <?= nl2br(htmlspecialchars(
                                    $item['feedback'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                )) ?>
                            </p>
                            <small>
                                Saved:
                                <?= htmlspecialchars(
                                    $item['created_at'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </main>
</body>
</html>
