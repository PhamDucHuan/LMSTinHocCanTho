<?php
declare(strict_types=1);

/**
 * Thư viện sinh HTML cho các mẫu Email của hệ thống LMS.
 */

/**
 * Trả về giao diện bọc ngoài (layout/wrapper) dùng chung cho mọi email.
 */
function get_email_layout(string $content): string {
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; background-color: #f8fafc; font-family: 'Segoe UI', Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #f8fafc; padding-bottom: 40px; }
        .main { background-color: #ffffff; margin: 0 auto; width: 100%; max-width: 600px; border-spacing: 0; color: #1e293b; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.03); margin-top: 30px; }
        .header { background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); padding: 30px; text-align: center; }
        .header h1 { margin: 0; color: #ffffff; font-size: 24px; font-weight: 700; letter-spacing: -0.5px; }
        .content { padding: 35px 35px 25px 35px; }
        .content p { font-size: 16px; line-height: 1.6; margin-top: 0; margin-bottom: 20px; color: #475569; }
        .footer { padding: 25px; text-align: center; background-color: #f1f5f9; border-top: 1px solid #e2e8f0; }
        .footer p { margin: 0; font-size: 13px; color: #64748b; line-height: 1.5; }
        .button { display: inline-block; background-color: #6366f1; color: #ffffff !important; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: 600; font-size: 15px; text-align: center; transition: all 0.2s; }
        .highlight-box { background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 18px 20px; margin-bottom: 25px; border-radius: 0 8px 8px 0; }
        .highlight-box p { margin: 0; color: #1e40af; font-weight: 600; }
        .highlight-box-danger { background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 18px 20px; margin-bottom: 25px; border-radius: 0 8px 8px 0; }
        .highlight-box-danger p { margin: 0; color: #991b1b; font-weight: 600; }
    </style>
</head>
<body>
    <center class="wrapper">
        <table class="main" width="100%">
            <tr>
                <td class="header">
                    <h1>LMS Tin Học Cần Thơ</h1>
                </td>
            </tr>
            <tr>
                <td class="content">
                    $content
                </td>
            </tr>
            <tr>
                <td class="footer">
                    <p>Trân trọng,<br><strong>Tin Học</strong></p>
                    <p style="margin-top:8px;">Đây là email được gửi tự động từ Hệ thống Quản lý Học tập LMS.<br>Vui lòng không trả lời email này.</p>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
HTML;
}

/**
 * Sinh nội dung HTML cho email Nhắc nhở Ngày thi.
 */
function get_exam_reminder_email_html(string $studentName, string $courseTitle, string $examDateStr, int $daysLeft, string $loginUrl = '#'): string {
    $urgencyStr = $daysLeft == 0 ? 'Hôm nay là ngày thi!' : "Chỉ còn $daysLeft ngày nữa";
    
    $content = <<<HTML
        <h2 style="margin-top: 0; margin-bottom: 20px; font-size: 20px; color: #1e293b;">Chào $studentName,</h2>
        <p>Hệ thống nhận thấy kỳ thi môn <strong>$courseTitle</strong> của bạn đang đến rất gần.</p>
        
        <div class="highlight-box">
            <p style="font-size: 18px; margin-bottom: 5px;">📅 Ngày thi dự kiến: $examDateStr</p>
            <p style="color: #2563eb; font-size: 15px;">⏳ $urgencyStr. Bạn đã ôn bài kỹ chưa?</p>
        </div>
        
        <p>Để đạt được kết quả tốt nhất trong kỳ thi sắp tới, chúng tôi khuyến nghị bạn nên:</p>
        <ul style="color: #475569; font-size: 15px; line-height: 1.6; padding-left: 20px; margin-bottom: 20px;">
            <li>Truy cập hệ thống LMS và ôn lại toàn bộ lý thuyết đã học.</li>
            <li>Hoàn thành các bài tập thực hành chưa làm.</li>
            <li>Thử sức với các bài thi trắc nghiệm mẫu (nếu có) để làm quen với cấu trúc đề.</li>
        </ul>
        
        <div style="background-color: #f8fafc; border: 1px dashed #cbd5e1; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: center;">
            <p style="margin: 0; color: #334155; font-style: italic;">"Thành công không đến từ sự may mắn, mà là kết quả của sự chuẩn bị kỹ lưỡng."</p>
            <p style="margin-top: 8px; margin-bottom: 0; color: #475569; font-weight: bold;">Chúc bạn ôn tập thật tốt, giữ tinh thần thoải mái và đạt điểm cao trong kỳ thi nhé! 🌟</p>
        </div>
        
        <div style="text-align: center; margin-top: 30px; margin-bottom: 10px;">
            <a href="$loginUrl" class="button">Đăng nhập Ôn tập ngay</a>
        </div>
HTML;

    return get_email_layout($content);
}

/**
 * Sinh nội dung HTML cho email Hạn chót bài tập (Deadline).
 */
function get_deadline_reminder_email_html(string $studentName, string $assignmentTitle, string $dueDateStr, string $assignmentUrl = '#'): string {
    $content = <<<HTML
        <h2 style="margin-top: 0; margin-bottom: 20px; font-size: 20px; color: #1e293b;">Chào $studentName,</h2>
        <p>Bạn có một bài tập chưa hoàn thành và hạn chót nộp bài đang đến rất gần.</p>
        
        <div class="highlight-box-danger">
            <p style="font-size: 18px; margin-bottom: 5px;">📝 Bài tập: $assignmentTitle</p>
            <p style="color: #b91c1c; font-size: 15px;">⏰ Hạn chót: $dueDateStr</p>
        </div>
        
        <p>Vui lòng sắp xếp thời gian hoàn thành và nộp bài trước hạn chót. Các bài nộp trễ sẽ bị trừ điểm theo quy định.</p>
        
        <div style="text-align: center; margin-top: 30px; margin-bottom: 10px;">
            <a href="$assignmentUrl" class="button" style="background-color: #ef4444;">Nộp bài ngay</a>
        </div>
HTML;

    return get_email_layout($content);
}

/**
 * Sinh nội dung HTML cho email Nhắc nhở Tham gia Khóa học (Engagement).
 */
function get_course_engagement_reminder_email_html(string $studentName, string $courseTitle, string $courseUrl = '#'): string {
    $content = <<<HTML
        <h2 style="margin-top: 0; margin-bottom: 20px; font-size: 20px; color: #1e293b;">Chào $studentName,</h2>
        <p>Hệ thống nhận thấy đã một thời gian bạn chưa quay lại tham gia học tập môn <strong>$courseTitle</strong>.</p>
        
        <p>Việc duy trì thói quen học tập đều đặn là chìa khóa quan trọng nhất để tiếp thu kiến thức hiệu quả và đạt kết quả cao trong các kỳ thi sắp tới.</p>
        
        <div class="highlight-box" style="border-left-color: #10b981; background-color: #ecfdf5;">
            <p style="font-size: 16px; margin-bottom: 8px; color: #047857;">💡 Bạn có biết?</p>
            <p style="color: #065f46; font-size: 14px; margin-bottom: 0;">Dành ra chỉ 30 phút mỗi ngày để ôn luyện sẽ giúp bạn nắm vững kiến thức hơn là học nhồi nhét vào sát ngày thi. Các bài giảng và bài tập mới đã được cập nhật đầy đủ trên hệ thống.</p>
        </div>
        
        <p><strong>Dưới đây là 3 bước đơn giản để bắt kịp tiến độ:</strong></p>
        <ul style="color: #475569; font-size: 15px; line-height: 1.6; padding-left: 20px; margin-bottom: 25px;">
            <li>Đăng nhập vào hệ thống LMS.</li>
            <li>Xem lại các bài giảng video hoặc tài liệu lý thuyết gần nhất.</li>
            <li>Hoàn thành ít nhất một bài tập hoặc bài trắc nghiệm đang mở.</li>
        </ul>
        
        <p>Đừng để bài vở tồn đọng quá nhiều nhé! Các thầy cô luôn sẵn sàng hỗ trợ bạn trên diễn đàn nếu có bất kỳ thắc mắc nào.</p>
        
        <div style="background-color: #f8fafc; border: 1px dashed #cbd5e1; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: center;">
            <p style="margin: 0; color: #334155; font-style: italic;">"Học tập là hạt giống của kiến thức, kiến thức là hạt giống của hạnh phúc."</p>
            <p style="margin-top: 8px; margin-bottom: 0; color: #475569; font-weight: bold;">Chúc bạn luôn giữ vững tinh thần học tập và đạt kết quả xuất sắc nhất! 🌟</p>
        </div>
        
        <div style="text-align: center; margin-top: 30px; margin-bottom: 10px;">
            <a href="$courseUrl" class="button" style="background-color: #10b981;">Tiếp tục học ngay</a>
        </div>
HTML;

    return get_email_layout($content);
}
