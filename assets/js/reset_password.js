$(document).ready(function() {
    $('#resetPasswordForm').on('submit', function(e) {
        e.preventDefault();
        
        const id = $('#id').val();
        const password = $('#password').val();
        const confirmPassword = $('#confirmPassword').val();
        
        // Clear previous error messages
        $('.error-message').text('');
        
        // Validate passwords match
        if (password !== confirmPassword) {
            $('#passwordError').text('Passwords do not match');
            return;
        }
        
        // Validate password strength
        if (password.length < 8) {
            $('#passwordError').text('Password must be at least 8 characters long');
            return;
        }
        
        // Show loading state
        $('#resetBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Resetting...');
        
        // Send reset request
        fetch('auth/reset_password.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                id: id,
                password: password
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Your password has been reset successfully.',
                    showConfirmButton: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'login.php';
                    }
                });
            } else {
                // Show error message
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.error || 'Failed to reset password. Please try again.'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An unexpected error occurred. Please try again.'
            });
        })
        .finally(() => {
            // Reset button state
            $('#resetBtn').prop('disabled', false).text('Reset Password');
        });
    });
}); 