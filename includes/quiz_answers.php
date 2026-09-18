<?php
declare(strict_types=1);

/**
 * Chuẩn hóa đáp án đúng về dạng A,B,C,D (không khoảng trắng, không trùng lặp).
 * Chấp nhận chuỗi như "A,D", "A; D", "A D" hoặc mảng từ checkbox.
 */
function quizNormalizeAnswerOptions(mixed $value): string
{
    $raw = is_array($value) ? implode(',', $value) : (string) $value;
    $raw = strtoupper(trim($raw));
    if ($raw === '') return '';

    $compact = preg_replace('/[\s,;|\/+]+/u', '', $raw);
    if ($compact === '' || preg_match('/[^A-D]/', $compact)) return '';

    $letters = array_values(array_unique(str_split($compact)));
    sort($letters, SORT_STRING);
    return implode(',', $letters);
}

function quizAnswerOptions(mixed $value): array
{
    $normalized = quizNormalizeAnswerOptions($value);
    return $normalized === '' ? [] : explode(',', $normalized);
}

function quizAnswerIsCorrect(mixed $selected, mixed $correct): bool
{
    $selectedNormalized = quizNormalizeAnswerOptions($selected);
    $correctNormalized = quizNormalizeAnswerOptions($correct);
    return $selectedNormalized !== '' && $selectedNormalized === $correctNormalized;
}

function quizHasMultipleCorrectOptions(mixed $correct): bool
{
    return count(quizAnswerOptions($correct)) > 1;
}
