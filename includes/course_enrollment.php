<?php
declare(strict_types=1);

/**
 * Create or reopen a student's request to join a course.
 *
 * Course access is granted only through an active learning class. Creating a
 * request never inserts into learning_class_students.
 */
function requestCourseEnrollment(PDO $pdo, int $studentId, int $courseId): array
{
    if ($studentId <= 0 || $courseId <= 0) {
        throw new RuntimeException('Khóa học không hợp lệ.');
    }

    $studentStmt = $pdo->prepare(
        "SELECT id FROM users
         WHERE id=? AND role='student' AND is_approved=1 AND COALESCE(is_locked,0)=0
         LIMIT 1"
    );
    $studentStmt->execute([$studentId]);
    if (!$studentStmt->fetchColumn()) {
        throw new RuntimeException('Tài khoản học viên không hợp lệ hoặc đang bị khóa.');
    }

    $courseStmt = $pdo->prepare(
        "SELECT c.id, c.title, c.teacher_id,
                (SELECT COUNT(*) FROM learning_classes lc WHERE lc.course_id=c.id AND lc.status='active') AS active_class_count,
                EXISTS(
                    SELECT 1 FROM learning_classes lc
                    JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
                    WHERE lc.course_id=c.id AND lc.status='active' AND lcs.student_id=?
                ) AS is_enrolled
         FROM courses c
         WHERE c.id=?
         LIMIT 1"
    );
    $courseStmt->execute([$studentId, $courseId]);
    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        throw new RuntimeException('Khóa học không tồn tại.');
    }
    if (!empty($course['is_enrolled'])) {
        throw new RuntimeException('Bạn đã được xếp vào lớp của khóa học này.');
    }
    if ((int) $course['active_class_count'] <= 0) {
        throw new RuntimeException('Khóa học này chưa có lớp đang mở để đăng ký.');
    }

    $requestStmt = $pdo->prepare(
        "INSERT INTO course_enrollment_requests
            (course_id, student_id, learning_class_id, status, requested_at, reviewed_at, reviewed_by)
         VALUES (?, ?, NULL, 'pending', NOW(), NULL, NULL)
         ON DUPLICATE KEY UPDATE
            learning_class_id=NULL,
            status='pending',
            requested_at=NOW(),
            reviewed_at=NULL,
            reviewed_by=NULL"
    );
    $requestStmt->execute([$courseId, $studentId]);

    $idStmt = $pdo->prepare('SELECT id FROM course_enrollment_requests WHERE course_id=? AND student_id=? LIMIT 1');
    $idStmt->execute([$courseId, $studentId]);
    $course['request_id'] = (int) $idStmt->fetchColumn();
    return $course;
}

/** Return active course managers plus administrators who should review a request. */
function courseEnrollmentReviewerIds(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.id
         FROM users u
         WHERE u.is_approved=1 AND COALESCE(u.is_locked,0)=0
           AND (
               u.role='admin'
               OR u.id=(SELECT c.teacher_id FROM courses c WHERE c.id=? LIMIT 1)
               OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=? AND ct.teacher_id=u.id)
           )
         ORDER BY u.id"
    );
    $stmt->execute([$courseId, $courseId]);
    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

/**
 * Approve or reject a pending request. Approval grants access by adding the
 * student to one active class belonging to the requested course.
 */
function reviewCourseEnrollment(
    PDO $pdo,
    int $requestId,
    int $courseId,
    string $decision,
    ?int $learningClassId,
    int $reviewerId
): array {
    if ($requestId <= 0 || $courseId <= 0 || $reviewerId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
        throw new RuntimeException('Yêu cầu đăng ký không hợp lệ.');
    }

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) $pdo->beginTransaction();
    try {
        $requestStmt = $pdo->prepare(
            "SELECT er.id, er.student_id, er.status, er.requested_at,
                    c.title AS course_title, u.name AS student_name, u.email AS student_email,
                    u.role AS student_role, u.is_approved, COALESCE(u.is_locked,0) AS is_locked
             FROM course_enrollment_requests er
             JOIN courses c ON c.id=er.course_id
             JOIN users u ON u.id=er.student_id
             WHERE er.id=? AND er.course_id=?
             LIMIT 1 FOR UPDATE"
        );
        $requestStmt->execute([$requestId, $courseId]);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || $request['status'] !== 'pending') {
            throw new RuntimeException('Yêu cầu đăng ký không còn ở trạng thái chờ duyệt.');
        }
        $approvedClassId = null;
        $approvedClassName = null;
        $alreadyEnrolled = false;
        if ($decision === 'approved') {
            if ($request['student_role'] !== 'student' || empty($request['is_approved']) || !empty($request['is_locked'])) {
                throw new RuntimeException('Tài khoản học viên không còn hoạt động nên chưa thể duyệt.');
            }
            $existingStmt = $pdo->prepare(
                "SELECT lc.id, lc.class_name
                 FROM learning_classes lc
                 JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
                 WHERE lc.course_id=? AND lc.status='active' AND lcs.student_id=?
                 ORDER BY lc.id LIMIT 1 FOR UPDATE"
            );
            $existingStmt->execute([$courseId, (int) $request['student_id']]);
            $existingClass = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingClass) {
                $approvedClassId = (int) $existingClass['id'];
                $approvedClassName = (string) $existingClass['class_name'];
                $alreadyEnrolled = true;
            } else {
                if (($learningClassId ?? 0) <= 0) {
                    throw new RuntimeException('Vui lòng chọn lớp để tiếp nhận học viên.');
                }
                $classStmt = $pdo->prepare(
                    "SELECT id, class_name FROM learning_classes
                     WHERE id=? AND course_id=? AND status='active'
                     LIMIT 1 FOR UPDATE"
                );
                $classStmt->execute([$learningClassId, $courseId]);
                $class = $classStmt->fetch(PDO::FETCH_ASSOC);
                if (!$class) {
                    throw new RuntimeException('Lớp được chọn không tồn tại, đã lưu trữ hoặc không thuộc khóa học này.');
                }
                $approvedClassId = (int) $class['id'];
                $approvedClassName = (string) $class['class_name'];
                $insertStmt = $pdo->prepare(
                    'INSERT IGNORE INTO learning_class_students (learning_class_id, student_id, exam_date, added_by) VALUES (?, ?, NULL, ?)'
                );
                $insertStmt->execute([$approvedClassId, (int) $request['student_id'], $reviewerId]);
            }
        }

        $updateStmt = $pdo->prepare(
            'UPDATE course_enrollment_requests
             SET learning_class_id=?, status=?, reviewed_at=NOW(), reviewed_by=?
             WHERE id=? AND status=\'pending\''
        );
        $updateStmt->execute([$approvedClassId, $decision, $reviewerId, $requestId]);
        if ($updateStmt->rowCount() !== 1) {
            throw new RuntimeException('Yêu cầu vừa được xử lý ở một phiên khác. Vui lòng tải lại trang.');
        }

        if ($startedTransaction) $pdo->commit();
        $request['decision'] = $decision;
        $request['learning_class_id'] = $approvedClassId;
        $request['class_name'] = $approvedClassName;
        $request['already_enrolled'] = $alreadyEnrolled;
        return $request;
    } catch (Throwable $error) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
