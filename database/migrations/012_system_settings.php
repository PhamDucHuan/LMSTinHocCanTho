<?php

return static function (PDO $pdo): void {
    // Create system_settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        `key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `value` TEXT NULL,
        `label` VARCHAR(255) NOT NULL DEFAULT '',
        `type` ENUM('text','number','bool','url','color','textarea') NOT NULL DEFAULT 'text',
        `group_name` VARCHAR(80) NOT NULL DEFAULT 'general',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Insert default settings
    $defaults = [
        ['site_name',             'LMS Tin học Cần Thơ',        'Tên hệ thống',                      'text',    'general'],
        ['site_description',      'Hệ thống học tập trực tuyến','Mô tả hệ thống',                    'textarea','general'],
        ['admin_email',           '',                            'Email liên hệ Admin',               'text',    'general'],
        ['max_upload_mb',         '50',                          'Dung lượng upload tối đa (MB)',      'number',  'general'],
        ['allow_google_login',    '1',                           'Cho phép đăng nhập Google',          'bool',    'security'],
        ['registration_enabled',  '1',                           'Cho phép đăng ký tài khoản mới',    'bool',    'security'],
        ['require_approval',      '1',                           'Cần Admin duyệt tài khoản mới',     'bool',    'security'],
        ['maintenance_mode',      '0',                           'Chế độ bảo trì (chặn đăng nhập)',   'bool',    'security'],
        ['session_timeout_minutes','120',                        'Thời gian hết phiên (phút)',         'number',  'security'],
        ['ai_chat_enabled',       '1',                           'Bật trợ lý AI chat cho học viên',   'bool',    'features'],
        ['notifications_enabled', '1',                           'Bật thông báo thời gian thực',      'bool',    'features'],
        ['grade_export_enabled',  '1',                           'Cho phép giáo viên xuất bảng điểm', 'bool',    'features'],
    ];

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO system_settings (`key`, `value`, `label`, `type`, `group_name`) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($defaults as $row) {
        $stmt->execute($row);
    }

    // Add user_agent column to audit_logs if not exists
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM audit_logs') as $col) {
        $cols[$col['Field']] = true;
    }
    if (!isset($cols['user_agent'])) {
        $pdo->exec("ALTER TABLE audit_logs ADD COLUMN user_agent VARCHAR(500) NULL AFTER ip_address");
    }
};
