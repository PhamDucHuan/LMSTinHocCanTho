<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

function resolveLocalDrivePath(string $fileId): ?string {
    if (!str_starts_with($fileId, 'local_')) return null;
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    $candidate = realpath(__DIR__ . '/../uploads/' . ltrim(substr($fileId, 6), '/\\'));
    if (!$uploadsRoot || !$candidate || !str_starts_with($candidate, $uploadsRoot . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $candidate;
}

function getDriveClient() {
    $clientID = envValue('GOOGLE_CLIENT_ID', '');
    $clientSecret = envValue('GOOGLE_CLIENT_SECRET', '');
    if ($clientID === '' || $clientSecret === '') {
        throw new RuntimeException('Google Drive chưa được cấu hình.');
    }

    $client = new Google_Client();
    $client->setClientId($clientID);
    $client->setClientSecret($clientSecret);
    $client->addScope(Google_Service_Drive::DRIVE);
    
    $tokenPath = envValue('GOOGLE_DRIVE_TOKEN_PATH', __DIR__ . '/../config/drive_token.json');
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }
    
    // Nếu token hết hạn, refresh lại
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        } else {
            throw new Exception('Lỗi: Không tìm thấy Refresh Token. Vui lòng chạy ' . appUrl('setup_drive_token.php') . ' để thiết lập lại.');
        }
    }
    return $client;
}

function getOrCreateFolder($service, $folderNames) {
    if (!is_array($folderNames)) {
        $folderNames = [$folderNames];
    }
    
    $parentId = null;
    foreach ($folderNames as $folderName) {
        $query = "mimeType='application/vnd.google-apps.folder' and name='$folderName' and trashed=false";
        if ($parentId) {
            $query .= " and '$parentId' in parents";
        }
        $optParams = [
            'q' => $query,
            'spaces' => 'drive',
            'fields' => 'files(id, name)'
        ];
        $results = $service->files->listFiles($optParams);
        $files = $results->getFiles();
        
        if (count($files) == 0) {
            $folderMetadata = new Google_Service_Drive_DriveFile([
                'name' => $folderName,
                'mimeType' => 'application/vnd.google-apps.folder'
            ]);
            if ($parentId) {
                $folderMetadata->setParents([$parentId]);
            }
            $folder = $service->files->create($folderMetadata, ['fields' => 'id']);
            $parentId = $folder->id;
        } else {
            $parentId = $files[0]->id;
        }
    }
    return $parentId;
}

/**
 * Uploads a file to Google Drive and makes it public accessible
 * @param string $filePath Đường dẫn local của file (VD: file tmp từ $_FILES)
 * @param string $fileName Tên file sẽ hiển thị trên Drive
 * @param array $folderNames Cấu trúc thư mục con
 * @return string File ID trên Google Drive
 */
function uploadToDrive($filePath, $fileName, $folderNames = ['LMS_Uploads']) {
    $client = getDriveClient();
    $service = new Google_Service_Drive($client);
    
    // Tự động tìm hoặc tạo cấu trúc thư mục
    $folderId = getOrCreateFolder($service, $folderNames);
    
    $fileMetadata = new Google_Service_Drive_DriveFile([
        'name' => $fileName,
        'parents' => [$folderId]
    ]);
    
    $content = file_get_contents($filePath);
    // Lấy mimetype thực sự hoặc default
    $mimeType = (function_exists('mime_content_type') && file_exists($filePath)) ? mime_content_type($filePath) : 'application/octet-stream';
    if (!$mimeType) $mimeType = 'application/octet-stream';
    
    $file = $service->files->create($fileMetadata, [
        'data' => $content,
        'mimeType' => $mimeType,
        'uploadType' => 'multipart',
        'fields' => 'id'
    ]);
    
    // Cấp quyền chia sẻ công khai (bất kỳ ai có link đều xem/tải được)
    // Để khi hiển thị trên web, cả giáo viên và học sinh đều có thể bấm tải.
    try {
        if (filter_var(envValue('GOOGLE_DRIVE_PUBLIC_FILES', 'false'), FILTER_VALIDATE_BOOLEAN) !== true) {
            return $file->id;
        }
        $permission = new Google_Service_Drive_Permission([
            'type' => 'anyone',
            'role' => 'reader'
        ]);
        $service->permissions->create($file->id, $permission);
    } catch(Exception $e) {
        // Bỏ qua nếu có lỗi set quyền
    }
    
    return $file->id;
}

/**
 * Xóa file khỏi Google Drive
 * @param string $fileId ID của file trên Google Drive (hoặc file local)
 * @return bool True nếu xóa thành công, False nếu thất bại
 */
function deleteFromDrive($fileId) {
    if (!$fileId) return true;
    
    // Nếu là file lưu trữ cục bộ (local_)
    if (strpos($fileId, 'local_') === 0) {
        $localPath = resolveLocalDrivePath((string) $fileId);
        if ($localPath && is_file($localPath)) {
            @unlink($localPath);
        }
        return true;
    }
    
    // Xóa trên Google Drive
    try {
        $client = getDriveClient();
        $service = new Google_Service_Drive($client);
        if (filter_var(envValue('GOOGLE_DRIVE_PERMANENT_DELETE', 'false'), FILTER_VALIDATE_BOOLEAN)) {
            $service->files->delete($fileId);
        } else {
            $service->files->update($fileId, new Google_Service_Drive_DriveFile(['trashed' => true]));
        }
        return true;
    } catch (Exception $e) {
        error_log("Lỗi xóa file khỏi Drive ($fileId): " . $e->getMessage());
        return false;
    }
}

/**
 * Tải file từ Google Drive về thư mục tạm cục bộ
 * @param string $fileId ID của file trên Google Drive (hoặc file local)
 * @param string $destPath Đường dẫn file đích (local)
 * @return bool True nếu tải thành công
 */
function downloadFromDrive($fileId, $destPath) {
    if (!$fileId) return false;
    
    // Đảm bảo thư mục đích tồn tại
    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    
    // Nếu là file lưu trữ cục bộ (local_)
    if (strpos($fileId, 'local_') === 0) {
        $localPath = resolveLocalDrivePath((string) $fileId);
        if ($localPath && is_file($localPath)) {
            return copy($localPath, $destPath);
        }
        return false;
    }
    
    // Tải từ Google Drive
    try {
        $client = getDriveClient();
        $service = new Google_Service_Drive($client);
        $response = $service->files->get($fileId, ['alt' => 'media']);
        $content = $response->getBody()->getContents();
        file_put_contents($destPath, $content);
        return true;
    } catch (Exception $e) {
        error_log("Lỗi tải file từ Drive ($fileId): " . $e->getMessage());
        return false;
    }
}
?>
