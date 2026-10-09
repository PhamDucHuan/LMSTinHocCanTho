<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    // Preserve students from the legacy direct-enrollment table by placing
    // them in the first active class of the same course.
    $pdo->exec(
        "INSERT IGNORE INTO learning_class_students (learning_class_id, student_id, exam_date, added_by)
         SELECT target_class.learning_class_id, ce.student_id, DATE(ce.exam_date), c.teacher_id
         FROM course_enrollments ce
         JOIN courses c ON c.id=ce.course_id
         JOIN users student ON student.id=ce.student_id
            AND student.role='student' AND student.is_approved=1
         JOIN (
             SELECT course_id, MIN(id) AS learning_class_id
             FROM learning_classes
             WHERE status='active'
             GROUP BY course_id
         ) target_class ON target_class.course_id=ce.course_id
         WHERE NOT EXISTS (
             SELECT 1 FROM learning_classes existing_class
             JOIN learning_class_students existing_student ON existing_student.learning_class_id=existing_class.id
             WHERE existing_class.course_id=ce.course_id
               AND existing_class.status='active'
               AND existing_student.student_id=ce.student_id
         )"
    );

    // An approved request from the old course-only workflow also represents a
    // previously accepted student. Keep that approval when moving to classes.
    $pdo->exec(
        "INSERT IGNORE INTO learning_class_students (learning_class_id, student_id, exam_date, added_by)
         SELECT COALESCE(selected_class.id, target_class.learning_class_id), er.student_id, NULL,
                COALESCE(er.reviewed_by, c.teacher_id)
         FROM course_enrollment_requests er
         JOIN courses c ON c.id=er.course_id
         JOIN users student ON student.id=er.student_id
            AND student.role='student' AND student.is_approved=1
         JOIN (
             SELECT course_id, MIN(id) AS learning_class_id
             FROM learning_classes
             WHERE status='active'
             GROUP BY course_id
         ) target_class ON target_class.course_id=er.course_id
         LEFT JOIN learning_classes selected_class
            ON selected_class.id=er.learning_class_id
           AND selected_class.course_id=er.course_id
           AND selected_class.status='active'
         WHERE er.status='approved'
           AND NOT EXISTS (
               SELECT 1 FROM learning_classes existing_class
               JOIN learning_class_students existing_student ON existing_student.learning_class_id=existing_class.id
               WHERE existing_class.course_id=er.course_id
                 AND existing_class.status='active'
                 AND existing_student.student_id=er.student_id
           )"
    );

    // Make the request/status table reflect every effective active membership.
    $pdo->exec(
        "INSERT INTO course_enrollment_requests
            (course_id, student_id, learning_class_id, status, requested_at, reviewed_at, reviewed_by)
         SELECT members.course_id, members.student_id, members.learning_class_id, 'approved',
                members.joined_at, members.joined_at, members.reviewer_id
         FROM (
             SELECT lc.course_id, lcs.student_id, MIN(lc.id) AS learning_class_id,
                    MIN(lcs.created_at) AS joined_at,
                    COALESCE(MIN(lcs.added_by), MIN(lc.primary_teacher_id), MIN(c.teacher_id)) AS reviewer_id
             FROM learning_classes lc
             JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
             JOIN courses c ON c.id=lc.course_id
             WHERE lc.status='active'
             GROUP BY lc.course_id, lcs.student_id
         ) members
         ON DUPLICATE KEY UPDATE
            learning_class_id=VALUES(learning_class_id),
            status='approved',
            reviewed_at=COALESCE(reviewed_at, VALUES(reviewed_at)),
            reviewed_by=COALESCE(reviewed_by, VALUES(reviewed_by))"
    );
};

