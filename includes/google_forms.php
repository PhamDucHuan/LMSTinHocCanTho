<?php
declare(strict_types=1);

/** @return array{url:string, html:string} */
function fetchPublicGoogleForm(string $inputUrl): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('Máy chủ chưa bật cURL để đọc Google Forms.');
    $url = trim($inputUrl);
    for ($redirect = 0; $redirect < 5; $redirect++) {
        validateGoogleFormHost($url);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LMS Google Forms preview)',
            CURLOPT_HTTPHEADER => ['Accept-Language: vi,en;q=0.8'],
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $redirectUrl = (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL);
        curl_close($curl);
        if ($response === false) throw new RuntimeException('Không thể mở Google Form: ' . ($error ?: 'lỗi kết nối.'));
        if ($status >= 300 && $status < 400 && $redirectUrl !== '') {
            $url = googleFormAbsoluteUrl($url, $redirectUrl);
            continue;
        }
        if ($status < 200 || $status >= 300) throw new RuntimeException('Google Form trả về lỗi HTTP ' . $status . '.');
        $html = substr((string) $response, $headerSize);
        if ($html === '') throw new RuntimeException('Google Form không trả về nội dung.');
        return ['url' => $url, 'html' => $html];
    }
    throw new RuntimeException('Link Google Form chuyển hướng quá nhiều lần.');
}

function validateGoogleFormHost(string $url): void
{
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, ['docs.google.com', 'forms.gle'], true)) {
        throw new RuntimeException('Chỉ chấp nhận link HTTPS từ docs.google.com/forms hoặc forms.gle.');
    }
}

function googleFormAbsoluteUrl(string $baseUrl, string $location): string
{
    if (preg_match('#^https://#i', $location)) return $location;
    $base = parse_url($baseUrl);
    if (str_starts_with($location, '//')) return 'https:' . $location;
    if (str_starts_with($location, '/')) return 'https://' . ($base['host'] ?? '') . $location;
    $path = (string) ($base['path'] ?? '/');
    return 'https://' . ($base['host'] ?? '') . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $location;
}

function googleFormSubmissionUrl(string $formUrl): string
{
    validateGoogleFormHost($formUrl);
    if (!preg_match('#^https://docs\.google\.com/forms/(?:u/\d+/)?d/(e/)?([^/]+)/(?:viewform|formResponse)(?:[/?]|\#|$)#i', $formUrl, $match)) {
        throw new RuntimeException('Link Google Form không hợp lệ để gửi dữ liệu.');
    }
    return 'https://docs.google.com/forms/d/' . ($match[1] !== '' ? 'e/' : '') . $match[2] . '/formResponse';
}

/**
 * @param array<string, list<string>> $answers
 * @return array{status:int}
 */
function submitPublicGoogleForm(string $formUrl, array $answers): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('Máy chủ chưa bật cURL để gửi Google Forms.');
    if (!$answers || count($answers) > 300) throw new RuntimeException('Không có dữ liệu hợp lệ để gửi.');

    $pairs = [];
    foreach ($answers as $entry => $values) {
        $isFormEntry = is_string($entry) && preg_match('/^entry\.\d+$/', $entry);
        $isCollectedEmail = $entry === 'emailAddress';
        if ((!$isFormEntry && !$isCollectedEmail) || !is_array($values) || count($values) > 100) {
            throw new RuntimeException('Dữ liệu trường Google Form không hợp lệ.');
        }
        foreach ($values as $value) {
            if (!is_scalar($value)) throw new RuntimeException('Giá trị gửi lên Google Form không hợp lệ.');
            $text = trim((string) $value);
            if ($text === '') continue;
            if (mb_strlen($text, 'UTF-8') > 10000) throw new RuntimeException('Một giá trị vượt quá độ dài cho phép.');
            $pairs[] = rawurlencode($entry) . '=' . rawurlencode($text);
        }
    }
    if (!$pairs) throw new RuntimeException('Không có dữ liệu hợp lệ để gửi.');
    $pairs[] = 'fvv=1';
    $pairs[] = 'draftResponse=%5B%5D';
    $pairs[] = 'pageHistory=0';
    $pairs[] = 'submit=Submit';

    $curl = curl_init(googleFormSubmissionUrl($formUrl));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 7,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => implode('&', $pairs),
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LMS Google Forms submitter)',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: vi,en;q=0.8',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        ],
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $redirectUrl = (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL);
    curl_close($curl);

    if ($response === false) throw new RuntimeException('Không thể kết nối Google Forms: ' . ($error ?: 'lỗi không xác định.'));
    if ($status < 200 || $status >= 400) throw new RuntimeException('Google Forms từ chối yêu cầu gửi (HTTP ' . $status . ').');
    if ($status >= 300 && $redirectUrl !== '') {
        $redirectHost = strtolower((string) (parse_url($redirectUrl, PHP_URL_HOST) ?? ''));
        if ($redirectHost !== '' && $redirectHost !== 'docs.google.com') {
            throw new RuntimeException('Google Form yêu cầu đăng nhập hoặc xác minh trên trang Google. Hãy dùng nút mở Form để gửi thủ công.');
        }
    }
    $body = mb_strtolower(strip_tags((string) $response), 'UTF-8');
    if (str_contains($body, 'you need permission') || str_contains($body, 'cần có quyền') || str_contains($body, 'no longer accepting responses') || str_contains($body, 'không còn nhận câu trả lời')) {
        throw new RuntimeException('Google Form đang giới hạn quyền truy cập hoặc đã ngừng nhận câu trả lời.');
    }
    return ['status' => $status];
}

