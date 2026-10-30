<?php

function ensureResearchGroupLeaders(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS research_group_leaders (
            group_id INT NOT NULL PRIMARY KEY,
            student_id INT NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_research_group_leaders_student (student_id),
            CONSTRAINT fk_research_group_leaders_group
                FOREIGN KEY (group_id) REFERENCES research_groups (id)
                ON DELETE CASCADE,
            CONSTRAINT fk_research_group_leaders_member
                FOREIGN KEY (group_id, student_id)
                REFERENCES group_members (group_id, student_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        INSERT IGNORE INTO research_group_leaders (group_id, student_id)
        SELECT gm.group_id, gm.student_id
        FROM group_members AS gm
        LEFT JOIN research_group_leaders AS rgl
            ON rgl.group_id = gm.group_id
        WHERE rgl.group_id IS NULL
        ORDER BY gm.assigned_at ASC, gm.student_id ASC
    ");
}

function ensureResearchGroupNamedMembers(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS research_group_named_members (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            added_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_research_group_named_member (group_id, full_name),
            CONSTRAINT fk_named_members_group
                FOREIGN KEY (group_id) REFERENCES research_groups (id)
                ON DELETE CASCADE,
            CONSTRAINT fk_named_members_user
                FOREIGN KEY (added_by) REFERENCES users (id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
