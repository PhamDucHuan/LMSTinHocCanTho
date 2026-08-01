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
