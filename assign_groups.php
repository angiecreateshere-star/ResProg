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

// Process group assignments.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
    $studentIds = $_POST['student_ids'] ?? [];
    $teacherId = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);

    if (!is_array($studentIds)) {
        $studentIds = [];
    }

    $studentIds = array_values(array_unique(array_filter(
        array_map('intval', $studentIds),
        fn($id) => $id > 0
    )));

    if (!$groupId || (!$studentIds && !$teacherId)) {
        $error = 'Select a research group and at least one student or adviser.';
    } else {
        try {
            $pdo->beginTransaction();

            // Confirm that the selected research group exists.
            $groupStmt = $pdo->prepare(
                'SELECT id FROM research_groups WHERE id = :id'
            );
            $groupStmt->execute(['id' => $groupId]);

            if (!$groupStmt->fetch()) {
                throw new RuntimeException('The selected research group was not found.');
            }

            // Validate and assign the selected students.
            if ($studentIds) {
                $placeholders = implode(',', array_fill(0, count($studentIds), '?'));

                $studentStmt = $pdo->prepare(
                    "SELECT id FROM users
                     WHERE role = 'student'
                       AND is_active = 1
                       AND id IN ($placeholders)"
                );
                $studentStmt->execute($studentIds);
                $validStudentIds = array_map(
                    'intval',
                    $studentStmt->fetchAll(PDO::FETCH_COLUMN)
                );

                if (count($validStudentIds) !== count($studentIds)) {
                    throw new RuntimeException(
                        'One or more selected student accounts are invalid or inactive.'
                    );
                }

                $memberStmt = $pdo->prepare(
                    'INSERT INTO group_members (group_id, student_id, assigned_by)
                     VALUES (:group_id, :student_id, :assigned_by)
                     ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by)'
                );

                foreach ($validStudentIds as $studentId) {
                    $memberStmt->execute([
                        'group_id' => $groupId,
                        'student_id' => $studentId,
                        'assigned_by' => $_SESSION['user_id']
                    ]);
                }
            }

            // Validate and assign one active teacher/adviser.
            if ($teacherId) {
                $teacherStmt = $pdo->prepare(
                    "SELECT id FROM users
                     WHERE id = :id
                       AND role = 'teacher'
                       AND is_active = 1"
                );
                $teacherStmt->execute(['id' => $teacherId]);

                if (!$teacherStmt->fetch()) {
                    throw new RuntimeException(
                        'The selected teacher account is invalid or inactive.'
                    );
                }

                $adviserStmt = $pdo->prepare(
                    'INSERT INTO group_advisers (group_id, teacher_id, assigned_by)
                     VALUES (:group_id, :teacher_id, :assigned_by)
                     ON DUPLICATE KEY UPDATE
                        teacher_id = VALUES(teacher_id),
                        assigned_by = VALUES(assigned_by)'
                );

                $adviserStmt->execute([
                    'group_id' => $groupId,
                    'teacher_id' => $teacherId,
                    'assigned_by' => $_SESSION['user_id']
                ]);
            }

            $pdo->commit();

            header('Location: assign_groups.php?assigned=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                error_log('ResProg group assignment error: ' . $e->getMessage());
                $error = 'Unable to save assignments. Please try again.';
            }
        }
    }
}

if (isset($_GET['assigned']) && $_GET['assigned'] === '1') {
    $success = 'Group assignments saved successfully.';
}

// Load research groups, active students, and active teachers.
$groups = $pdo->query(
    'SELECT id, group_name, research_title
     FROM research_groups
     ORDER BY group_name'
)->fetchAll();

$students = $pdo->query(
    "SELECT id, full_name, email
     FROM users
     WHERE role = 'student' AND is_active = 1
     ORDER BY full_name"
)->fetchAll();

$teachers = $pdo->query(
    "SELECT id, full_name, email
     FROM users
     WHERE role = 'teacher' AND is_active = 1
     ORDER BY full_name"
)->fetchAll();

