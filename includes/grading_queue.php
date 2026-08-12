<?php
declare(strict_types=1);

final class AiGradingUnavailableException extends RuntimeException
{
}

function buildStoredAiFeedback(array $aiResponse): array
{
    $feedback = is_array($aiResponse['feedback'] ?? null) ? $aiResponse['feedback'] : [];
    foreach (['criteria_results', 'document_diff', 'reference_comparison', 'review', 'generated_rubric', 'grading_metadata', 'max_score'] as $key) {
        if (array_key_exists($key, $aiResponse)) $feedback[$key] = $aiResponse[$key];
    }
    return $feedback;
}

function assertAiGradingWorkerAvailable(): void
{
    if (!function_exists('curl_init')) {
        throw new AiGradingUnavailableException('PHP cURL chưa được bật nên không thể kiểm tra worker chấm AI.');
    }

    $handle = curl_init(aiServiceUrl('/health'));
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => aiServiceHeaders(),
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
    ]);
    $body = curl_exec($handle);
    $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    $health = is_string($body) ? json_decode($body, true) : null;
    if ($httpCode !== 200 || !is_array($health) || ($health['status'] ?? '') !== 'ok') {
        throw new AiGradingUnavailableException(
            'Dịch vụ chấm AI chưa sẵn sàng' . ($curlError !== '' ? ': ' . $curlError : '. Vui lòng thử lại sau.')
        );
    }
    if (empty($health['persistent_queue'])) {
        throw new AiGradingUnavailableException('Worker chấm AI đang tắt. Vui lòng báo Admin khởi động worker trước khi chấm.');
    }
}

function enqueueGradingJob(
    PDO $pdo,
    int $submissionId,
    int $assignmentId,
    int $studentId,
    string $moduleName,
    array $payload
): int {
    // Không tạo một tác vụ sẽ nằm chờ vô hạn khi API hoặc worker đang tắt.
    try {
        assertAiGradingWorkerAvailable();
    } catch (Throwable $error) {
        cleanupUnusedGradingPayload($payload);
        throw $error;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT id FROM grading_jobs
             WHERE submission_id = ? AND module_name = ? AND status IN ('queued','processing')
             ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$submissionId, $moduleName]);
        $existingId = $stmt->fetchColumn();
        if ($existingId) {
            $pdo->commit();
            cleanupUnusedGradingPayload($payload);
            return (int) $existingId;
        }
        $maxQueueSize = max(1, (int) envValue('AI_MAX_GRADE_QUEUE_SIZE', '50'));
        $queuedCount = (int) $pdo->query("SELECT COUNT(*) FROM grading_jobs WHERE status='queued'")->fetchColumn();
        if ($queuedCount >= $maxQueueSize) {
            throw new RuntimeException('Hàng đợi chấm AI đang đầy. Vui lòng thử lại sau.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO grading_jobs
             (submission_id, assignment_id, student_id, module_name, status, payload)
             VALUES (?, ?, ?, ?, 'queued', ?)"
        );
        $stmt->execute([
            $submissionId,
            $assignmentId,
            $studentId,
            mb_substr($moduleName, 0, 100),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $jobId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "UPDATE submissions SET grading_status = 'queued', grading_updated_at = NOW() WHERE id = ?"
        )->execute([$submissionId]);
        $pdo->commit();
        return $jobId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cleanupUnusedGradingPayload($payload);
        throw $error;
    }
}

function cleanupUnusedGradingPayload(array $payload): void
{
    $allowedRoot = realpath(__DIR__ . '/../uploads/temp_ai');
    if (!$allowedRoot) return;
    foreach (['prompt_local_path', 'submission_local_path'] as $key) {
        $candidate = $payload[$key] ?? null;
        if (!is_string($candidate) || $candidate === '') continue;
        $parent = realpath(dirname($candidate));
        if ($parent === $allowedRoot && is_file($candidate)) @unlink($candidate);
    }
}

