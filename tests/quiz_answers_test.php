<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/quiz_answers.php';

$cases = [
    ['A', 'A'],
    ['A,D', 'A,D'],
    ['d, a', 'A,D'],
    [['D', 'A', 'D'], 'A,D'],
    ['A; C', 'A,C'],
];

foreach ($cases as [$input, $expected]) {
    if (quizNormalizeAnswerOptions($input) !== $expected) {
        fwrite(STDERR, "[FAIL] Không chuẩn hóa được đáp án {$expected}.\n");
        exit(1);
    }
}

if (!quizAnswerIsCorrect(['D', 'A'], 'A,D')) {
    fwrite(STDERR, "[FAIL] Hai tập đáp án giống nhau phải được tính đúng.\n");
    exit(1);
}
if (quizAnswerIsCorrect('A', 'A,D') || quizAnswerIsCorrect('A,B,D', 'A,D')) {
    fwrite(STDERR, "[FAIL] Chọn thiếu hoặc chọn thừa không được tính đúng.\n");
    exit(1);
}
if (quizNormalizeAnswerOptions('A,X') !== '') {
    fwrite(STDERR, "[FAIL] Đáp án ngoài A-D phải bị từ chối.\n");
    exit(1);
}

echo "[PASS] Multiple-answer normalization and exact-set grading\n";
