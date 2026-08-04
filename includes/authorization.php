<?php
declare(strict_types=1);

/** Central policies kept free of session state so they are easy to test. */
function authorizationCanManageOwnedResource(string $role, int $actorId, int $ownerId): bool
{
    return $role === 'admin' || ($role === 'teacher' && $actorId === $ownerId);
}

function authorizationCanAccessAssignment(string $role, int $actorId, int $teacherId, ?int $courseId, bool $isEnrolled): bool
{
    if ($role === 'admin') return true;
    if ($role === 'teacher') return $actorId === $teacherId;
    return $role === 'student' && ($courseId === null || $isEnrolled);
}

function authorizationCanTakeQuiz(string $role, bool $isPublished): bool
{
    return $role === 'student' && $isPublished;
}

function authorizationCanDownloadSubmission(string $role, int $actorId, int $teacherId, int $studentId): bool
{
    return $role === 'admin'
        || ($role === 'teacher' && $actorId === $teacherId)
        || ($role === 'student' && $actorId === $studentId);
}

function authorizationStudentIsEnrolled(PDO $pdo, int $studentId, int $courseId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM course_enrollments WHERE course_id=? AND student_id=? LIMIT 1');
    $stmt->execute([$courseId, $studentId]);
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
    return authorizationCanAccessAssignment($role, $actorId, (int) $assignment['teacher_id'], $courseId, $isEnrolled)
        ? $assignment : null;
}

function authorizationFindManageableAssignment(PDO $pdo, int $assignmentId, string $role, int $actorId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM assignments WHERE id=? LIMIT 1');
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    return $assignment && authorizationCanManageOwnedResource($role, $actorId, (int) $assignment['teacher_id'])
        ? $assignment : null;
}

function authorizationFindManageableCourse(PDO $pdo, int $courseId, string $role, int $actorId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE id=? LIMIT 1');
    $stmt->execute([$courseId]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    return $course && authorizationCanManageOwnedResource($role, $actorId, (int) $course['teacher_id'])
        ? $course : null;
}

function authorizationFindAvailableQuiz(PDO $pdo, int $quizId, string $role): ?array
{
    $stmt = $pdo->prepare('SELECT q.*, c.title AS course_title, c.slug AS course_slug FROM quizzes q JOIN courses c ON c.id=q.course_id WHERE q.id=? LIMIT 1');
    $stmt->execute([$quizId]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    return $quiz && authorizationCanTakeQuiz($role, (bool) $quiz['is_published']) ? $quiz : null;
}
