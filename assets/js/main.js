document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const showRegisterBtn = document.getElementById('show-register');
    const showLoginBtn = document.getElementById('show-login');

    if (showRegisterBtn && showLoginBtn) {
        showRegisterBtn.addEventListener('click', (e) => {
            e.preventDefault();
            loginForm.classList.remove('active-form');
            setTimeout(() => {
                loginForm.style.display = 'none';
                registerForm.style.display = 'block';
                setTimeout(() => {
                    registerForm.classList.add('active-form');
                }, 50);
            }, 400); // Wait for transition
        });

        showLoginBtn.addEventListener('click', (e) => {
            e.preventDefault();
            registerForm.classList.remove('active-form');
            setTimeout(() => {
                registerForm.style.display = 'none';
                loginForm.style.display = 'block';
                setTimeout(() => {
                    loginForm.classList.add('active-form');
                }, 50);
            }, 400); // Wait for transition
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
