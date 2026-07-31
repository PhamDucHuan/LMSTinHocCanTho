<?php
declare(strict_types=1);

function friendlySlug(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = strtr($value, [
        'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
        'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
        'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
        'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
        'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
        'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d',
    ]);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = $ascii !== false ? $ascii : $value;
    $value = preg_replace('/[^a-z0-9]+/', '-', strtolower($value));
    return trim((string) $value, '-') ?: 'noi-dung';
}

function ensureFriendlyUrls(PDO $pdo): void
{
    // Schema và dữ liệu cũ được xử lý bởi database/migrate.php.
}

function uniqueFriendlySlug(PDO $pdo, string $table, string $title): string
{
    if (!in_array($table, ['courses', 'assignments', 'quizzes'], true)) {
        throw new InvalidArgumentException('Bảng không hỗ trợ đường dẫn thân thiện.');
    }
    $base = friendlySlug($title);
    $slug = $base;
    $suffix = 2;
    $exists = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE slug = ? LIMIT 1");
    while (true) {
        $exists->execute([$slug]);
        if (!$exists->fetchColumn()) return $slug;
        $slug = $base . '-' . $suffix++;
    }
}

function friendlyUrl(string $page, string $parameter, string $slug): string
{
    return $page . '?' . rawurlencode($parameter) . '=' . rawurlencode($slug);
}
