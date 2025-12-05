<?php
$to = "espoirugaza2020@gmail.com"; // Replace with your email to receive the test
$subject = "Test Email";
$message = "This is a test from XAMPP.";
$headers = "From: espoirugaza2020@gmail.com"; // Replace with your Gmail

if(mail($to, $subject, $message, $headers)){
    echo "Mail sent successfully!";
} else {
    echo "Mail sending failed.";
}
?>
