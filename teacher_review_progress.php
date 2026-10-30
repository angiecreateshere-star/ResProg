<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Research Teachers only.
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

// Process teacher review.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updateId = filter_input(INPUT_POST, 'update_id', FILTER_VALIDATE_INT);
    $decision = $_POST['decision'] ?? '';
    $comment = trim($_POST['teacher_comment'] ?? '');

    if (
        !$updateId ||
        !in_array($decision, ['Approved', 'Rejected'], true)
    ) {
        $error = 'Please select a valid progress update and decision.';
    } else {
        // Confirm this update belongs to a group assigned to this teacher.
        $check = $pdo->prepare("
            SELECT pu.id, pu.group_id
            FROM progress_updates AS pu
            INNER JOIN group_advisers AS ga
                ON ga.group_id = pu.group_id
            WHERE pu.id = ?
              AND ga.teacher_id = ?
              AND pu.review_status = 'Pending'
        ");
        $check->execute([$updateId, $teacherId]);
        $pendingUpdate = $check->fetch();

        if (!$pendingUpdate) {
            $error = 'This update is not pending or is not from your assigned groups.';
        } else {
            $groupId = (int) $pendingUpdate['group_id'];

            try {
                // Save the teacher's decision and recalculate group progress together.
                $pdo->beginTransaction();

                $review = $pdo->prepare("
                    UPDATE progress_updates
                    SET review_status = ?,
                        teacher_comment = ?,
                        reviewed_at = NOW()
                    WHERE id = ?
                      AND review_status = 'Pending'
                ");

                $review->execute([
                    $decision,
                    $comment !== '' ? $comment : null,
                    $updateId
                ]);

                if ($review->rowCount() !== 1) {
                    throw new RuntimeException('This update has already been reviewed.');
                }

                // Overall progress is the average of approved updates only.
                $averageStmt = $pdo->prepare("
                    SELECT COALESCE(ROUND(AVG(progress_percentage)), 0)
                    FROM progress_updates
                    WHERE group_id = ?
                      AND review_status = 'Approved'
                ");
                $averageStmt->execute([$groupId]);
                $overallProgress = (int) $averageStmt->fetchColumn();

                $saveProgress = $pdo->prepare("
                    UPDATE research_groups
                    SET overall_progress = ?
                    WHERE id = ?
                ");
                $saveProgress->execute([$overallProgress, $groupId]);

                $pdo->commit();

                $message = 'Progress update ' . strtolower($decision)
                    . ' successfully. Group overall progress is now '
                    . $overallProgress . '%.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Progress review error: ' . $exception->getMessage());
                $error = 'The review could not be saved. Please try again.';
            }
        }
    }
}

// Get updates from this teacher's assigned groups.
$stmt = $pdo->prepare("
    SELECT pu.id,
           pu.group_id,
           pu.progress_note,
           pu.progress_percentage,
           pu.review_status,
           pu.created_at,
           pu.teacher_comment,
           rg.group_name,
           rg.research_title,
           rg.overall_progress,
           u.full_name AS student_name
    FROM progress_updates AS pu
    INNER JOIN research_groups AS rg
        ON rg.id = pu.group_id
    INNER JOIN group_advisers AS ga
        ON ga.group_id = rg.id
    INNER JOIN users AS u
        ON u.id = pu.updated_by
    WHERE ga.teacher_id = ?
    ORDER BY
        CASE WHEN pu.review_status = 'Pending' THEN 0 ELSE 1 END,
        pu.created_at DESC
");
$stmt->execute([$teacherId]);
$updates = $stmt->fetchAll();

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Progress | ResProg</title>

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

        h1, h2 { color: #16345c; }

        .card {
            background: white;
            padding: 24px;
            margin-top: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }

        .update {
            padding: 18px;
            margin-top: 14px;
            background: #f8fafc;
            border-left: 4px solid #2b8a85;
            border-radius: 7px;
            overflow-wrap: anywhere;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: bold;
        }

        textarea, select {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccd6e2;
            border-radius: 7px;
            font: inherit;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        button, .back {
            display: inline-block;
            margin-top: 14px;
            padding: 11px 16px;
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

        .status {
            display: inline-block;
            padding: 5px 9px;
            background: #fff4d6;
            color: #805b00;
            border-radius: 5px;
            font-size: 13px;
            font-weight: bold;
        }

        .progress-summary {
            padding: 12px;
            margin: 12px 0;
            background: #e8f1fc;
            border-radius: 7px;
            color: #174a7e;
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

    <h1>Review Student Progress</h1>
    <p class="muted">
        Review updates submitted by students in your assigned research groups.
    </p>

    <div class="card">
        <?php if ($message !== ''): ?>
            <p class="success"><?= e($message) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="error"><?= e($error) ?></p>
        <?php endif; ?>

        <?php if (empty($updates)): ?>
            <p class="muted">No progress updates have been submitted yet.</p>
        <?php else: ?>
            <?php foreach ($updates as $update): ?>
                <div class="update">
                    <h2><?= e($update['group_name']) ?></h2>

                    <div class="progress-summary">
                        <strong>Current Group Overall Progress:</strong>
                        <?= (int) $update['overall_progress'] ?>%
                        <br>
                        <small>
                            Calculated as the average of approved progress updates for this group.
                        </small>
                    </div>

                    <p>
                        <strong>Research Title:</strong>
                        <?= e($update['research_title']) ?>
                    </p>

                    <p>
                        <strong>Submitted by:</strong>
                        <?= e($update['student_name']) ?>
                    </p>

                    <p>
                        <strong>Progress in This Update:</strong>
                        <?= (int) $update['progress_percentage'] ?>%
                    </p>

                    <p>
                        <strong>Progress Description:</strong><br>
                        <?= nl2br(e($update['progress_note'])) ?>
                    </p>

                    <p>
                        <strong>Submitted:</strong>
                        <?= e($update['created_at']) ?>
                    </p>

                    <p>
                        <strong>Status:</strong>
                        <span class="status">
                            <?= e($update['review_status']) ?>
                        </span>
                    </p>

                    <?php if ($update['review_status'] === 'Pending'): ?>
                        <form method="POST">
                            <input
                                type="hidden"
                                name="update_id"
                                value="<?= (int) $update['id'] ?>"
                            >

                            <label for="comment_<?= (int) $update['id'] ?>">
                                Teacher Comment (optional)
                            </label>

                            <textarea
                                name="teacher_comment"
                                id="comment_<?= (int) $update['id'] ?>"
                                placeholder="Provide feedback about this progress update..."
                            ></textarea>

                            <label for="decision_<?= (int) $update['id'] ?>">
                                Review Decision *
                            </label>

                            <select
                                name="decision"
                                id="decision_<?= (int) $update['id'] ?>"
                                required
                            >
                                <option value="">Select decision</option>
                                <option value="Approved">Approve</option>
                                <option value="Rejected">Reject</option>
                            </select>

                            <button type="submit">Submit Review</button>
                        </form>
                    <?php elseif (!empty($update['teacher_comment'])): ?>
                        <p>
                            <strong>Teacher Comment:</strong><br>
                            <?= nl2br(e($update['teacher_comment'])) ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