/** @return array{title:string, url:string, fields:list<array<string,mixed>>, skipped:list<string>} */
function parsePublicGoogleForm(string $html, string $sourceUrl): array
{
    if (!preg_match('/FB_PUBLIC_LOAD_DATA_\s*=\s*(\[.*?\]);\s*<\/script>/s', $html, $match)) {
        throw new RuntimeException('Không đọc được câu hỏi. Hãy kiểm tra Form đang công khai và không bắt buộc đăng nhập.');
    }
    $data = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    $form = $data[1] ?? null;
    $items = is_array($form) && isset($form[1]) && is_array($form[1]) ? $form[1] : [];
    $title = is_array($form) && is_string($form[8] ?? null) ? trim($form[8]) : 'Google Form';
    $typeLabels = [0 => 'Câu trả lời ngắn', 1 => 'Đoạn văn', 2 => 'Trắc nghiệm', 3 => 'Danh sách', 4 => 'Hộp kiểm', 5 => 'Thang điểm', 7 => 'Lưới', 9 => 'Ngày', 10 => 'Thời gian'];
    $fields = [];
    $skipped = [];
    $seen = [];

    // Email do Google Forms thu thập nằm ngoài danh sách câu hỏi entry.*.
    // Với chế độ email đã xác minh, Google khóa ô này và tự lấy từ tài khoản
    // đang đăng nhập; vẫn cần đưa lên giao diện để người dùng thấy đủ trường.
    if (preg_match('/<input\b(?=[^>]*\btype\s*=\s*["\']email["\'])[^>]*>/i', $html, $emailInputMatch)) {
        $emailInput = $emailInputMatch[0];
        $googleManaged = preg_match('/\sdisabled(?:\s|=|>)/i', $emailInput) === 1
            || preg_match('/\baria-disabled\s*=\s*["\']true["\']/i', $emailInput) === 1;
        $fields[] = [
            'entry' => 'emailAddress',
            'label' => $googleManaged ? 'Email tài khoản Google' : 'Email do Google Forms thu thập',
            'mapping_label' => 'Email',
            'type' => 0,
            'type_label' => $googleManaged ? 'Google tự lấy từ tài khoản đang đăng nhập' : 'Email hệ thống của Google Forms',
            'required' => preg_match('/\srequired(?:\s|=|>)/i', $emailInput) === 1,
            'multiple' => false,
            'options' => [],
            'google_managed' => $googleManaged,
        ];
        $seen['emailAddress'] = true;
    }
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $question = trim((string) ($item[1] ?? 'Câu hỏi'));
        $type = (int) ($item[3] ?? -1);
        // 6/8 are title or section blocks; 11/12 are image or video blocks.
        if (in_array($type, [6, 8, 11, 12], true)) continue;
        if ($type === 13) {
            $skipped[] = $question . ' (tải tệp không thể điền sẵn)';
            continue;
        }
        $entries = isset($item[4]) && is_array($item[4]) ? $item[4] : [];
        foreach ($entries as $entryIndex => $entry) {
            if (!is_array($entry) || !isset($entry[0]) || !is_numeric($entry[0])) continue;
            $entryId = (string) $entry[0];
            if (isset($seen[$entryId])) continue;
            $seen[$entryId] = true;
            $rowLabel = is_string($entry[3] ?? null) ? trim($entry[3]) : '';
            $label = $question . ($rowLabel !== '' ? ' — ' . $rowLabel : (count($entries) > 1 ? ' — dòng ' . ($entryIndex + 1) : ''));
            $options = [];
            if (isset($entry[1]) && is_array($entry[1])) {
                foreach ($entry[1] as $option) {
                    if (is_array($option) && isset($option[0]) && is_scalar($option[0])) $options[] = (string) $option[0];
                }
            }
            $fields[] = [
                'entry' => 'entry.' . $entryId,
                'label' => $label,
                'type' => $type,
                'type_label' => $typeLabels[$type] ?? 'Trường nhập',
                'required' => (int) ($entry[2] ?? 0) === 1,
                'multiple' => $type === 4,
                'options' => array_values(array_unique($options)),
            ];
        }
    }
    if (!$fields) throw new RuntimeException('Form không có trường nào có thể điền sẵn.');
    if (!preg_match('#^https://docs\.google\.com/forms/(?:u/\d+/)?d/(e/)?([^/]+)/(?:viewform|edit|formResponse)#i', $sourceUrl, $urlMatch)) {
        throw new RuntimeException('Không nhận dạng được địa chỉ Google Form sau khi chuyển hướng.');
    }
    $canonicalUrl = 'https://docs.google.com/forms/d/' . ($urlMatch[1] !== '' ? 'e/' : '') . $urlMatch[2] . '/viewform';
    return ['title' => $title ?: 'Google Form', 'url' => $canonicalUrl, 'fields' => $fields, 'skipped' => $skipped];
}
