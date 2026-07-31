# Thiết lập bảo mật

1. Sao chép `.env.example` thành `.env` và điền thông tin mới. Không dùng lại các bí mật từng nằm trong mã nguồn.
2. Đổi mật khẩu database và giới hạn tài khoản DB chỉ được truy cập đúng database LMS.
3. Thu hồi Google OAuth client secret và Drive refresh token cũ, sau đó tạo lại.
4. Đặt `GOOGLE_DRIVE_TOKEN_PATH` trỏ tới file nằm ngoài `htdocs`.
5. Dùng cùng một giá trị ngẫu nhiên dài cho `AI_SERVICE_KEY` trong `.env` của PHP và tiến trình FastAPI.
6. Bật Apache `mod_rewrite` và `mod_headers`; xác nhận `.htaccess` được phép hoạt động (`AllowOverride All`).
7. Chạy `init.sql` cho database mới. Với database cũ, sao lưu trước rồi bổ sung unique key `(assignment_id, student_id)` sau khi xử lý bản ghi trùng.
8. Khởi chạy AI bằng tài khoản hệ điều hành ít quyền và không mở cổng 8000 ra Internet.

`setup_drive_token.php` chỉ được phép truy cập từ localhost để cấp lại Drive token. Schema mới được quản lý tập trung trong `init.sql`.
