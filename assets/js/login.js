// Auto-dismiss alerts after specified duration
document.addEventListener('DOMContentLoaded', function() {
    // Handle logout success message
    const logoutAlert = document.getElementById('logoutAlert');
    if (logoutAlert) {
        autoDismissAlert(logoutAlert, 3000); // 3 seconds for success message
    }

    // Handle error messages
    const errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        autoDismissAlert(errorAlert, 3000); // Changed to 3 seconds for error message
    }

    const recoveryForm = document.getElementById('recoveryForm');
    const recoveryStep2 = document.getElementById('recoveryStep2');
    const recoveryError = document.getElementById('recoveryError');
    const recoverySuccess = document.getElementById('recoverySuccess');
    const recoverySubmitBtn = document.getElementById('recoverySubmitBtn');

    if (recoveryForm) {
        recoveryForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // If step 2 is visible, handle password change
            if (recoveryStep2.style.display !== 'none') {
                const id = document.getElementById('recoveryId').value.trim();
                const dob = document.getElementById('recoveryDob').value.trim();
                const newPassword = document.getElementById('newPassword').value.trim();
                const confirmPassword = document.getElementById('confirmPassword').value.trim();

                if (newPassword.length < 6) {
                    recoveryError.textContent = 'Password must be at least 6 characters.';
                    recoveryError.classList.remove('d-none');
                    return;
                }
                if (newPassword !== confirmPassword) {
                    recoveryError.textContent = 'Passwords do not match.';
                    recoveryError.classList.remove('d-none');
                    return;
                }

                fetch('recover_account.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'change_password',
                        id: id,
                        dob: dob,
                        new_password: newPassword
                    })
                })
                .then(res => res.json())
                .then(data => {
                    console.log('Recovery change_password response:', data); // Debug log
                    if (data.success) {
                        recoveryError.classList.add('d-none');
                        recoverySuccess.textContent = data.message;
                        recoverySuccess.classList.remove('d-none');
                        recoveryStep2.style.display = 'none';
                        recoverySubmitBtn.disabled = true;
                    } else {
                        recoveryError.textContent = data.message;
                        recoveryError.classList.remove('d-none');
                    }
                });
                return;
            }

            // Step 1: verify ID and DOB
            const id = document.getElementById('recoveryId').value.trim();
            const dob = document.getElementById('recoveryDob').value.trim();

            fetch('recover_account.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'verify',
                    id: id,
                    dob: dob
                })
            })
            .then(res => res.json())
            .then(data => {
                console.log('Recovery verify response:', data); // Debug log
                if (data.success) {
                    recoveryError.classList.add('d-none');
                    recoveryStep2.style.display = '';
                    recoverySubmitBtn.textContent = 'Change Password';
                } else {
                    recoveryError.textContent = data.message;
                    recoveryError.classList.remove('d-none');
                }
            });
        });
    }
});

// Function to handle auto-dismissing alerts
function autoDismissAlert(alert, duration) {
    // Add show class to trigger pop-in animation
    alert.classList.add('show');
    
    // Set timeout for fade-out
    setTimeout(function() {
        alert.classList.add('fade-out');
        // Remove the alert after animation completes
        alert.addEventListener('animationend', function() {
            alert.remove();
        });
    }, duration);
}

// Show loading screen when form is submitted
document.querySelector('form').addEventListener('submit', function() {
    document.querySelector('.loading-container').classList.remove('hide');
});

// Hide loading screen when page loads
window.addEventListener('load', function() {
    setTimeout(function() {
        document.querySelector('.loading-container').classList.add('hide');
    }, 1000); // Hide after 1 second
});

// Hide loading screen when back button is used
window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        document.querySelector('.loading-container').classList.add('hide');
    }
});

// Toggle password visibility
document.addEventListener('DOMContentLoaded', function() {
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    console.log('Toggle password element:', togglePassword); // Debug log
    console.log('Password input element:', passwordInput); // Debug log
    
    if (togglePassword && passwordInput) {
        console.log('Both elements found, setting up event listeners'); // Debug log
        // Show/hide toggle icon based on input
        passwordInput.addEventListener('input', function() {
            // Keep the toggle visible at all times
            togglePassword.style.display = 'flex';
        });

        // Toggle password visibility
        togglePassword.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Password toggle clicked!'); // Debug log
            
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            console.log('Password type changed to:', type); // Debug log
            
            // Toggle eye icon
            const eyeIcon = this.querySelector('svg');
            if (type === 'password') {
                // Show the "eye" icon (password is hidden)
                eyeIcon.innerHTML = '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>';
            } else {
                // Show the "eye-slash" icon (password is visible)
                eyeIcon.innerHTML = '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/>';
            }
        });
    } else {
        console.log('Elements not found - togglePassword:', togglePassword, 'passwordInput:', passwordInput);
    }
});

// Global JS error alert for debugging
window.onerror = function(message, source, lineno, colno, error) {
    alert('JS Error: ' + message + '\n' + source + ':' + lineno);
}; 