<?php
session_start();

require_once __DIR__ . '/config/db.php';

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['student', 'teacher'], true)
) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];
$error = '';
$message = $_SESSION['document_message'] ?? '';
unset($_SESSION['document_message']);
$storageDirectory = __DIR__ . '/config/research_uploads';
$maximumFileSize = 10 * 1024 * 1024;

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS research_documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            student_id INT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name CHAR(36) NOT NULL UNIQUE,
            file_size INT UNSIGNED NOT NULL,
            review_status ENUM('Pending', 'Under Review', 'Reviewed') NOT NULL DEFAULT 'Pending',
            teacher_comment TEXT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            INDEX idx_research_documents_student (student_id, submitted_at),
            INDEX idx_research_documents_group (group_id, review_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $exception) {
    error_log('ResProg research document table setup error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Research document storage is not configured. Please contact the administrator.');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $error = 'Your session token expired. Refresh the page and try again.';
    } elseif ($role === 'student' && ($_POST['action'] ?? '') === 'upload') {
        $groupId = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
        $file = $_FILES['research_pdf'] ?? null;

        if (
            !$groupId ||
            !is_array($file) ||
            !isset($file['error'], $file['tmp_name'], $file['name'], $file['size']) ||
            !is_int($file['error']) ||
            !is_string($file['tmp_name']) ||
            !is_string($file['name']) ||
            !is_numeric($file['size'])
        ) {
            $error = 'Choose your research group and a PDF manuscript.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $error = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'The PDF is larger than the server allows.'
                : 'The PDF could not be uploaded. Please try again.';
        } elseif (
            !is_uploaded_file($file['tmp_name']) ||
            $file['size'] < 1 ||
            $file['size'] > $maximumFileSize
        ) {
            $error = 'Choose a PDF file smaller than 10 MB.';
        } else {
            $membership = $pdo->prepare("
                SELECT rg.id
                FROM research_groups AS rg
                INNER JOIN group_members AS gm ON gm.group_id = rg.id
                WHERE rg.id = ? AND gm.student_id = ?
                LIMIT 1
            ");
            $membership->execute([$groupId, $userId]);

            if (!$membership->fetch()) {
                $error = 'You can only submit a manuscript to your assigned research group.';
            } else {
                $signature = file_get_contents($file['tmp_name'], false, null, 0, 5);
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

                if ($signature !== '%PDF-' || $mime !== 'application/pdf') {
                    $error = 'Only valid PDF documents can be submitted.';
                } else {
                    $originalName = basename(str_replace('\\', '/', $file['name']));
                    $originalName = mb_substr($originalName, 0, 255);
                    $storedName = bin2hex(random_bytes(16)) . '.pdf';
                    $destination = $storageDirectory . DIRECTORY_SEPARATOR . $storedName;

                    if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
                        error_log('ResProg could not create the private research document directory.');
                        $error = 'Document storage is unavailable. Please contact the administrator.';
                    } elseif (!move_uploaded_file($file['tmp_name'], $destination)) {
                        error_log('ResProg could not move an uploaded research document.');
                        $error = 'The manuscript could not be saved. Please try again.';
                    } else {
                        try {
                            $insert = $pdo->prepare("
                                INSERT INTO research_documents
                                    (group_id, student_id, original_name, stored_name, file_size)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $insert->execute([
                                $groupId,
                                $userId,
                                $originalName,
                                $storedName,
                                (int) $file['size']
                            ]);
                            $_SESSION['document_message'] = 'Manuscript submitted. Its review status is Pending.';
                            header('Location: research_documents.php');
                            exit;
                        } catch (PDOException $exception) {
                            if (is_file($destination) && !unlink($destination)) {
                                error_log('ResProg could not remove an orphaned research document.');
                            }
                            error_log('ResProg research document record error: ' . $exception->getMessage());
                            $error = 'The manuscript could not be recorded. Please try again.';
                        }
                    }
                }
            }
        }
    } elseif ($role === 'teacher' && ($_POST['action'] ?? '') === 'review') {
        $documentId = filter_input(INPUT_POST, 'document_id', FILTER_VALIDATE_INT);
        $status = $_POST['review_status'] ?? '';
        $comment = trim($_POST['teacher_comment'] ?? '');

        if (!$documentId || !in_array($status, ['Pending', 'Under Review', 'Reviewed'], true)) {
            $error = 'Choose a valid document and review status.';
        } else {
            $assignedDocument = $pdo->prepare("
                SELECT rd.id
                FROM research_documents AS rd
                INNER JOIN group_advisers AS ga ON ga.group_id = rd.group_id
                WHERE rd.id = ? AND ga.teacher_id = ?
                LIMIT 1
            ");
            $assignedDocument->execute([$documentId, $userId]);

            if (!$assignedDocument->fetch()) {
                $error = 'This document is not from one of your assigned research groups.';
            } else {
                $review = $pdo->prepare("
                    UPDATE research_documents
                    SET review_status = ?,
                        teacher_comment = ?,
                        reviewed_at = CASE WHEN ? = 'Reviewed' THEN NOW() ELSE NULL END
                    WHERE id = ?
                ");
                try {
                    $review->execute([
                        $status,
                        $comment !== '' ? $comment : null,
                        $status,
                        $documentId
                    ]);
                    $_SESSION['document_message'] = 'Document review status updated.';
                    header('Location: research_documents.php');
                    exit;
                } catch (PDOException $exception) {
                    error_log('ResProg document review error: ' . $exception->getMessage());
                    $error = 'The document review could not be saved. Please try again.';
                }
            }
        }
    } else {
        $error = 'This action is not available for your account.';
    }
}

if ($role === 'student') {
    $groupsStmt = $pdo->prepare("
        SELECT rg.id, rg.group_name, rg.research_title
        FROM research_groups AS rg
        INNER JOIN group_members AS gm ON gm.group_id = rg.id
        WHERE gm.student_id = ?
        ORDER BY rg.group_name
    ");
    $groupsStmt->execute([$userId]);
    $groups = $groupsStmt->fetchAll();

    $documentsStmt = $pdo->prepare("
        SELECT rd.id, rd.original_name, rd.file_size, rd.review_status,
               rd.teacher_comment, rd.submitted_at, rd.reviewed_at,
               rg.group_name, rg.research_title
        FROM research_documents AS rd
        INNER JOIN research_groups AS rg ON rg.id = rd.group_id
        WHERE rd.student_id = ?
        ORDER BY rd.submitted_at DESC
    ");
    $documentsStmt->execute([$userId]);
} else {
    $groups = [];
    $documentsStmt = $pdo->prepare("
        SELECT rd.id, rd.original_name, rd.file_size, rd.review_status,
               rd.teacher_comment, rd.submitted_at, rd.reviewed_at,
               rg.group_name, rg.research_title, u.full_name AS student_name
        FROM research_documents AS rd
        INNER JOIN research_groups AS rg ON rg.id = rd.group_id
        INNER JOIN group_advisers AS ga ON ga.group_id = rg.id
        INNER JOIN users AS u ON u.id = rd.student_id
        WHERE ga.teacher_id = ?
        ORDER BY CASE WHEN rd.review_status = 'Pending' THEN 0
                      WHEN rd.review_status = 'Under Review' THEN 1 ELSE 2 END,
                 rd.submitted_at DESC
    ");
    $documentsStmt->execute([$userId]);
}
$documents = $documentsStmt->fetchAll();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (isset($_GET['download'])) {
    $documentId = filter_input(INPUT_GET, 'download', FILTER_VALIDATE_INT);
    if (!$documentId) {
        http_response_code(404);
        exit('Document not found.');
    }

    if ($role === 'student') {
        $downloadStmt = $pdo->prepare("
            SELECT rd.stored_name, rd.original_name
            FROM research_documents AS rd
            WHERE rd.id = ? AND rd.student_id = ?
        ");
        $downloadStmt->execute([$documentId, $userId]);
    } else {
        $downloadStmt = $pdo->prepare("
            SELECT rd.stored_name, rd.original_name
            FROM research_documents AS rd
            INNER JOIN group_advisers AS ga ON ga.group_id = rd.group_id
            WHERE rd.id = ? AND ga.teacher_id = ?
        ");
        $downloadStmt->execute([$documentId, $userId]);
    }

    $document = $downloadStmt->fetch();
    $filePath = $document
        ? $storageDirectory . DIRECTORY_SEPARATOR . $document['stored_name']
        : '';
    if (!$document || !is_file($filePath)) {
        http_response_code(404);
        exit('Document not found.');
    }
    if (!is_readable($filePath)) {
        error_log('ResProg research document is not readable: ' . $filePath);
        http_response_code(500);
        exit('The document could not be opened. Please contact the administrator.');
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: inline; filename="research-document.pdf"; filename*=UTF-8\'\'' . rawurlencode($document['original_name']));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

$pageTitle = $role === 'student' ? 'My Research Documents' : 'Student Manuscripts';
$backLink = $role === 'student' ? 'student_dashboard.php' : 'teacher_dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | ResProg</title>
    <link rel="stylesheet" href="assets/resprog.css">
</head>
<body>
<main class="container">
    <nav class="page-nav"><a class="button button-secondary" href="<?= e($backLink) ?>">← Back to dashboard</a></nav>
    <header class="page-heading">
        <p class="eyebrow">Research documents</p>
        <h1><?= e($pageTitle) ?></h1>
        <p><?= $role === 'student'
            ? 'Submit your research manuscript as a PDF and follow its review progress.'
            : 'Review PDF manuscripts submitted by students in your assigned groups.' ?></p>
    </header>

    <?php if ($message !== ''): ?><p class="notice notice-success" role="status"><?= e($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <?php if ($role === 'student'): ?>
        <section class="panel">
            <h2>Submit a manuscript</h2>
            <p class="muted">PDF files only. Maximum file size: 10 MB.</p>
            <?php if (empty($groups)): ?>
                <p>You need to be assigned to a research group before submitting a document.</p>
            <?php else: ?>
                <form class="upload-form" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="upload">
                    <label for="group_id">Research group</label>
                    <select id="group_id" name="group_id" required>
                        <option value="">Select your group</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>"><?= e($group['group_name']) ?> — <?= e($group['research_title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="research_pdf">Research manuscript (PDF)</label>
                    <input id="research_pdf" name="research_pdf" type="file" accept="application/pdf,.pdf" required>
                    <button class="button button-primary" type="submit">Submit PDF</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2><?= $role === 'student' ? 'Submission history' : 'Manuscripts to review' ?></h2>
        <?php if (empty($documents)): ?>
            <p class="muted"><?= $role === 'student' ? 'You have not submitted any manuscripts yet.' : 'There are no manuscripts in your assigned groups yet.' ?></p>
        <?php else: ?>
            <div class="document-list">
                <?php foreach ($documents as $document): ?>
                    <article class="document-card">
                        <div class="document-heading">
                            <div>
                                <h3><?= e($document['original_name']) ?></h3>
                                <p><?= e($document['group_name']) ?> · <?= e($document['research_title']) ?></p>
                                <?php if ($role === 'teacher'): ?><p><strong>Submitted by:</strong> <?= e($document['student_name']) ?></p><?php endif; ?>
                                <p class="muted"><?= e($document['submitted_at']) ?> · <?= number_format((int) $document['file_size'] / 1024, 0) ?> KB</p>
                            </div>
                            <span class="status-badge status-<?= strtolower(str_replace(' ', '-', e($document['review_status']))) ?>"><?= e($document['review_status']) ?></span>
                        </div>
                        <a class="button button-secondary" href="research_documents.php?download=<?= (int) $document['id'] ?>" target="_blank" rel="noopener">Open PDF</a>
                        <?php if ($role === 'teacher'): ?>
                            <form method="post" class="review-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="review">
                                <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                                <label for="status_<?= (int) $document['id'] ?>">Review status</label>
                                <select id="status_<?= (int) $document['id'] ?>" name="review_status" required>
                                    <?php foreach (['Pending', 'Under Review', 'Reviewed'] as $status): ?>
                                        <option value="<?= e($status) ?>" <?= $document['review_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label for="comment_<?= (int) $document['id'] ?>">Feedback for the student</label>
                                <textarea id="comment_<?= (int) $document['id'] ?>" name="teacher_comment" rows="3"><?= e($document['teacher_comment'] ?? '') ?></textarea>
                                <button class="button button-primary" type="submit">Save review</button>
                            </form>
                        <?php elseif (!empty($document['teacher_comment'])): ?>
                            <p><strong>Teacher feedback:</strong><br><?= nl2br(e($document['teacher_comment'])) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($document['reviewed_at'])): ?><p class="muted">Reviewed <?= e($document['reviewed_at']) ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