// Show existing assignments.
$assignments = $pdo->query(
    'SELECT
        rg.id AS group_id,
        rg.group_name,
        rg.research_title,
        gm.student_id,
        su.full_name AS student_name,
        ga.teacher_id,
        tu.full_name AS teacher_name
     FROM research_groups rg
     LEFT JOIN group_members gm ON rg.id = gm.group_id
     LEFT JOIN users su ON gm.student_id = su.id
     LEFT JOIN group_advisers ga ON rg.id = ga.group_id
     LEFT JOIN users tu ON ga.teacher_id = tu.id
     ORDER BY rg.group_name, su.full_name'
)->fetchAll();

// Group assignment rows for display.
$assignmentList = [];

foreach ($assignments as $assignment) {
    $id = (int) $assignment['group_id'];

    if (!isset($assignmentList[$id])) {
        $assignmentList[$id] = [
            'group_name' => $assignment['group_name'],
            'research_title' => $assignment['research_title'],
            'students' => [],
            'teacher' => $assignment['teacher_name']
        ];
    }

    if (
        $assignment['student_name'] !== null &&
        !in_array($assignment['student_name'], $assignmentList[$id]['students'], true)
    ) {
        $assignmentList[$id]['students'][] = $assignment['student_name'];
    }

    if ($assignment['teacher_name'] !== null) {
        $assignmentList[$id]['teacher'] = $assignment['teacher_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Research Groups | ResProg</title>

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
            flex-wrap: wrap;
            gap: 15px;
            padding: 20px 6%;
            background: #173b67;
            color: white;
        }

        header h1 {
            margin: 0;
            font-size: 23px;
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
            padding: 25px;
            margin-bottom: 25px;
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
            margin: 16px 0 8px;
            font-size: 14px;
            font-weight: bold;
        }

        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: white;
            font-size: 14px;
        }

        .hint {
            margin-top: 7px;
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }

        button {
            margin-top: 20px;
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
        }

        td {
            overflow-wrap: anywhere;
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
    <h1>ResProg | Group Assignments</h1>
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
        <h2>Assign Students and Adviser</h2>
        <p>Select a research group, its student members, and its adviser.</p>

        <?php if (!$groups): ?>
            <p>No research groups are available yet. Create a group first.</p>
        <?php elseif (!$students && !$teachers): ?>
            <p>Create active Student and Teacher accounts in User Management first.</p>
        <?php else: ?>
            <form action="assign_groups.php" method="POST">
                <label for="group_id">Research Group</label>
                <select id="group_id" name="group_id" required>
                    <option value="">Select a research group</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>">
                            <?= htmlspecialchars($group['group_name'], ENT_QUOTES, 'UTF-8') ?>
                            — <?= htmlspecialchars($group['research_title'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="student_ids">Student Members</label>
                <select id="student_ids" name="student_ids[]" multiple size="5">
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int) $student['id'] ?>">
                            <?= htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8') ?>
                            (<?= htmlspecialchars($student['email'], ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">
                    To select multiple students, hold Ctrl while clicking on Windows.
                    On a phone, tap the students you want to select.
                </p>

                <label for="teacher_id">Research Teacher / Adviser</label>
                <select id="teacher_id" name="teacher_id">
                    <option value="">No change / do not assign</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= (int) $teacher['id'] ?>">
                            <?= htmlspecialchars($teacher['full_name'], ENT_QUOTES, 'UTF-8') ?>
                            (<?= htmlspecialchars($teacher['email'], ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">
                    Choosing a teacher assigns or replaces the group's adviser.
                    Leaving it blank does not change the current adviser.
                    Selected students are added without removing existing members.
                </p>

                <button type="submit">Save Assignments</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Current Group Assignments</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Research Group</th>
                        <th>Research Title</th>
                        <th>Student Members</th>
                        <th>Research Adviser</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$assignmentList): ?>
                        <tr>
                            <td colspan="4">No research groups or assignments found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($assignmentList as $item): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($item['group_name'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($item['research_title'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?php if ($item['students']): ?>
                                        <?= htmlspecialchars(implode(', ', $item['students']), ENT_QUOTES, 'UTF-8') ?>
                                    <?php else: ?>
                                        Not assigned
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($item['teacher'] ?? 'Not assigned', ENT_QUOTES, 'UTF-8') ?>
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