function cancelActiveGradingJobs(PDO $pdo, int $submissionId, string $moduleName): void
{
    $stmt = $pdo->prepare(
        "UPDATE grading_jobs
         SET status='cancelled', completed_at=NOW(), worker_token=NULL,
             error_message='Bài nộp đã được thay đổi hoặc xóa.'
         WHERE submission_id=? AND module_name=? AND status IN ('queued','processing')"
    );
    $stmt->execute([$submissionId, $moduleName]);
}

function gradingJobForStudent(PDO $pdo, int $jobId, int $studentId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, submission_id, module_name, status, result_json, error_message,
                attempts, created_at, started_at, completed_at, result_applied_at
         FROM grading_jobs WHERE id = ? AND student_id = ? LIMIT 1'
    );
    $stmt->execute([$jobId, $studentId]);
    $job = $stmt->fetch();
    return $job ?: null;
}

function applyCompletedGradingJob(PDO $pdo, array $job): array
{
    if (($job['status'] ?? '') !== 'completed') return $job;
    $result = json_decode((string) ($job['result_json'] ?? ''), true);
    if (!is_array($result) || ($result['status'] ?? '') !== 'success') {
        throw new RuntimeException('Kết quả chấm AI không hợp lệ.');
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT * FROM grading_jobs WHERE id = ? FOR UPDATE');
        $lock->execute([(int) $job['id']]);
        $freshJob = $lock->fetch();
        if (!$freshJob) throw new RuntimeException('Không tìm thấy yêu cầu chấm.');
        $firstApply = empty($freshJob['result_applied_at']);

        $submissionStmt = $pdo->prepare(
            'SELECT module_scores, ai_feedback, grading_status FROM submissions WHERE id = ? FOR UPDATE'
        );
        $submissionStmt->execute([(int) $freshJob['submission_id']]);
        $submission = $submissionStmt->fetch();
        if (!$submission) throw new RuntimeException('Bài nộp không còn tồn tại.');

        $scores = json_decode((string) ($submission['module_scores'] ?? '{}'), true) ?: [];
        $feedback = json_decode((string) ($submission['ai_feedback'] ?? '{}'), true) ?: [];
        $moduleName = (string) $freshJob['module_name'];
        $maxScore = max(0, (float) ($result['max_score'] ?? 10));
        $scores[$moduleName] = max(0, min($maxScore, (float) ($result['score'] ?? 0)));
        $feedback[$moduleName] = buildStoredAiFeedback($result);
        $reviewRequired = !empty($result['review']['required'])
            || !empty($result['grading_metadata']['review_required'])
            || ($submission['grading_status'] ?? '') === 'review_required';

        $update = $pdo->prepare(
            'UPDATE submissions
             SET module_scores = ?, score = ?, ai_feedback = ?, grading_status = ?, grading_updated_at = NOW()
             WHERE id = ?'
        );
        $update->execute([
            json_encode($scores, JSON_UNESCAPED_UNICODE),
            array_sum($scores),
            json_encode($feedback, JSON_UNESCAPED_UNICODE),
            $reviewRequired ? 'review_required' : 'ai_graded',
            (int) $freshJob['submission_id'],
        ]);
        if ($firstApply) {
            $pdo->prepare('UPDATE grading_jobs SET result_applied_at = NOW() WHERE id = ?')
                ->execute([(int) $freshJob['id']]);
            $notification = $pdo->prepare(
                'INSERT INTO notifications (user_id, type, title, message, link, data_json)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $notification->execute([
                (int) $freshJob['student_id'],
                $reviewRequired ? 'grade_review_required' : 'grade_completed',
                $reviewRequired ? 'AI đã chấm – cần giáo viên kiểm tra' : 'AI đã chấm bài xong',
                "Phần {$moduleName} đã được chấm {$scores[$moduleName]}/{$maxScore} điểm.",
                '../student/assignment.php?id=' . (int) $freshJob['assignment_id'],
                json_encode([
                    'assignment_id' => (int) $freshJob['assignment_id'],
                    'submission_id' => (int) $freshJob['submission_id'],
                    'grading_job_id' => (int) $freshJob['id'],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }
        $pdo->commit();
        return [
            'score' => $scores[$moduleName],
            'total_score' => array_sum($scores),
            'max_score' => $maxScore,
            'feedback' => $feedback[$moduleName],
            'review_required' => $reviewRequired,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
