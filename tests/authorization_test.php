<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/authorization.php';

$tests = [];
function authTest(string $name, callable $test): void { global $tests; $tests[] = [$name, $test]; }
function authAssert(bool $condition): void { if (!$condition) throw new RuntimeException('Assertion failed'); }

authTest('Admin manages every resource', fn() => authAssert(authorizationCanManageOwnedResource('admin', 1, 99)));
authTest('Teacher manages own resource', fn() => authAssert(authorizationCanManageOwnedResource('teacher', 7, 7)));
authTest('Teacher cannot manage another resource', fn() => authAssert(!authorizationCanManageOwnedResource('teacher', 7, 8)));
authTest('Administrative staff manages own teaching resource', fn() => authAssert(authorizationCanManageOwnedResource('administrative_staff', 7, 7)));
authTest('Administrative staff cannot modify another teacher resource', fn() => authAssert(!authorizationCanManageOwnedResource('administrative_staff', 7, 8)));
authTest('Student cannot manage teaching resources', fn() => authAssert(!authorizationCanManageOwnedResource('student', 7, 7)));
authTest('Enrolled student accesses assignment', fn() => authAssert(authorizationCanAccessAssignment('student', 20, 7, 5, true)));
authTest('Unenrolled student cannot access assignment or AI exam', fn() => authAssert(!authorizationCanAccessAssignment('student', 20, 7, 5, false)));
authTest('General assignment remains accessible', fn() => authAssert(authorizationCanAccessAssignment('student', 20, 7, null, false)));
authTest('Teacher cannot preview another teacher assignment', fn() => authAssert(!authorizationCanAccessAssignment('teacher', 7, 8, 5, false)));
authTest('Every student takes published quiz without enrollment', fn() => authAssert(authorizationCanTakeQuiz('student', true)));
authTest('Draft quiz is unavailable', fn() => authAssert(!authorizationCanTakeQuiz('student', false)));
authTest('Student downloads only own submission', function (): void {
    authAssert(authorizationCanDownloadSubmission('student', 20, 7, 20));
    authAssert(!authorizationCanDownloadSubmission('student', 21, 7, 20));
});
authTest('Teacher downloads only own assignment submissions', function (): void {
    authAssert(authorizationCanDownloadSubmission('teacher', 7, 7, 20));
    authAssert(!authorizationCanDownloadSubmission('teacher', 8, 7, 20));
});
authTest('Administrative staff downloads only own assignment submissions', function (): void {
    authAssert(authorizationCanDownloadSubmission('administrative_staff', 7, 7, 20));
    authAssert(!authorizationCanDownloadSubmission('administrative_staff', 8, 7, 20));
});

$failures = 0;
foreach ($tests as [$name, $test]) {
    try { $test(); echo "[PASS] {$name}\n"; }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "[FAIL] {$name}: {$error->getMessage()}\n"); }
}
echo sprintf("\n%d tests, %d failures.\n", count($tests), $failures);
exit($failures === 0 ? 0 : 1);
