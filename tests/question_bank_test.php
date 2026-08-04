<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/question_bank.php';

$first = ['question_text'=>'  Hàm SUM dùng để làm gì? ','option_a'=>'Tính tổng','option_b'=>'Đếm','option_c'=>'Tìm lớn nhất','option_d'=>'Sắp xếp'];
$same = ['question_text'=>'hàm   sum dùng để làm gì?','option_a'=>'Tính tổng','option_b'=>'Đếm','option_c'=>'Tìm lớn nhất','option_d'=>'Sắp xếp'];
$different = $same;
$different['question_text'] = 'Hàm COUNT dùng để làm gì?';
if (questionFingerprint($first) !== questionFingerprint($same)) { fwrite(STDERR,"[FAIL] Normalized duplicate was not detected.\n"); exit(1); }
if (questionFingerprint($first) === questionFingerprint($different)) { fwrite(STDERR,"[FAIL] Different questions have identical fingerprints.\n"); exit(1); }
if (questionDifficultyLabel('hard') !== 'Khó') { fwrite(STDERR,"[FAIL] Difficulty label is incorrect.\n"); exit(1); }
echo "[PASS] Question fingerprints and difficulty labels\n3 tests, 0 failures.\n";
