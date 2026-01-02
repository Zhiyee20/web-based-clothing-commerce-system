// ============================================================================
// General Functions
// ============================================================================


// // ============================================================================
// // Page Load (jQuery)
// // ============================================================================

   // Autofocus
   $('form :input:not(button):first').focus();
   $('.err:first').prev().focus();
   $('.err:first').prev().find(':input:first').focus();
   
   // Confirmation message
   $('[data-confirm]').on('click', e => {
       const text = e.target.dataset.confirm || 'Are you sure?';
       if (!confirm(text)) {
           e.preventDefault();
           e.stopImmediatePropagation();
       }
   });

    // ================== Password Validation ==================
    $(document).ready(function() {
        function updatePasswordValidation() {
            let password = $('#password').val();
            let confirmPassword = $('#confirm_password').val();
    
            let lengthValid = password.length >= 8;
            let uppercaseValid = /[A-Z]/.test(password);
            let numberValid = /\d/.test(password);
            let symbolValid = /[\W]/.test(password);
    
            $('#length .status').text(lengthValid ? '✅' : '❌').css('color', lengthValid ? 'green' : 'red');
            $('#uppercase .status').text(uppercaseValid ? '✅' : '❌').css('color', uppercaseValid ? 'green' : 'red');
            $('#number .status').text(numberValid ? '✅' : '❌').css('color', numberValid ? 'green' : 'red');
            $('#symbol .status').text(symbolValid ? '✅' : '❌').css('color', symbolValid ? 'green' : 'red');
    
            if (confirmPassword !== "") {
                if (password === confirmPassword) {
                    $('#confirm-password-validation').text('✅').css('color', 'green');
                } else {
                    $('#confirm-password-validation').text('❌').css('color', 'red');
                }
            } else {
                $('#confirm-password-validation').text('');
            }
        }
    
        $('#password, #confirm_password').on('input', updatePasswordValidation);
    });
    


// Confirmation for cancel button
$(document).ready(function() {
    // ================== Discard Changes Confirmation ==================
    $('.cancel-btn').on('click', function(e) {
        e.preventDefault();
        const confirmDiscard = confirm('Discard changes?');
        if (confirmDiscard) {
            window.history.back(); // Navigate back without saving
        }
    });
});


function deleteAccount() {
    const deleteBtn = document.getElementById("delete-account");
    if (!deleteBtn) return;

    const userId = deleteBtn.dataset.userid;

    // Small click feedback
    deleteBtn.style.opacity = "0.7";
    deleteBtn.style.transform = "scale(0.98)";
    setTimeout(() => {
        deleteBtn.style.opacity = "1";
        deleteBtn.style.transform = "scale(1)";
    }, 150);

    if (!confirm("Are you sure you want to delete your account? This action is irreversible!")) {
        return;
    }

    fetch('delete_user.php', {   // <── here: NO ../
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'UserID=' + encodeURIComponent(userId)
    })
    .then(response => response.text())
    .then(text => {
        const trimmed = text.trim();
        console.log('delete_user.php response:', trimmed);

        // Your PHP echoes: "User deleted successfully"
        if (trimmed.startsWith('User deleted successfully')) {
            alert("Your account has been deleted.");
            // user is already logged out in PHP, just send them home
            window.location.href = '../index.php';
        } else {
            alert("Failed to delete account:\n" + trimmed);
        }
    })
    .catch(err => {
        alert("Error deleting account. Please try again.");
        console.error(err);
    });
}
