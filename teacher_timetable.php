
<?php
session_start();

require_once __DIR__ . '/config/db.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'teacher'
) {
    header('Location: login.php');
    exit;
}

$teacherId = (int) $_SESSION['user_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
    $activityTitle = trim($_POST['activity_title'] ?? '');
    $activityDate = trim($_POST['activity_date'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $endTime = trim($_POST['end_time'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (
        !$groupId ||
        $activityTitle === '' ||
        $activityDate === ''
    ) {
        $error = 'Please complete the required fields.';
    } elseif (
        ($startTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $startTime)) ||
        ($endTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $endTime))
    ) {
        $error = 'Please enter a valid start and end time.';
    } elseif (
        $startTime !== '' &&
        $endTime !== '' &&
        $endTime <= $startTime
    ) {
        $error = 'The end time must be later than the start time.';
    } else {
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
            $error = 'You can only add a timetable to your assigned group.';
        } else {
            $insert = $pdo->prepare("
                INSERT INTO research_timetable
                    (group_id, activity_title, activity_date,
                     start_time, end_time, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $insert->execute([
                $groupId,
                $activityTitle,
                $activityDate,
                $startTime !== '' ? $startTime : null,
                $endTime !== '' ? $endTime : null,
                $notes !== '' ? $notes : null,
                $teacherId
            ]);

            $message = 'Research timetable added successfully.';
        }
    }
}

$groupsStmt = $pdo->prepare("
    SELECT rg.id, rg.group_name, rg.research_title
    FROM research_groups AS rg
    INNER JOIN group_advisers AS ga
        ON ga.group_id = rg.id
    WHERE ga.teacher_id = ?
    ORDER BY rg.group_name
");
$groupsStmt->execute([$teacherId]);
$groups = $groupsStmt->fetchAll();

$timetableStmt = $pdo->prepare("
    SELECT rt.*, rg.group_name
    FROM research_timetable AS rt
    INNER JOIN research_groups AS rg
        ON rg.id = rt.group_id
    INNER JOIN group_advisers AS ga
        ON ga.group_id = rg.id
    WHERE ga.teacher_id = ?
    ORDER BY rt.activity_date ASC, rt.start_time ASC
");
$timetableStmt->execute([$teacherId]);
$timetable = $timetableStmt->fetchAll();

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Timetable | ResProg</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 25px;
            background: #f4f7fb;
            color: #263449;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        h1 { color: #17365d; }
        h2 { color: #24476b; margin-top: 0; }

        .card {
            background: white;
            padding: 24px;
            margin-bottom: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(20, 45, 75, 0.07);
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

        textarea { min-height: 90px; resize: vertical; }

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

        .item {
            border: 1px solid #e0e7ef;
            padding: 16px;
            border-radius: 9px;
            margin-top: 12px;
        }

        .muted { color: #63748a; }
        .back { margin-bottom: 20px; }

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

    <h1>Research Timetable</h1>
    <p class="muted">
        Create and view schedules for your assigned research groups.
    </p>

    <div class="card">
        <h2>Add Timetable</h2>

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

                <label for="activity_title">Activity Title *</label>
                <input
                    type="text"
                    name="activity_title"
                    id="activity_title"
                    maxlength="200"
                    required
                >

                <label for="activity_date">Activity Date *</label>
                <input
                    type="date"
                    name="activity_date"
                    id="activity_date"
                    required
                >

                <label for="start_time">Start Time (optional)</label>
                <input type="time" name="start_time" id="start_time">

                <label for="end_time">End Time (optional)</label>
                <input type="time" name="end_time" id="end_time">

                <label for="notes">Notes (optional)</label>
                <textarea
                    name="notes"
                    id="notes"
                    placeholder="Additional schedule details..."
                ></textarea>

                <button type="submit">Save Timetable</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Scheduled Activities</h2>

        <?php if (empty($timetable)): ?>
            <p class="muted">No timetable activities have been added yet.</p>
        <?php else: ?>
            <?php foreach ($timetable as $item): ?>
                <div class="item">
                    <h3><?= e($item['activity_title']) ?></h3>

                    <p>
                        <strong>Group:</strong>
                        <?= e($item['group_name']) ?>
                    </p>

                    <p>
                        <strong>Date:</strong>
                        <?= e($item['activity_date']) ?>
                    </p>

                    <p>
                        <strong>Time:</strong>
                        <?= $item['start_time']
                            ? e(substr($item['start_time'], 0, 5))
                            : 'Not specified' ?>
                        –
                        <?= $item['end_time']
                            ? e(substr($item['end_time'], 0, 5))
                            : 'Not specified' ?>
                    </p>

                    <?php if (!empty($item['notes'])): ?>
                        <p>
                            <strong>Notes:</strong><br>
                            <?= nl2br(e($item['notes'])) ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
