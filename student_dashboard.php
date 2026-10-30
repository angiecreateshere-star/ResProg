
<?php
session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/group_leaders.php';

// Allow students only.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'student'
) {
    header('Location: login.php');
    exit;
}

$studentId = (int) $_SESSION['user_id'];

ensureResearchGroupLeaders($pdo);
ensureResearchGroupNamedMembers($pdo);

if (!isset($_SESSION['group_csrf_token'])) {
    $_SESSION['group_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['group_csrf_token'];
$groupMessage = $_SESSION['group_message'] ?? '';
unset($_SESSION['group_message']);
$groupError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken) ||
        !$groupId
    ) {
        $groupError = 'Your request could not be verified. Refresh the page and try again.';
    } else {
        $leaderCheck = $pdo->prepare("
            SELECT rgl.group_id
            FROM research_group_leaders AS rgl
            INNER JOIN group_members AS gm
                ON gm.group_id = rgl.group_id
               AND gm.student_id = rgl.student_id
            WHERE rgl.group_id = ? AND rgl.student_id = ?
            LIMIT 1
        ");
        $leaderCheck->execute([$groupId, $studentId]);

        if (!$leaderCheck->fetch()) {
            $groupError = 'Only the group leader can manage this research group.';
        } elseif ($action === 'add_member') {
            $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);

            if (!$memberId || $memberId === $studentId) {
                $groupError = 'Select an active student to add to your group.';
            } else {
                $studentCheck = $pdo->prepare("
                    SELECT id FROM users
                    WHERE id = ? AND role = 'student' AND is_active = 1
                    LIMIT 1
                ");
                $studentCheck->execute([$memberId]);

                if (!$studentCheck->fetch()) {
                    $groupError = 'The selected student account is not active.';
                } else {
                    $existingMember = $pdo->prepare("
                        SELECT student_id FROM group_members
                        WHERE group_id = ? AND student_id = ?
                        LIMIT 1
                    ");
                    $existingMember->execute([$groupId, $memberId]);

                    if ($existingMember->fetch()) {
                        $groupError = 'That student is already a member of this group.';
                    } else {
                        try {
                            $addMember = $pdo->prepare("
                                INSERT INTO group_members (group_id, student_id, assigned_by)
                                VALUES (?, ?, ?)
                            ");
                            $addMember->execute([$groupId, $memberId, $studentId]);
                            $_SESSION['group_message'] = 'Research group member added.';
                            header('Location: student_dashboard.php');
                            exit;
                        } catch (PDOException $exception) {
                            error_log('ResProg student group member add error: ' . $exception->getMessage());
                            $groupError = 'The student could not be added. Please try again.';
                        }
                    }
                }
            }
        } elseif ($action === 'add_named_member') {
            $memberName = trim($_POST['member_name'] ?? '');

            if ($memberName === '' || mb_strlen($memberName) > 150) {
                $groupError = 'Enter a member name with no more than 150 characters.';
            } else {
                $duplicateName = $pdo->prepare("
                    SELECT id FROM research_group_named_members
                    WHERE group_id = ? AND full_name = ?
                    LIMIT 1
                ");
                $duplicateName->execute([$groupId, $memberName]);

                if ($duplicateName->fetch()) {
                    $groupError = 'That name is already listed in your group.';
                } else {
                    try {
                        $addNamedMember = $pdo->prepare("
                            INSERT INTO research_group_named_members (group_id, full_name, added_by)
                            VALUES (?, ?, ?)
                        ");
                        $addNamedMember->execute([$groupId, $memberName, $studentId]);
                        $_SESSION['group_message'] = 'Group member name added.';
                        header('Location: student_dashboard.php');
                        exit;
                    } catch (PDOException $exception) {
                        error_log('ResProg named group member add error: ' . $exception->getMessage());
                        $groupError = 'The member name could not be saved. Please try again.';
                    }
                }
            }
        } elseif ($action === 'remove_named_member') {
            $namedMemberId = filter_input(INPUT_POST, 'named_member_id', FILTER_VALIDATE_INT);

            if (!$namedMemberId) {
                $groupError = 'Choose a valid group member name to remove.';
            } else {
                $removeNamedMember = $pdo->prepare("
                    DELETE FROM research_group_named_members
                    WHERE id = ? AND group_id = ?
                ");
                $removeNamedMember->execute([$namedMemberId, $groupId]);

                if ($removeNamedMember->rowCount() !== 1) {
                    $groupError = 'That name is not listed in this group.';
                } else {
                    $_SESSION['group_message'] = 'Group member name removed.';
                    header('Location: student_dashboard.php');
                    exit;
                }
            }
        } elseif ($action === 'remove_member') {
            $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);

            if (!$memberId || $memberId === $studentId) {
                $groupError = 'Group leaders cannot remove themselves. Transfer leadership first.';
            } else {
                $removeMember = $pdo->prepare("
                    DELETE FROM group_members
                    WHERE group_id = ? AND student_id = ?
                ");
                $removeMember->execute([$groupId, $memberId]);

                if ($removeMember->rowCount() !== 1) {
                    $groupError = 'That student is not a member of this group.';
                } else {
                    $_SESSION['group_message'] = 'Research group member removed.';
                    header('Location: student_dashboard.php');
                    exit;
                }
            }
        } elseif ($action === 'set_leader') {
            $newLeaderId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
            $memberCheck = $pdo->prepare("
                SELECT student_id FROM group_members
                WHERE group_id = ? AND student_id = ?
                LIMIT 1
            ");
            $memberCheck->execute([$groupId, $newLeaderId]);

            if (!$newLeaderId || !$memberCheck->fetch()) {
                $groupError = 'The new group leader must be a current group member.';
            } else {
                $setLeader = $pdo->prepare("
                    UPDATE research_group_leaders
                    SET student_id = ?
                    WHERE group_id = ?
                ");
                $setLeader->execute([$newLeaderId, $groupId]);
                $_SESSION['group_message'] = 'Group leader updated.';
                header('Location: student_dashboard.php');
                exit;
            }
        } else {
            $groupError = 'Choose a valid group management action.';
        }
    }
}

$stmt = $pdo->prepare("
    SELECT rg.id, rg.group_name, rg.research_title, rg.status,
           rg.overall_progress, rgl.student_id AS leader_id,
           leader.full_name AS leader_name,
           (SELECT COUNT(*) FROM progress_updates AS pu
            WHERE pu.group_id = rg.id AND pu.review_status = 'Pending') AS pending_reviews
    FROM research_groups AS rg
    INNER JOIN group_members AS my_membership
        ON my_membership.group_id = rg.id AND my_membership.student_id = ?
    LEFT JOIN research_group_leaders AS rgl ON rgl.group_id = rg.id
    LEFT JOIN users AS leader ON leader.id = rgl.student_id
    ORDER BY rg.group_name ASC
");
$stmt->execute([$studentId]);
$groups = $stmt->fetchAll();

$dashboardMilestones = [];
$milestoneCounts = [
    'Completed' => 0,
    'In Progress' => 0,
    'Pending' => 0
];
$milestoneStmt = $pdo->prepare("
    SELECT milestone_title, due_date, status
    FROM milestones
    WHERE group_id = ?
    ORDER BY due_date ASC, id ASC
");
foreach ($groups as $group) {
    $milestoneStmt->execute([$group['id']]);
    $groupMilestones = $milestoneStmt->fetchAll();
    foreach ($groupMilestones as $milestone) {
        if (isset($milestoneCounts[$milestone['status']])) {
            $milestoneCounts[$milestone['status']]++;
        }
        $milestone['group_name'] = $group['group_name'];
        $dashboardMilestones[] = $milestone;
    }
}
usort($dashboardMilestones, static function (array $first, array $second): int {
    $firstDue = $first['due_date'] ?: '9999-12-31';
    $secondDue = $second['due_date'] ?: '9999-12-31';
    return strcmp($firstDue, $secondDue);
});
$dashboardMilestones = array_slice($dashboardMilestones, 0, 5);

$averageProgress = $groups
    ? (int) round(array_sum(array_map(
        static fn(array $group): int => (int) $group['overall_progress'],
        $groups
    )) / count($groups))
    : 0;
$pendingReviewCount = array_sum(array_map(
    static fn(array $group): int => (int) $group['pending_reviews'],
    $groups
));
$documentStmt = $pdo->prepare("
    SELECT id, original_name, file_size, review_status, submitted_at
    FROM research_documents
    WHERE student_id = ?
    ORDER BY submitted_at DESC
    LIMIT 4
");
$documentStmt->execute([$studentId]);
$recentDocuments = $documentStmt->fetchAll();

$membersStmt = $pdo->prepare("
    SELECT u.id, u.full_name
    FROM group_members AS gm
    INNER JOIN users AS u ON u.id = gm.student_id
    WHERE gm.group_id = ?
    ORDER BY CASE WHEN u.id = ? THEN 0 ELSE 1 END, u.full_name ASC
");
$namedMembersStmt = $pdo->prepare("
    SELECT id, full_name
    FROM research_group_named_members
    WHERE group_id = ?
    ORDER BY full_name ASC
");
$candidatesStmt = $pdo->prepare("
    SELECT u.id, u.full_name
    FROM users AS u
    LEFT JOIN group_members AS gm
        ON gm.student_id = u.id AND gm.group_id = ?
    WHERE u.role = 'student'
      AND u.is_active = 1
      AND u.id <> ?
      AND gm.student_id IS NULL
    ORDER BY u.full_name ASC
");

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | ResProg</title>

    <style>
        * {
            box-sizing: border-box;
        }

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

        .container { max-width: 1080px; margin: auto; }
        .header { margin-bottom: 24px; padding: 28px; border: 1px solid #2b6690; border-radius: 18px; background: linear-gradient(115deg, #102d59, #075274); box-shadow: 0 16px 38px #00072655; }
        .header h1 { margin: 0; color: #f1f8ff; }
        .header p { margin-bottom: 0; color: #c4e7fa; }
        .dashboard-card { margin-top: 20px; padding: 24px; }
        .dashboard-card > h2 { margin-top: 0; }
        .group-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 430px), 1fr)); gap: 18px; }
        .group-card { min-width: 0; padding: 22px; }
        .group-card h3 { margin-top: 0; }
        .group-title { color: #a9c5e1; }
        .leader-name { color: #51eaf6; font-weight: 750; }
        .member-list { display: grid; gap: 8px; padding: 0; margin: 12px 0; list-style: none; }
        .member-list li { display: flex; align-items: center; gap: 10px; padding: 9px 11px; border: 1px solid #284b70; border-radius: 9px; background: #091c39; }
        .member-dot { display: inline-grid; flex: 0 0 27px; width: 27px; height: 27px; place-items: center; border-radius: 50%; background: #10507a; color: #75efff; font-size: 12px; font-weight: 800; }
        .leader-badge { margin-left: auto; padding: 4px 8px; border: 1px solid #286d86; border-radius: 99px; color: #73e7f2; font-size: 11px; }
        .progress-track { height: 9px; overflow: hidden; border-radius: 99px; background: #07162f; }
        .progress-fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #0879e9, #38e1ec); }
        .group-meta { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; color: #9db5d2; font-size: 13px; }
        .group-manager { display: grid; gap: 10px; margin-top: 18px; padding-top: 16px; border-top: 1px solid #284b70; }
        .group-manager form { display: flex; flex-wrap: wrap; align-items: end; gap: 9px; }
        .group-manager label { display: grid; flex: 1 1 165px; gap: 6px; font-size: 12px; }
        .group-manager select { width: 100%; }
        .group-manager button { align-self: end; }
        .menu { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .muted { color: #9db5d2; }
        .dashboard-notice { margin-top: 18px; }
        @media (max-width: 600px) {
            body { padding: 14px; }
            .header, .dashboard-card, .group-card { padding: 18px; }
            .group-manager form { align-items: stretch; }
            .group-manager button { width: 100%; }
        }

        @media (max-width: 600px) {
            body {
                padding: 14px;
            }

            .header,
            .card {
                padding: 18px;
            }
        }
    </style>
    <link rel="stylesheet" href="assets/resprog.css">
    <link rel="stylesheet" href="assets/student-dashboard.css">
</head>

<body class="student-dashboard">
<div class="student-app">
    <aside class="student-sidebar" aria-label="Student navigation">
        <a class="student-brand" href="student_dashboard.php"><span class="student-brand-mark">R</span> ResProg</a>
        <p class="student-nav-label">Workspace</p>
        <nav class="student-nav">
            <a class="active" href="#overview"><span class="nav-icon">D</span>Dashboard</a>
            <a href="#projects"><span class="nav-icon">P</span>Research Groups</a>
            <a href="#documents"><span class="nav-icon">F</span>Documents</a>
            <a href="#milestones"><span class="nav-icon">M</span>Milestones</a>
            <a href="#members"><span class="nav-icon">T</span>Group Members</a>
            <a href="student_progress.php"><span class="nav-icon">A</span>Progress &amp; Feedback</a>
        </nav>
        <div class="sidebar-bottom">
            <a href="logout.php"><span class="nav-icon">↗</span>Log out</a>
        </div>
    </aside>

    <main class="student-main" id="overview">
        <div class="student-topbar">
            <span class="topbar-title">Student workspace <span aria-hidden="true">/</span> Dashboard</span>
            <div class="topbar-actions"><a href="logout.php">Log out</a></div>
        </div>

        <div class="student-content">
            <?php if ($groupMessage !== ''): ?>
                <p class="notice notice-success dashboard-notice" role="status"><?= e($groupMessage) ?></p>
            <?php endif; ?>
            <?php if ($groupError !== ''): ?>
                <p class="notice notice-error dashboard-notice" role="alert"><?= e($groupError) ?></p>
            <?php endif; ?>

            <section class="dashboard-heading">
                <div>
                    <p class="eyebrow">Research workspace</p>
                    <h1>Welcome back, <?= e($_SESSION['full_name'] ?? 'Student') ?></h1>
                    <p>Here is an overview of your research, milestones, and team activity.</p>
                </div>
                <a class="button" href="student_submit_progress.php">＋ Submit progress</a>
            </section>

            <section class="student-stat-grid" aria-label="Research summary">
                <article class="student-stat"><div class="student-stat-label">Research groups</div><div class="student-stat-value"><?= count($groups) ?></div><div class="student-stat-note">Your active teams</div></article>
                <article class="student-stat"><div class="student-stat-label">Overall progress</div><div class="student-stat-value"><?= $averageProgress ?>%</div><div class="student-stat-note">Across your groups</div></article>
                <article class="student-stat"><div class="student-stat-label">Milestones completed</div><div class="student-stat-value"><?= (int) $milestoneCounts['Completed'] ?></div><div class="student-stat-note"><?= (int) $milestoneCounts['Pending'] ?> still pending</div></article>
                <article class="student-stat"><div class="student-stat-label">Progress updates for review</div><div class="student-stat-value"><?= $pendingReviewCount ?></div><div class="student-stat-note"><?= count($recentDocuments) ?> manuscript<?= count($recentDocuments) === 1 ? '' : 's' ?> submitted</div></article>
            </section>

            <section class="dashboard-panel" id="projects">
                <div class="panel-heading"><div><h2>My Research Groups</h2><p>Your research projects and their current progress</p></div><a href="student_progress.php">View progress →</a></div>
                <?php if (empty($groups)): ?>
                    <div class="dashboard-empty">You have not been assigned to a research group yet. Please contact your administrator.</div>
                <?php else: ?>
                    <div class="project-grid">
                        <?php foreach ($groups as $group): ?>
                            <?php
                            $membersStmt->execute([$group['id'], (int) $group['leader_id']]);
                            $members = $membersStmt->fetchAll();
                            $namedMembersStmt->execute([$group['id']]);
                            $namedMembers = $namedMembersStmt->fetchAll();
                            $totalMemberCount = count($members) + count($namedMembers);
                            $isLeader = (int) $group['leader_id'] === $studentId;
                            ?>
                            <article class="project-card">
                                <h3><?= e($group['group_name']) ?></h3>
                                <p class="project-title"><?= e($group['research_title']) ?></p>
                                <div class="project-status-row"><span><?= e($group['status']) ?></span><strong><?= (int) $group['overall_progress'] ?>%</strong></div>
                                <div class="progress-track" role="progressbar" aria-label="<?= e($group['group_name']) ?> research progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $group['overall_progress'] ?>"><div class="progress-fill" style="width: <?= (int) $group['overall_progress'] ?>%"></div></div>
                                <div class="project-bottom"><span>Leader: <?= e($group['leader_name'] ?? 'Not assigned') ?></span><span><?= $totalMemberCount ?> member<?= $totalMemberCount === 1 ? '' : 's' ?></span></div>
                                <details class="manage-details">
                                    <summary><?= $isLeader ? 'View team &amp; manage members' : 'View group members' ?></summary>
                                    <div class="group-card">
                                        <p><strong>Leader:</strong> <span class="leader-name"><?= e($group['leader_name'] ?? 'Not assigned') ?></span></p>
                                        <p><strong>Members</strong></p>
                                        <?php if (empty($members) && empty($namedMembers)): ?>
                                            <p class="muted">No students are assigned to this group yet.</p>
                                        <?php else: ?>
                                            <ul class="member-list">
                                                <?php foreach ($members as $member): ?>
                                                    <li><span class="member-dot" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($member['full_name'], 0, 1))) ?></span><?= e($member['full_name']) ?><?php if ((int) $member['id'] === (int) $group['leader_id']): ?><span class="leader-badge">Leader</span><?php endif; ?></li>
                                                <?php endforeach; ?>
                                                <?php foreach ($namedMembers as $namedMember): ?>
                                                    <li><span class="member-dot" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($namedMember['full_name'], 0, 1))) ?></span><?= e($namedMember['full_name']) ?><span class="leader-badge">Member</span><?php if ($isLeader): ?><form method="post" class="named-member-remove"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="remove_named_member"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>"><input type="hidden" name="named_member_id" value="<?= (int) $namedMember['id'] ?>"><button type="submit" class="named-member-remove-button" aria-label="Remove <?= e($namedMember['full_name']) ?>">×</button></form><?php endif; ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <div class="group-meta"><span>Pending progress reviews</span><strong><?= (int) $group['pending_reviews'] ?></strong></div>
                                        <?php if ($isLeader): ?>
                                            <?php
                                            $candidatesStmt->execute([$group['id'], $studentId]);
                                            $candidates = $candidatesStmt->fetchAll();
                                            ?>
                                            <div class="group-manager">
                                                <strong>Manage group members</strong>
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="add_named_member">
                                                    <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                                    <label>Add a member name<input type="text" name="member_name" maxlength="150" autocomplete="off" placeholder="Enter the member's full name" required></label>
                                                    <button type="submit">Add name</button>
                                                </form>
                                                <?php if (!empty($candidates)): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="add_member">
                                                        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                                        <label>Add a student<select name="member_id" required><option value="">Select an active student</option><?php foreach ($candidates as $candidate): ?><option value="<?= (int) $candidate['id'] ?>"><?= e($candidate['full_name']) ?></option><?php endforeach; ?></select></label>
                                                        <button type="submit">Add member</button>
                                                    </form>
                                                <?php else: ?>
                                                    <p class="muted">No other active students are available to add.</p>
                                                <?php endif; ?>
                                                <?php if (count($members) > 1): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="set_leader">
                                                        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                                        <label>Transfer leadership<select name="member_id" required><option value="">Choose a group member</option><?php foreach ($members as $member): ?><?php if ((int) $member['id'] !== $studentId): ?><option value="<?= (int) $member['id'] ?>"><?= e($member['full_name']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
                                                        <button class="button-secondary" type="submit">Transfer</button>
                                                    </form>
                                                    <form method="post">
                                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="remove_member">
                                                        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                                        <label>Remove a member<select name="member_id" required><option value="">Choose a member</option><?php foreach ($members as $member): ?><?php if ((int) $member['id'] !== $studentId): ?><option value="<?= (int) $member['id'] ?>"><?= e($member['full_name']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
                                                        <button class="button-secondary" type="submit">Remove</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="muted">Only the group leader can manage members and transfer leadership.</p>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="dashboard-columns">
                <section class="dashboard-panel" id="progress">
                    <div class="panel-heading"><div><h2>Project Progress</h2><p>Milestone status across your research groups</p></div><a href="student_progress.php">Details →</a></div>
                    <div class="progress-overview">
                        <div class="progress-donut" style="--progress: <?= $averageProgress ?>%"><div class="donut-center"><strong><?= $averageProgress ?>%</strong><span>Overall progress</span></div></div>
                        <div class="milestone-legend">
                            <div class="legend-row"><span class="legend-label"><span class="legend-dot"></span>Completed milestones</span><strong><?= (int) $milestoneCounts['Completed'] ?></strong></div>
                            <div class="legend-row"><span class="legend-label"><span class="legend-dot in-progress"></span>In progress</span><strong><?= (int) $milestoneCounts['In Progress'] ?></strong></div>
                            <div class="legend-row"><span class="legend-label"><span class="legend-dot pending"></span>Pending milestones</span><strong><?= (int) $milestoneCounts['Pending'] ?></strong></div>
                            <div class="legend-row"><span class="legend-label"><span class="legend-dot pending"></span>Progress updates for review</span><strong><?= $pendingReviewCount ?></strong></div>
                        </div>
                    </div>
                </section>

                <section class="dashboard-panel" id="milestones">
                    <div class="panel-heading"><div><h2>Milestone Timeline</h2><p>Upcoming research checkpoints</p></div><a href="student_progress.php">View all →</a></div>
                    <?php if (empty($dashboardMilestones)): ?>
                        <div class="dashboard-empty">No milestones have been added to your groups yet.</div>
                    <?php else: ?>
                        <div class="milestone-list">
                            <?php foreach ($dashboardMilestones as $milestone): ?>
                                <?php $milestoneClass = strtolower(str_replace(' ', '-', $milestone['status'])); ?>
                                <div class="milestone-row <?= e($milestoneClass) ?>">
                                    <div class="milestone-marker"><span></span></div>
                                    <div><div class="milestone-name"><?= e($milestone['milestone_title']) ?></div><div class="milestone-group"><?= e($milestone['group_name']) ?> · <?= e($milestone['status']) ?></div></div>
                                    <time class="milestone-date" datetime="<?= e($milestone['due_date']) ?>"><?= $milestone['due_date'] ? e(date('M j', strtotime($milestone['due_date']))) : 'No due date' ?></time>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="dashboard-columns">
                <section class="dashboard-panel" id="members">
                    <div class="panel-heading"><div><h2>Research Team</h2><p>Group leaders and collaborators</p></div><a href="#projects">Manage →</a></div>
                    <?php if (empty($groups)): ?>
                        <div class="dashboard-empty">Your group members will appear here after you are assigned to a research group.</div>
                    <?php else: ?>
                        <div class="team-list">
                            <?php foreach ($groups as $group): ?>
                                <?php
                                $membersStmt->execute([$group['id'], (int) $group['leader_id']]);
                                $members = $membersStmt->fetchAll();
                                $namedMembersStmt->execute([$group['id']]);
                                $namedMembers = $namedMembersStmt->fetchAll();
                                ?>
                                <?php foreach ($members as $member): ?>
                                    <div class="team-member">
                                        <span class="team-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($member['full_name'], 0, 1))) ?></span>
                                        <span class="team-name"><?= e($member['full_name']) ?><br><span class="muted"><?= e($group['group_name']) ?></span></span>
                                        <span class="team-role"><?= (int) $member['id'] === (int) $group['leader_id'] ? 'Leader' : 'Member' ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ($namedMembers as $namedMember): ?>
                                    <div class="team-member">
                                        <span class="team-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($namedMember['full_name'], 0, 1))) ?></span>
                                        <span class="team-name"><?= e($namedMember['full_name']) ?><br><span class="muted"><?= e($group['group_name']) ?></span></span>
                                        <span class="team-role">Member</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="dashboard-panel" id="documents">
                    <div class="panel-heading"><div><h2>Research Documents</h2><p>Your recent manuscript submissions</p></div><a href="research_documents.php">View all →</a></div>
                    <?php if (empty($recentDocuments)): ?>
                        <div class="dashboard-empty">No manuscripts submitted yet.<br><br><a class="button button-secondary" href="research_documents.php">Upload a PDF</a></div>
                    <?php else: ?>
                        <div class="document-list">
                            <?php foreach ($recentDocuments as $document): ?>
                                <a class="document-row" href="research_documents.php?download=<?= (int) $document['id'] ?>" target="_blank" rel="noopener">
                                    <span class="document-icon">PDF</span>
                                    <span class="document-info"><strong><?= e($document['original_name']) ?></strong><span><?= e(date('M j, Y', strtotime($document['submitted_at']))) ?> · <?= number_format((int) $document['file_size'] / 1024) ?> KB</span></span>
                                    <span class="document-status"><?= e($document['review_status']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <p><a class="button button-secondary" href="research_documents.php">Submit another PDF</a></p>
                    <?php endif; ?>
                </section>
            </div>

            <section class="dashboard-panel tools-panel">
                <div class="panel-heading"><div><h2>Research Tools</h2><p>Quick access to your existing workspace features</p></div></div>
                <div class="menu">
                    <a href="student_progress.php">Progress, requirements &amp; feedback</a>
                    <a href="student_submit_progress.php">Submit progress update</a>
                    <a href="research_documents.php">Research PDF submissions</a>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
