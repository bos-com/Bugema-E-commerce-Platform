<?php
include 'db_connect.php'; // your DB connection file

if (isset($_POST['id']) && isset($_POST['message'])) {
    $id = intval($_POST['id']);
    $message = mysqli_real_escape_string($conn, $_POST['message']);
    
    $update = "UPDATE notifications SET message='$message' WHERE id=$id";
    if (mysqli_query($conn, $update)) {
        echo "✅ Notification updated successfully!";
    } else {
        echo "❌ Error updating notification: " . mysqli_error($conn);
    }
} else {
    echo "⚠️ Invalid request.";
}
?>
