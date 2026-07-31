# Triển khai LMS

## Yêu cầu

- PHP 8.1 trở lên với PDO MySQL, cURL, fileinfo, ZIP và mbstring.
- MySQL 8 hoặc MariaDB 10.5 trở lên.
- Node.js 20 trở lên.
- HTTPS cho đăng nhập Google.

## Cài đặt

1. Sao chép `.env.example` thành `.env` và nhập thông tin thật.
2. Chạy Composer:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. Với bản cài mới, nhập `init.sql` trước. Sau đó áp dụng mọi migration:

   ```bash
   mysql -u USER -p DATABASE_NAME < init.sql
   php database/migrate.php
   ```

4. Cài và chạy dịch vụ AI:

   ```bash
   cd ai_service
   npm ci --omit=dev
   pm2 start ecosystem.config.cjs
   pm2 save
   ```

5. Kiểm tra dịch vụ bằng endpoint `/health` với header `X-API-Key`.

## Cron bảo trì

Chạy mỗi ngày để xóa file tạm, token và dữ liệu cũ:

```cron
15 2 * * * /usr/bin/php /home/USER/public_html/LMS/maintenance/cleanup.php
```

## Sau mỗi lần cập nhật

```bash
php database/migrate.php
cd ai_service
npm ci --omit=dev
pm2 restart lms-ai
```

Không đưa `.env`, `drive_token.json`, thư mục `uploads/temp_ai` hoặc log lên Git.
