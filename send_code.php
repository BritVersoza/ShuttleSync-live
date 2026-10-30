<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];

    // 1. Generate a secure 6-digit code
    $reset_code = random_int(100000, 999999);
    $expires = date("U") + 900; // Code expires in 15 minutes (900 seconds)

    // 2. Save the code to the database
    // (Pseudo-code: UPDATE admins SET reset_code = $reset_code, code_expires = $expires WHERE email = $email)

    // 3. Store the email in a session so we know who is trying to reset
    $_SESSION['reset_email'] = $email;

    // 4. Send the email
    $subject = "Your Admin Portal Security Code";
    $message = "Your password reset code is: " . $reset_code . "\n\nThis code expires in 15 minutes. Do not share this with anyone.";
    
    // It is highly recommended to use PHPMailer here for reliable delivery
    mail($email, $subject, $message);

    // 5. Send them to the verification page
    header("Location: /authentication/verify_code");
    exit();
}
?>