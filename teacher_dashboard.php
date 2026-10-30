<?php
session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/group_leaders.php';

// Allow Teacher accounts only.
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'teacher'
) {
    header('Location: login.php');
    exit;
}

$teacherId = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Research Teacher';

ensureResearchGroupLeaders($pdo);
ensureResearchGroupNamedMembers($pdo);

$stmt = $pdo->prepare("
    SELECT
        rg.id,
        rg.group_name,
        rg.research_title,
        rg.description,
        rg.status,
        rg.overall_progress,
        rgl.student_id AS leader_id,
        leader.full_name AS leader_name,
        ((SELECT COUNT(*) FROM group_members AS gm
          WHERE gm.group_id = rg.id) +
         (SELECT COUNT(*) FROM research_group_named_members AS ngm
          WHERE ngm.group_id = rg.id)) AS member_count,
        (SELECT COUNT(*) FROM progress_updates AS pu
         WHERE pu.group_id = rg.id
           AND pu.review_status = 'Pending') AS pending_reviews,
        (SELECT MAX(pu.created_at) FROM progress_updates AS pu
         WHERE pu.group_id = rg.id) AS last_update
    FROM research_groups AS rg
    INNER JOIN group_advisers AS ga
        ON ga.group_id = rg.id
    LEFT JOIN research_group_leaders AS rgl
        ON rgl.group_id = rg.id
    LEFT JOIN users AS leader
        ON leader.id = rgl.student_id
    WHERE ga.teacher_id = ?
    ORDER BY rg.group_name ASC
");
$stmt->execute([$teacherId]);
$groups = $stmt->fetchAll();
$totalMemberCount = array_sum(array_map(
    static fn(array $group): int => (int) $group['member_count'],
    $groups
));
$pendingReviewCount = array_sum(array_map(
    static fn(array $group): int => (int) $group['pending_reviews'],
    $groups
));
$averageProgress = $groups
    ? (int) round(array_sum(array_map(
        static fn(array $group): int => (int) $group['overall_progress'],
        $groups
    )) / count($groups))
    : 0;

// Prepare query for student members of each group.
$memberStmt = $pdo->prepare("
    SELECT u.id AS member_id, u.full_name
    FROM group_members AS gm
    INNER JOIN users AS u ON u.id = gm.student_id
    WHERE gm.group_id = ?
    ORDER BY u.full_name ASC
");
$namedMemberStmt = $pdo->prepare("
    SELECT id AS member_id, full_name
    FROM research_group_named_members
    WHERE group_id = ?
    ORDER BY full_name ASC
");
$milestoneStmt = $pdo->prepare("
    SELECT m.milestone_title, m.due_date, m.status, rg.group_name
    FROM milestones AS m
    INNER JOIN research_groups AS rg ON rg.id = m.group_id
    INNER JOIN group_advisers AS ga ON ga.group_id = rg.id
    WHERE ga.teacher_id = ?
    ORDER BY
        CASE WHEN m.due_date IS NULL THEN 1 ELSE 0 END,
        m.due_date ASC,
        m.id ASC
    LIMIT 4
");
$milestoneStmt->execute([$teacherId]);
$upcomingMilestones = $milestoneStmt->fetchAll();

$activityStmt = $pdo->prepare("
    SELECT pu.progress_note, pu.created_at, pu.progress_percentage,
           pu.review_status, rg.group_name, u.full_name AS student_name
    FROM progress_updates AS pu
    INNER JOIN research_groups AS rg ON rg.id = pu.group_id
    INNER JOIN group_advisers AS ga ON ga.group_id = rg.id
    INNER JOIN users AS u ON u.id = pu.updated_by
    WHERE ga.teacher_id = ?
    ORDER BY pu.created_at DESC
    LIMIT 4
");
$activityStmt->execute([$teacherId]);
$recentActivity = $activityStmt->fetchAll();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | ResProg</title>

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
            font-size: 25px;
        }

        header p {
            margin: 0;
            color: #dce7f5;
        }

        .container {
            width: 86%;
            max-width: 1100px;
            margin: 30px auto;
        }

        .top-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }

        .top-links a {
            text-decoration: none;
            padding: 10px 15px;
            padding-top: 12px;
            border-radius: 8px;
            background: #e7f0fb;
            color: #174a7e;
        }

        .top-links a:hover {
            background: #d6e6f8;
        }

        .section-title { margin: 20px 0; }
        .section-title h2 { margin-bottom: 6px; }
        .overview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin: 22px 0; }
        .overview-stat { padding: 18px; border: 1px solid #2c527b; border-radius: 14px; background: linear-gradient(145deg, #10294e, #0a1d3b); }
        .overview-stat strong { display: block; color: #51eaf6; font-size: 26px; }
        .overview-stat span { color: #9db5d2; font-size: 13px; }
        .group-card { margin-bottom: 18px; padding: 22px; }
        .group-card h3 { margin-top: 0; }
        .research-title { color: #bdd5ef; }
        .leader-name { color: #51eaf6; font-weight: 750; }
        .group-summary { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px; margin: 14px 0; color: #a9c5e1; font-size: 13px; }
        .progress-track { height: 9px; overflow: hidden; border-radius: 99px; background: #07162f; }
        .progress-fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, #0879e9, #38e1ec); }
        .members { margin-top: 18px; }
        .members ul { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 8px; padding: 0; list-style: none; }
        .members li { display: flex; align-items: center; gap: 9px; padding: 9px 11px; border: 1px solid #284b70; border-radius: 9px; background: #091c39; }
        .member-dot { display: grid; flex: 0 0 27px; width: 27px; height: 27px; place-items: center; border-radius: 50%; background: #10507a; color: #75efff; font-size: 12px; font-weight: 800; }
        .leader-badge { margin-left: auto; padding: 4px 8px; border: 1px solid #286d86; border-radius: 99px; color: #73e7f2; font-size: 11px; }
        .empty-state { padding: 28px; border: 1px dashed #31547b; border-radius: 12px; background: #0c2142; color: #9db5d2; text-align: center; }

        @media (max-width: 600px) {
            .container {
                width: 92%;
            }

            header {
                padding: 20px 5%;
            }

            .group-card {
                padding: 17px;
            }
        }
    </style>
    <link rel="stylesheet" href="assets/resprog.css">
    <link rel="stylesheet" href="assets/teacher-dashboard.css">
</head>

<body class="teacher-dashboard">
    <div class="teacher-app">
        <aside class="teacher-sidebar" aria-label="Teacher navigation">
            <a class="teacher-brand" href="teacher_dashboard.php">
                <span class="teacher-brand-mark">R</span>
                <span>ResProg<small>TEACHER WORKSPACE</small></span>
            </a>
            <nav class="teacher-nav">
                <a class="active" href="#overview"><span class="teacher-nav-icon">⌂</span>Overview</a>
                <a href="#groups"><span class="teacher-nav-icon">♧</span>Research Groups</a>
                <a href="#milestones"><span class="teacher-nav-icon">⚑</span>Milestones</a>
                <a href="teacher_review_progress.php"><span class="teacher-nav-icon">▥</span>Progress Updates</a>
                <a href="research_documents.php"><span class="teacher-nav-icon">▤</span>Manuscripts</a>
                <a href="teacher_feedback.php"><span class="teacher-nav-icon">☏</span>Feedback</a>
                <a href="teacher_timetable.php"><span class="teacher-nav-icon">▦</span>Timetable</a>
                <a href="teacher_requirements.php"><span class="teacher-nav-icon">☑</span>Requirements</a>
            </nav>
            <div class="teacher-profile">
                <span class="teacher-profile-avatar"><?= e(mb_strtoupper(mb_substr($fullName, 0, 1))) ?></span>
                <span class="teacher-profile-name"><?= e($fullName) ?><small>Research Adviser</small></span>
                <a href="logout.php" aria-label="Log out">↗</a>
            </div>
        </aside>

        <main class="teacher-main" id="overview">
            <div class="teacher-topbar">
                <span>Dashboard <span class="breadcrumb-divider">/</span> Overview</span>
                <a href="logout.php">Log out</a>
            </div>
            <div class="teacher-content">
                <section class="teacher-heading">
                    <div>
                        <h1>Welcome back, <?= e($fullName) ?>!</h1>
                        <p>Here’s your research activity overview.</p>
                    </div>
                </section>

                <section class="teacher-stat-grid" aria-label="Research overview">
                    <a class="teacher-stat" href="#groups"><span class="stat-icon blue">♧</span><span class="stat-copy"><strong><?= count($groups) ?></strong><small>Research Groups</small></span><span class="stat-arrow">›</span></a>
                    <a class="teacher-stat" href="#groups"><span class="stat-icon green">♟</span><span class="stat-copy"><strong><?= $totalMemberCount ?></strong><small>Total Group Members</small></span><span class="stat-arrow">›</span></a>
                    <a class="teacher-stat" href="#groups"><span class="stat-icon purple">▥</span><span class="stat-copy"><strong><?= $averageProgress ?>%</strong><small>Average Progress</small></span><span class="stat-arrow">›</span></a>
                    <a class="teacher-stat" href="teacher_review_progress.php"><span class="stat-icon orange">▤</span><span class="stat-copy"><strong><?= $pendingReviewCount ?></strong><small>Pending Reviews</small></span><span class="stat-arrow">›</span></a>
                </section>

                <section class="teacher-panel assigned-panel" id="groups">
                    <div class="teacher-panel-heading"><h2>Assigned Research Groups</h2><a href="#groups">View All Groups</a></div>
                    <?php if (empty($groups)): ?>
                        <div class="teacher-empty">
                            <h3>No Research Groups Yet</h3>
                            <p>You do not have any assigned research groups. Please contact the administrator.</p>
                        </div>
                    <?php else: ?>
                        <div class="assigned-groups">
                            <?php foreach ($groups as $group): ?>
                                <?php
                                $memberStmt->execute([$group['id']]);
                                $members = $memberStmt->fetchAll();
                                $namedMemberStmt->execute([$group['id']]);
                                $namedMembers = $namedMemberStmt->fetchAll();
                                ?>
                                <article class="assigned-group">
                                    <div class="group-emblem" aria-hidden="true">♧</div>
                                    <div class="assigned-group-info">
                                        <div class="group-name-row"><h3><?= e($group['group_name']) ?></h3><span class="group-state"><?= e($group['status']) ?></span></div>
                                        <span class="research-label">Research Title</span>
                                        <p class="research-title"><?= e($group['research_title']) ?></p>
                                        <div class="group-leader"><span class="leader-avatar"><?= e(mb_strtoupper(mb_substr($group['leader_name'] ?? '?', 0, 1))) ?></span><span><?= e($group['leader_name'] ?? 'Not assigned') ?><small>Group Leader</small></span></div>
                                        <details class="teacher-members">
                                            <summary>View <?= (int) $group['member_count'] ?> group member<?= (int) $group['member_count'] === 1 ? '' : 's' ?></summary>
                                            <?php if (empty($members) && empty($namedMembers)): ?>
                                                <p class="teacher-muted">No members have been added to this group yet.</p>
                                            <?php else: ?>
                                                <ul>
                                                    <?php foreach ($members as $member): ?>
                                                        <li><span class="member-dot"><?= e(mb_strtoupper(mb_substr($member['full_name'], 0, 1))) ?></span><?= e($member['full_name']) ?><?php if ((int) $member['member_id'] === (int) $group['leader_id']): ?><span class="member-leader-badge">Leader</span><?php endif; ?></li>
                                                    <?php endforeach; ?>
                                                    <?php foreach ($namedMembers as $namedMember): ?>
                                                        <li><span class="member-dot"><?= e(mb_strtoupper(mb_substr($namedMember['full_name'], 0, 1))) ?></span><?= e($namedMember['full_name']) ?><span class="member-leader-badge">Member</span></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </details>
                                    </div>
                                    <div class="group-progress">
                                        <span>Group Progress</span>
                                        <div class="teacher-progress-row"><div class="teacher-progress-track" role="progressbar" aria-label="<?= e($group['group_name']) ?> group progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $group['overall_progress'] ?>"><div class="teacher-progress-fill" style="width: <?= (int) $group['overall_progress'] ?>%"></div></div><strong><?= (int) $group['overall_progress'] ?>%</strong></div>
                                        <small><?= (int) $group['member_count'] ?> students · <?= (int) $group['pending_reviews'] ?> pending reviews</small>
                                        <a class="group-details-link" href="teacher_review_progress.php">View Group Details <span>→</span></a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <div class="teacher-lower-grid">
                    <section class="teacher-panel" id="activity">
                        <div class="teacher-panel-heading"><h2><span class="panel-icon">◷</span>Recent Activity</h2><a href="teacher_review_progress.php">View All Activity</a></div>
                        <?php if (empty($recentActivity)): ?>
                            <div class="teacher-empty compact">No student progress updates yet.</div>
                        <?php else: ?>
                            <div class="activity-list">
                                <?php foreach ($recentActivity as $activity): ?>
                                    <article class="activity-item">
                                        <span class="activity-icon"><?= $activity['review_status'] === 'Pending' ? '↑' : '▤' ?></span>
                                        <span class="activity-copy"><strong><?= e($activity['student_name']) ?> updated the progress</strong><small><?= e($activity['group_name']) ?> · <?= (int) $activity['progress_percentage'] ?>% progress</small><?php if (!empty($activity['progress_note'])): ?><span><?= e($activity['progress_note']) ?></span><?php endif; ?></span>
                                        <time datetime="<?= e($activity['created_at']) ?>"><?= e(date('M j', strtotime($activity['created_at']))) ?></time>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="teacher-panel" id="milestones">
                        <div class="teacher-panel-heading"><h2><span class="panel-icon">▦</span>Upcoming Milestones</h2><a href="teacher_milestones.php">View All Milestones</a></div>
                        <?php if (empty($upcomingMilestones)): ?>
                            <div class="teacher-empty compact">No milestones have been scheduled for your groups.</div>
                        <?php else: ?>
                            <div class="upcoming-list">
                                <?php foreach ($upcomingMilestones as $milestone): ?>
                                    <article class="upcoming-item">
                                        <time class="milestone-date" datetime="<?= e($milestone['due_date']) ?>"><span><?= $milestone['due_date'] ? e(date('M', strtotime($milestone['due_date']))) : 'TBD' ?></span><strong><?= $milestone['due_date'] ? e(date('d', strtotime($milestone['due_date']))) : '—' ?></strong></time>
                                        <span class="upcoming-copy"><strong><?= e($milestone['milestone_title']) ?></strong><small><?= e($milestone['group_name']) ?></small></span>
                                        <span class="milestone-status"><?= e($milestone['status']) ?></span>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>

                <section class="teacher-quick-links" aria-label="Teacher tools">
                    <a href="teacher_milestones.php">Manage Milestones</a>
                    <a href="teacher_review_progress.php">Review Progress</a>
                    <a href="research_documents.php">Review Manuscripts</a>
                    <a href="teacher_feedback.php">Provide Feedback</a>
                    <a href="teacher_timetable.php">Manage Timetable</a>
                    <a href="teacher_requirements.php">Manage Requirements</a>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
