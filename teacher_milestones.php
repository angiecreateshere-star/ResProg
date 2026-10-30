
<?php
session_start();

require_once __DIR__ . '/config/db.php';
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_milestone'])) {
    $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
    $milestoneTitle = trim($_POST['milestone_title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $dueDate = trim($_POST['due_date'] ?? '');

    if (!$groupId || $milestoneTitle === '') {
        $errorMessage = 'Please select a research group and enter a milestone title.';
    } else {
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
        $checkGroup->execute([$groupId, (int) $_SESSION['user_id']]);
        $assignedGroup = $checkGroup->fetch();

        if (!$assignedGroup) {
            $errorMessage = 'You are not assigned to that research group.';
        } else {
            $insert = $pdo->prepare("
                INSERT INTO milestones
                    (group_id, milestone_title, description, due_date, status, created_by)
                VALUES (?, ?, ?, ?, 'Pending', ?)
            ");

            $insert->execute([
                $groupId,
                $milestoneTitle,
                $description !== '' ? $description : null,
                $dueDate !== '' ? $dueDate : null,
                (int) $_SESSION['user_id']
            ]);

            $successMessage = 'Research milestone added successfully!';
        }
    }
}

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

// Get milestones belonging to each assigned group.
$milestoneStmt = $pdo->prepare("
    SELECT id, milestone_title, description, due_date, status
    FROM milestones
    WHERE group_id = ?
    ORDER BY due_date ASC, id ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Milestones | ResProg</title>

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
        <p>Research Milestones</p>
    </header>

    <main>
        <div class="links">
            <a href="dashboard.php">← Back to Dashboard</a>
            <a href="logout.php">Log Out</a>
        </div>

        <h2>Welcome, <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>!</h2>
        <?php if ($errorMessage !== ''): ?>
    <p style="color: #b91c1c;">
        <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<?php if ($successMessage !== ''): ?>
    <p style="color: #166534;">
        <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<section class="group-card">
    <h3>Add Research Milestone</h3>

    <form method="POST" action="teacher_milestones.php">
        <label for="group_id">Research Group</label><br>
        <select id="group_id" name="group_id" required
                style="width: 100%; padding: 10px; margin: 8px 0 16px;">
            <option value="">Select your assigned group</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>">
                    <?= htmlspecialchars($group['group_name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="milestone_title">Milestone Title</label><br>
        <input type="text" id="milestone_title" name="milestone_title"
               required maxlength="255"
               placeholder="e.g., Chapter 1 Completion"
               style="width: 100%; padding: 10px; margin: 8px 0 16px;">

        <label for="description">Description</label><br>
        <textarea id="description" name="description" rows="3"
                  placeholder="Enter milestone details"
                  style="width: 100%; padding: 10px; margin: 8px 0 16px;"></textarea>

        <label for="due_date">Due Date</label><br>
        <input type="date" id="due_date" name="due_date"
               style="width: 100%; padding: 10px; margin: 8px 0 16px;">

        <button type="submit" name="add_milestone"
                style="background: #16345c; color: white; border: none;
                       padding: 12px 18px; border-radius: 6px; cursor: pointer;">
            Add Milestone
        </button>
    </form>
</section>
        <p>View the research milestones for your assigned groups.</p>

        <?php if (empty($groups)): ?>
            <p class="empty">No research groups are assigned to you yet.</p>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <section class="group-card">
                    <h3><?= htmlspecialchars($group['group_name'], ENT_QUOTES, 'UTF-8') ?></h3>

                    <p>
                        <strong>Research Title:</strong>
                        <?= htmlspecialchars($group['research_title'], ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <?php
                    $milestoneStmt->execute([$group['id']]);
                    $milestones = $milestoneStmt->fetchAll();
                    ?>

                    <h4>Research Milestones</h4>

                    <?php if (empty($milestones)): ?>
                        <p class="empty">No milestones have been added to this group yet.</p>
                    <?php else: ?>
                        <?php foreach ($milestones as $milestone): ?>
                            <div class="milestone">
                                <strong>
                                    <?= htmlspecialchars($milestone['milestone_title'], ENT_QUOTES, 'UTF-8') ?>
                                </strong>

                                <p>
                                    <?= nl2br(htmlspecialchars($milestone['description'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                                </p>

                                <p>
                                    <strong>Due Date:</strong>
                                    <?= htmlspecialchars($milestone['due_date'] ?? 'Not set', ENT_QUOTES, 'UTF-8') ?>
                                </p>

                                <p>
                                    <strong>Status:</strong>
                                    <span class="status">
                                        <?= htmlspecialchars($milestone['status'], ENT_QUOTES, 'UTF-8') ?>
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
