
<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Allow students only.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'student'
) {
    header('Location: login.php');
    exit;
}

$studentId = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Student';

// Get only the research groups this student belongs to.
$stmt = $pdo->prepare("
    SELECT rg.id, rg.group_name, rg.research_title, rg.status
    FROM research_groups AS rg
    INNER JOIN group_members AS gm
        ON gm.group_id = rg.id
    WHERE gm.student_id = ?
    ORDER BY rg.group_name ASC
");
$stmt->execute([$studentId]);
$groups = $stmt->fetchAll();

// Prepare milestone query.
$milestoneStmt = $pdo->prepare("
    SELECT milestone_title, description, due_date, status
    FROM milestones
    WHERE group_id = ?
    ORDER BY due_date ASC, id ASC
");

// Prepare research timetable query.
$timetableStmt = $pdo->prepare("
    SELECT activity_title,
           activity_date,
           start_time,
           end_time,
           notes
    FROM research_timetable
    WHERE group_id = ?
    ORDER BY activity_date ASC, start_time ASC
");
$requirementsStmt = $pdo->prepare("
    SELECT requirement_title,
           description,
           due_date
    FROM research_requirements
    WHERE group_id = ?
    ORDER BY due_date ASC, id ASC
");

// Prepare adviser feedback query.
$feedbackStmt = $pdo->prepare("
    SELECT u.full_name AS teacher_name,
           af.feedback,
           af.created_at
    FROM adviser_feedback AS af
    INNER JOIN users AS u
        ON u.id = af.teacher_id
    WHERE af.group_id = ?
    ORDER BY af.created_at DESC
");
$progressUpdatesStmt = $pdo->prepare("
    SELECT pu.progress_note,
           pu.progress_percentage,
           pu.review_status,
           pu.teacher_comment,
           pu.created_at,
           pu.reviewed_at,
           u.full_name AS teacher_name
    FROM progress_updates AS pu
    LEFT JOIN users AS u
        ON u.id = (
            SELECT ga.teacher_id
            FROM group_advisers AS ga
            WHERE ga.group_id = pu.group_id
            LIMIT 1
        )
    WHERE pu.group_id = ?
      AND pu.updated_by = ?
    ORDER BY pu.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Research Progress | ResProg</title>

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

        .milestone {
            border-left: 4px solid #2b8a85;
            background: #f8fafc;
            padding: 14px;
            margin-top: 12px;
            border-radius: 6px;
            overflow-wrap: anywhere;
        }

        .status {
            font-weight: bold;
            color: #176b65;
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
        <p>My Research Progress</p>
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

        <p>View your research group's milestones, timetable, and adviser feedback.</p>

        <?php if (empty($groups)): ?>
            <section class="group-card">
                <p class="empty">
                    You have not been assigned to a research group yet.
                    Please contact your Research Teacher or Administrator.
                </p>
            </section>
        <?php else: ?>
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

                    <p>
                        <strong>Group Status:</strong>
                        <?= htmlspecialchars(
                            $group['status'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>

                    <h4>My Progress Updates</h4>

                    <?php
                    $progressUpdatesStmt->execute([$group['id'], $studentId]);
                    $progressUpdates = $progressUpdatesStmt->fetchAll();
                    ?>

                    <?php if (empty($progressUpdates)): ?>
                        <p class="empty">You have not submitted any progress updates for this group yet.</p>
                    <?php else: ?>
                        <?php foreach ($progressUpdates as $update): ?>
                            <div class="milestone">
                                <p>
                                    <strong>Progress:</strong>
                                    <?= htmlspecialchars((string) $update['progress_percentage'], ENT_QUOTES, 'UTF-8') ?>%
                                </p>

                                <p>
                                    <strong>Description:</strong><br>
                                    <?= nl2br(htmlspecialchars($update['progress_note'], ENT_QUOTES, 'UTF-8')) ?>
                                </p>

                                <p>
                                    <strong>Review Status:</strong>
                                    <span class="status">
                                        <?= htmlspecialchars($update['review_status'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </p>

                                <p>
                                    <strong>Teacher's Comment:</strong><br>
                                    <?= nl2br(htmlspecialchars($update['teacher_comment'] ?? 'No comment yet.', ENT_QUOTES, 'UTF-8')) ?>
                                </p>

                                <p>
                                    <small>
                                        <strong>Date Submitted:</strong>
                                        <?= htmlspecialchars($update['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                    </small>
                                </p>

                                <?php if (!empty($update['reviewed_at'])): ?>
                                    <p>
                                        <small>
                                            <strong>Date Reviewed:</strong>
                                            <?= htmlspecialchars($update['reviewed_at'], ENT_QUOTES, 'UTF-8') ?>
                                        </small>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <h4 id="timetable">Research Timetable</h4>
                    <!-- Research Requirements -->
<h2 id="requirements">Research Requirements</h2>

<?php
$requirementsStmt->execute([$group['id']]);
$requirements = $requirementsStmt->fetchAll();
?>

<?php if (empty($requirements)): ?>
    <p class="muted">No research requirements have been added yet.</p>
<?php else: ?>
    <?php foreach ($requirements as $requirement): ?>
        <div class="milestone">
            <h3><?= htmlspecialchars($requirement['requirement_title']) ?></h3>

            <?php if (!empty($requirement['description'])): ?>
                <p>
                    <strong>Description:</strong><br>
                    <?= nl2br(htmlspecialchars($requirement['description'])) ?>
                </p>
            <?php endif; ?>

            <p>
                <strong>Due Date:</strong>
                <?= htmlspecialchars($requirement['due_date'] ?: 'Not set') ?>
            </p>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

                    <?php
                    $timetableStmt->execute([$group['id']]);
                    $timetableList = $timetableStmt->fetchAll();
                    ?>

                    <?php if (empty($timetableList)): ?>
                        <p class="empty">
                            No timetable activities have been scheduled yet.
                        </p>
                    <?php else: ?>
                        <?php foreach ($timetableList as $activity): ?>
                            <div class="milestone">
                                <strong>
                                    <?= htmlspecialchars(
                                        $activity['activity_title'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>

                                <p>
                                    <strong>Date:</strong>
                                    <?= htmlspecialchars(
                                        $activity['activity_date'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <p>
                                    <strong>Time:</strong>
                                    <?= !empty($activity['start_time'])
                                        ? htmlspecialchars(
                                            substr($activity['start_time'], 0, 5),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )
                                        : 'Not specified' ?>
                                    –
                                    <?= !empty($activity['end_time'])
                                        ? htmlspecialchars(
                                            substr($activity['end_time'], 0, 5),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )
                                        : 'Not specified' ?>
                                </p>

                                <?php if (!empty($activity['notes'])): ?>
                                    <p>
                                        <strong>Notes:</strong><br>
                                        <?= nl2br(htmlspecialchars(
                                            $activity['notes'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <h4 id="feedback">Adviser Feedback</h4>

                    <?php
                    $feedbackStmt->execute([$group['id']]);
                    $feedbackList = $feedbackStmt->fetchAll();
                    ?>

                    <?php if (empty($feedbackList)): ?>
                        <p class="empty">
                            No adviser feedback has been provided yet.
                        </p>
                    <?php else: ?>
                        <?php foreach ($feedbackList as $feedbackItem): ?>
                            <div class="milestone">
                                <strong>
                                    <?= htmlspecialchars(
                                        $feedbackItem['teacher_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>

                                <p>
                                    <?= nl2br(htmlspecialchars(
                                        $feedbackItem['feedback'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    )) ?>
                                </p>

                                <p>
                                    <small>
                                        <strong>Date:</strong>
                                        <?= htmlspecialchars(
                                            $feedbackItem['created_at'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </small>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <h4>Research Milestones</h4>

                    <?php
                    $milestoneStmt->execute([$group['id']]);
                    $milestones = $milestoneStmt->fetchAll();
                    ?>

                    <?php if (empty($milestones)): ?>
                        <p class="empty">
                            No research milestones have been added yet.
                        </p>
                    <?php else: ?>
                        <?php foreach ($milestones as $milestone): ?>
                            <div class="milestone">
                                <strong>
                                    <?= htmlspecialchars(
                                        $milestone['milestone_title'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>

                                <p>
                                    <?= nl2br(htmlspecialchars(
                                        $milestone['description'] ?? '',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    )) ?>
                                </p>

                                <p>
                                    <strong>Due Date:</strong>
                                    <?= htmlspecialchars(
                                        $milestone['due_date'] ?: 'Not set',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <p>
                                    <strong>Status:</strong>
                                    <span class="status">
                                        <?= htmlspecialchars(
                                            $milestone['status'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
