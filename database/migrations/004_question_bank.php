<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/question_bank.php';
return static function (PDO $pdo): void { ensureQuestionBankSchema($pdo); };
