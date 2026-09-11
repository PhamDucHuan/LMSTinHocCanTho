document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const authBox = document.querySelector('.auth-box');
    const showRegisterBtn = document.getElementById('show-register');
    const showLoginBtn = document.getElementById('show-login');

    if (authBox && showRegisterBtn && showLoginBtn) {
        showRegisterBtn.addEventListener('click', (e) => {
            e.preventDefault();
            authBox.classList.add('register-mode');
            loginForm.classList.remove('active-form');
            registerForm.classList.add('active-form');
            registerForm.querySelector('input:not([type="hidden"])')?.focus({ preventScroll: true });
        });

        showLoginBtn.addEventListener('click', (e) => {
            e.preventDefault();
            authBox.classList.remove('register-mode');
            registerForm.classList.remove('active-form');
            loginForm.classList.add('active-form');
            loginForm.querySelector('input:not([type="hidden"])')?.focus({ preventScroll: true });
        });
    }

    if (registerForm) {
        const feedback = document.getElementById('register-feedback');
        const submitButton = registerForm.querySelector('button[type="submit"]');
        const nameInput = registerForm.elements.name;
        const emailInput = registerForm.elements.email;
        const passwordInput = registerForm.elements.password;
        const originalButtonContent = submitButton?.innerHTML || '';

        const clearFieldError = (input) => {
            input?.closest('.input-group')?.classList.remove('has-error');
            input?.removeAttribute('aria-invalid');
        };

        const showWarning = (messages, fieldName = null) => {
            const items = Array.isArray(messages) ? messages : [messages];
            feedback.replaceChildren();
            if (items.length === 1) {
                feedback.textContent = items[0];
            } else {
                const list = document.createElement('ul');
                items.forEach((message) => {
                    const item = document.createElement('li');
                    item.textContent = message;
                    list.append(item);
                });
                feedback.append(list);
            }
            feedback.hidden = false;
            const field = fieldName ? registerForm.elements[fieldName] : null;
            if (field) {
                field.closest('.input-group')?.classList.add('has-error');
                field.setAttribute('aria-invalid', 'true');
                field.focus({preventScroll: true});
            }
        };

        const clearWarning = () => {
            feedback.hidden = true;
            feedback.replaceChildren();
        };

        [nameInput, emailInput, passwordInput].forEach((input) => {
            input?.addEventListener('input', () => {
                clearFieldError(input);
                if (![nameInput, emailInput, passwordInput].some((item) => item?.getAttribute('aria-invalid') === 'true')) clearWarning();
            });
        });

        registerForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            clearWarning();
            [nameInput, emailInput, passwordInput].forEach(clearFieldError);

            nameInput.value = nameInput.value.trim();
            emailInput.value = emailInput.value.trim();
            const problems = [];
            let firstInvalidField = null;
            const addProblem = (message, input) => {
                problems.push(message);
                input.closest('.input-group')?.classList.add('has-error');
                input.setAttribute('aria-invalid', 'true');
                firstInvalidField ||= input;
            };

            if (!nameInput.value) addProblem('Vui lòng nhập họ và tên.', nameInput);
            if (!emailInput.value) addProblem('Vui lòng nhập địa chỉ email.', emailInput);
            else if (emailInput.validity.typeMismatch) addProblem('Địa chỉ email không đúng định dạng.', emailInput);
            if (!passwordInput.value) addProblem('Vui lòng nhập mật khẩu.', passwordInput);
            else if (passwordInput.value.length < 8) addProblem('Mật khẩu phải có ít nhất 8 ký tự.', passwordInput);

            if (problems.length) {
                showWarning(problems);
                firstInvalidField?.focus({preventScroll: true});
                return;
            }

            submitButton.disabled = true;
            submitButton.classList.add('is-loading');
            submitButton.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Đang kiểm tra...";

            try {
                const response = await fetch(registerForm.action, {
                    method: 'POST',
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                    body: new FormData(registerForm),
                    credentials: 'same-origin'
                });
                const responseText = await response.text();
                let result = null;
                try { result = JSON.parse(responseText); } catch (error) { /* Phản hồi lỗi cũ dạng văn bản. */ }
                if (!response.ok || !result?.ok) {
                    const plainResponse = responseText.trim().startsWith('<') ? '' : responseText.trim();
                    throw Object.assign(new Error(result?.message || plainResponse || 'Không thể đăng ký lúc này. Vui lòng thử lại.'), {field: result?.field || null});
                }
                window.location.assign(result.redirect || 'pending_approval.php');
            } catch (error) {
                showWarning(error.message || 'Không thể kết nối máy chủ. Vui lòng thử lại.', error.field);
                submitButton.disabled = false;
                submitButton.classList.remove('is-loading');
                submitButton.innerHTML = originalButtonContent;
            }
        });
    }

    // Input animation enhancement
    const inputs = document.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'scale(1.02)';
            this.parentElement.style.transition = 'transform 0.3s ease';
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'scale(1)';
        });
    });
});
