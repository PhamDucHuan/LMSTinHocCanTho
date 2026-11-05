<?php
declare(strict_types=1);

require_once __DIR__ . '/simple_xlsx.php';
require_once __DIR__ . '/quiz_answers.php';

/** Export all stored questions, including drafts, without applying attempt limits or shuffling. */
function quizExportLoadQuizzes(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare('SELECT id,title FROM quizzes WHERE course_id=? ORDER BY sort_order,id');
    $stmt->execute([$courseId]);
    $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $indexes = [];
    foreach ($quizzes as $index => &$quiz) {
        $quiz['questions'] = [];
        $indexes[(int) $quiz['id']] = $index;
    }
    unset($quiz);

    $stmt = $pdo->prepare(
        'SELECT qq.*,s.quiz_id,s.title AS section_title
         FROM quiz_questions qq
         JOIN quiz_sections s ON s.id=qq.section_id
         JOIN quizzes q ON q.id=s.quiz_id
         WHERE q.course_id=?
         ORDER BY q.sort_order,q.id,s.sort_order,s.id,qq.sort_order,qq.id'
    );
    $stmt->execute([$courseId]);
    while ($question = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($indexes[(int) $question['quiz_id']])) {
            $quizzes[$indexes[(int) $question['quiz_id']]]['questions'][] = $question;
        }
    }
    return $quizzes;
}

function quizExportBuildWorkbook(array $quizzes, string $uploadDirectory): SimpleXlsxWorkbook
{
    if ($quizzes === []) throw new RuntimeException('Khóa học chưa có bài trắc nghiệm để xuất.');

    $workbook = new SimpleXlsxWorkbook();
    $imageFields = ['question_image', 'option_a_image', 'option_b_image', 'option_c_image', 'option_d_image'];
    $uploadRoot = realpath($uploadDirectory);
    foreach ($quizzes as $quiz) {
        // Keep the first six columns in the same order as the CSV/XLSX import template.
        $headers = ['Câu hỏi', 'Đáp án A', 'Đáp án B', 'Đáp án C', 'Đáp án D', 'Đáp án đúng'];
        $widths = [65, 35, 35, 35, 35, 16];
        $hasImages = false;
        foreach ($quiz['questions'] as $question) {
            foreach ($imageFields as $field) {
                if (!empty($question[$field])) $hasImages = true;
            }
        }
        if ($hasImages) {
            $headers = [...$headers, 'Ảnh câu hỏi', 'Ảnh đáp án A', 'Ảnh đáp án B', 'Ảnh đáp án C', 'Ảnh đáp án D'];
            $widths = [...$widths, 28, 28, 28, 28, 28];
        }
        $multipleSections = count(array_unique(array_column($quiz['questions'], 'section_id'))) > 1;
        if ($multipleSections) {
            $headers[] = 'Phần câu hỏi';
            $widths[] = 30;
        }
        $headerCells = [];
        foreach ($headers as $index => $header) $headerCells[$index + 1] = ['value' => $header, 'style' => 4];
        $rows = [['height' => 32, 'cells' => $headerCells]];
        $images = [];
        foreach ($quiz['questions'] as $question) {
            $values = [
                $question['question_text'], $question['option_a'], $question['option_b'],
                $question['option_c'], $question['option_d'], quizNormalizeAnswerOptions($question['correct_option']),
            ];
            $cells = [];
            $height = 45;
            foreach ($values as $index => $value) {
                $cells[$index + 1] = ['value' => $value, 'style' => $index === 5 ? 6 : 5];
                // Estimate wrapped lines to keep long questions and answers readable.
                $lines = 0;
                foreach (explode("\n", (string) $value) as $line) {
                    $lines += max(1, (int) ceil(mb_strlen($line, 'UTF-8') / max(1, $widths[$index] - 3)));
                }
                $height = max($height, min(409, $lines * 15 + 10));
            }
            if ($hasImages) {
                foreach ($imageFields as $index => $field) {
                    $column = 7 + $index;
                    $cells[$column] = ['value' => '', 'style' => 5];
                    $relativePath = (string) ($question[$field] ?? '');
                    if ($relativePath === '') continue;
                    $path = $uploadRoot === false ? false : realpath($uploadRoot . '/' . $relativePath);
                    $insideUploads = $path !== false
                        && str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $uploadRoot) . '/');
                    $size = $insideUploads && is_readable($path) ? @getimagesize($path) : false;
                    if ($size && in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true)) {
                        $images[] = ['row' => count($rows) + 1, 'column' => $column, 'path' => $path];
                        $height = max($height, 85);
                    } else {
                        // Retain references for missing images and formats Excel cannot embed.
                        $cells[$column]['value'] = 'Ảnh: uploads/' . $relativePath;
                    }
                }
            }
            if ($multipleSections) $cells[count($headers)] = ['value' => $question['section_title'], 'style' => 5];
            $rows[] = ['height' => $height, 'cells' => $cells];
        }
        $workbook->addSheet((string) $quiz['title'], $rows, $widths, [], ['freeze_rows' => 1, 'images' => $images]);
    }
    return $workbook;
}
