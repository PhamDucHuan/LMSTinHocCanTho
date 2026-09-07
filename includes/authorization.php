<?php
declare(strict_types=1);

/** Central policies kept free of session state so they are easy to test. */
function authorizationCanManageOwnedResource(string $role, int $actorId, int $ownerId): bool
{
    return $role === 'admin' || (in_array($role, ['teacher', 'administrative_staff'], true) && $actorId === $ownerId);
}

function authorizationCanAccessAssignment(string $role, int $actorId, int $teacherId, ?int $courseId, bool $isEnrolled): bool
{
    if ($role === 'admin') return true;
    if (in_array($role, ['teacher', 'administrative_staff'], true)) return $actorId === $teacherId;
    return $role === 'student' && ($courseId === null || $isEnrolled);
}

function authorizationCanTakeQuiz(string $role, bool $isPublished, bool $hasCourseAccess): bool
{
    return $role === 'student' && $isPublished && $hasCourseAccess;
}

function authorizationCanDownloadSubmission(string $role, int $actorId, int $teacherId, int $studentId): bool
{
    return $role === 'admin'
        || (in_array($role, ['teacher', 'administrative_staff'], true) && $actorId === $teacherId)
        || ($role === 'student' && $actorId === $studentId);
}

function authorizationStudentIsEnrolled(PDO $pdo, int $studentId, int $courseId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM courses c
         WHERE c.id=? AND EXISTS (
             SELECT 1
             FROM learning_classes lc
             JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
             WHERE lc.course_id=c.id AND lc.status='active' AND lcs.student_id=?
         ) LIMIT 1"
    );
    $stmt->execute([$courseId, $studentId]);
    return (bool) $stmt->fetchColumn();
}

function authorizationUserCanManageCourse(PDO $pdo, int $courseId, string $role, int $actorId): bool
{
    if ($role === 'admin') return true;
    if (!in_array($role, ['teacher', 'administrative_staff'], true)) return false;
    $stmt = $pdo->prepare(
        'SELECT 1 FROM courses c
         WHERE c.id=? AND (
             c.teacher_id=? OR EXISTS (
                 SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?
             )
         ) LIMIT 1'
    );
    $stmt->execute([$courseId, $actorId, $actorId]);
    return (bool) $stmt->fetchColumn();
}

function authorizationFindAccessibleAssignment(PDO $pdo, int $assignmentId, string $role, int $actorId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM assignments WHERE id=? LIMIT 1');
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) return null;

    $courseId = $assignment['course_id'] === null ? null : (int) $assignment['course_id'];
    $isEnrolled = $role === 'student' && $courseId !== null
        ? authorizationStudentIsEnrolled($pdo, $actorId, $courseId)
        : false;
    if (in_array($role, ['teacher', 'administrative_staff'], true) && $courseId !== null) {
        return authorizationUserCanManageCourse($pdo, $courseId, $role, $actorId) ? $assignment : null;
    }
    return authorizationCanAccessAssignment($role, $actorId, (int) $assignment['teacher_id'], $courseId, $isEnrolled) ? $assignment : null;
}

function authorizationFindManageableAssignment(PDO $pdo, int $assignmentId, string $role, int $actorId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM assignments WHERE id=? LIMIT 1');
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) return null;
    if ($role === 'admin') return $assignment;
    $courseId = $assignment['course_id'] === null ? null : (int) $assignment['course_id'];
    if ($courseId !== null && authorizationUserCanManageCourse($pdo, $courseId, $role, $actorId)) return $assignment;
    return authorizationCanManageOwnedResource($role, $actorId, (int) $assignment['teacher_id']) ? $assignment : null;
}

function authorizationFindManageableCourse(PDO $pdo, int $courseId, string $role, int $actorId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE id=? LIMIT 1');
    $stmt->execute([$courseId]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    return $course && authorizationUserCanManageCourse($pdo, $courseId, $role, $actorId) ? $course : null;
}

function authorizationFindAvailableQuiz(PDO $pdo, int $quizId, string $role, int $actorId): ?array
{
    $stmt = $pdo->prepare('SELECT q.*, c.title AS course_title, c.slug AS course_slug FROM quizzes q JOIN courses c ON c.id=q.course_id WHERE q.id=? LIMIT 1');
    $stmt->execute([$quizId]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasCourseAccess = $quiz && $role === 'student'
        ? authorizationStudentIsEnrolled($pdo, $actorId, (int) $quiz['course_id'])
        : false;
    return $quiz && authorizationCanTakeQuiz($role, (bool) $quiz['is_published'], $hasCourseAccess) ? $quiz : null;
}
