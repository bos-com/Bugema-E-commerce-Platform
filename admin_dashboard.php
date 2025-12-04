<?php
// admin_dashboard.php (single-file dashboard: Products, Categories, Deliveries, Reports, Notifications, Feedback, Product Movement)
// Requires: db_connect.php (creates $conn mysqli connection)
// Database: campusshop_db
// Tables required:
//  - products(id, name, price, stock, category, caption, image_path)
//  - pending_deliveries(id, username, amount, payment_method, status, created_at)
//  - notifications(id, user_id, message, created_at)
//  - feedback(id, user_id, name, email, message, created_at, status, admin_reply)
//  - product_movements(id, product_id, movement_type, quantity, issued_by, received_by, remarks, created_at)
//  - users(id, username, email, role, created_at)
//  - orders(id, user_id, total_amount, status, payment_method, shipping_address, created_at)
//  - order_items(id, order_id, product_id, quantity, price)
//  - returns(id, order_id, product_id, reason, status, created_at, quantity, refund_amount)

session_start();
include 'db_connect.php';

// Enable errors for debugging (comment out in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Admin auth check
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    error_log("Unauthorized access attempt: " . print_r($_SESSION, true));
    header("Location: index.php");
    exit;
}

// ==================== EMAIL FUNCTION ====================
function sendFeedbackReplyEmail($userEmail, $userName, $userMessage, $adminReply) {
    $to = $userEmail;
    $subject = "Reply to Your Feedback - Bugema CampusShop";
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #091bbe; color: white; padding: 20px; text-align: center; }
            .content { background: #f9f9f9; padding: 20px; border-left: 4px solid #091bbe; }
            .footer { background: #f1f1f1; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            .user-message { background: white; padding: 15px; margin: 15px 0; border-radius: 5px; border-left: 3px solid #e6eefc; }
            .admin-reply { background: #e8f4fd; padding: 15px; margin: 15px 0; border-radius: 5px; border-left: 3px solid #091bbe; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Bugema CampusShop</h1>
                <p>Response to Your Feedback</p>
            </div>
            <div class='content'>
                <p>Dear $userName,</p>
                <p>Thank you for your feedback. Here is our response:</p>
                
                <div class='user-message'>
                    <strong>Your original message:</strong><br>
                    <em>" . nl2br(htmlspecialchars($userMessage)) . "</em>
                </div>
                
                <div class='admin-reply'>
                    <strong>Our response:</strong><br>
                    " . nl2br(htmlspecialchars($adminReply)) . "
                </div>
                
                <p>If you have any further questions, please don't hesitate to contact us.</p>
                
                <p>Best regards,<br>
                Bugema CampusShop Team</p>
            </div>
            <div class='footer'>
                <p>Bugema CampusShop - Bugema University<br>
                Email: campusshop@bugemauniv.ac.ug | Phone: +256 7550 87665</p>
                <p><small>This is an automated message. Please do not reply to this email.</small></p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Bugema CampusShop <noreply@campusshop.ug>" . "\r\n";
    $headers .= "Reply-To: campusshop@bugemauniv.ac.ug" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    // For debugging, you can log instead of actually sending
    error_log("Attempting to send email to: $userEmail");
    
    return mail($to, $subject, $message, $headers);
}

// ==================== NOTIFICATION FUNCTION ====================
function createUserNotification($userId, $message) {
    global $conn;
    if ($userId) {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("is", $userId, $message);
        return $stmt->execute();
    }
    return false;
}

// ==================== PRODUCT MOVEMENT FUNCTION ====================
function logProductMovement($productId, $movementType, $quantity, $issuedBy, $receivedBy = '', $remarks = '') {
    global $conn;
    
    $stmt = $conn->prepare("
        INSERT INTO product_movements 
        (product_id, movement_type, quantity, issued_by, received_by, remarks, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }

    // CORRECTED: Proper parameter order - movement_type comes before quantity
    $stmt->bind_param("isssss", $productId, $movementType, $quantity, $issuedBy, $receivedBy, $remarks);

    if ($stmt->execute()) {
        // Update stock based on movement type
        $stockChange = 0;
        switch ($movementType) {
            case 'Sale': 
            case 'Gift': 
            case 'Damaged': 
            case 'Promotion':
                $stockChange = -$quantity; // Reduce stock
                break;
            case 'Return':
            case 'Adjustment':
                $stockChange = $quantity; // Increase stock
                break;
        }

        if ($stockChange != 0) {
            $update = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            $update->bind_param("ii", $stockChange, $productId);
            $update->execute();
            $update->close();
        }

        $stmt->close();
        return true;
    } else {
        error_log("Movement log failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

// Lightweight JSON endpoint for product fetch (used by openEditProduct)
if (isset($_GET['fetch_product'])) {
    $pid = intval($_GET['fetch_product']);
    header('Content-Type: application/json');
    
    if ($pid <= 0) {
        echo json_encode(['error' => 'Invalid product ID']);
        exit;
    }

    try {
        $stmt = $conn->prepare("SELECT id, name, price, stock, category, caption, image_path FROM products WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Database preparation failed: ' . $conn->error);
        }
        $stmt->bind_param("i", $pid);
        if (!$stmt->execute()) {
            throw new Exception('Database execution failed: ' . $stmt->error);
        }
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            echo json_encode([
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'price' => (float)$row['price'],
                'stock' => (int)$row['stock'],
                'category' => $row['category'],
                'caption' => $row['caption'],
                'image_path' => $row['image_path']
            ]);
        } else {
            echo json_encode(['error' => 'Product not found']);
        }
    } catch (Exception $e) {
        $errorMsg = 'Error fetching product: ' . $e->getMessage();
        error_log($errorMsg . ' at ' . date('Y-m-d H:i:s'));
        echo json_encode(['error' => $errorMsg]);
    } finally {
        if (isset($stmt)) $stmt->close();
    }
    exit; // Ensure no further code executes
}

// ==================== ORDER DETAILS AJAX ENDPOINT ====================
if (isset($_GET['get_order_details'])) {
    $order_id = intval($_GET['get_order_details']);
    header('Content-Type: application/json');
    
    if ($order_id <= 0) {
        echo json_encode(['error' => 'Invalid order ID']);
        exit;
    }

    try {
        // Fetch order details
        $stmt = $conn->prepare("
            SELECT o.*, u.username, u.email, u.phone, u.address 
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            WHERE o.id = ?
        ");
        if (!$stmt) {
            throw new Exception('Database preparation failed: ' . $conn->error);
        }
        $stmt->bind_param("i", $order_id);
        if (!$stmt->execute()) {
            throw new Exception('Database execution failed: ' . $stmt->error);
        }
        $res = $stmt->get_result();
        $order = $res->fetch_assoc();
        $stmt->close();
        
        if (!$order) {
            echo json_encode(['error' => 'Order not found']);
            exit;
        }
        
        // Fetch order items
        $stmt = $conn->prepare("
            SELECT oi.*, p.name as product_name, p.price as product_price, p.image_path 
            FROM order_items oi 
            LEFT JOIN products p ON oi.product_id = p.id 
            WHERE oi.order_id = ?
        ");
        if (!$stmt) {
            throw new Exception('Database preparation failed: ' . $conn->error);
        }
        $stmt->bind_param("i", $order_id);
        if (!$stmt->execute()) {
            throw new Exception('Database execution failed: ' . $stmt->error);
        }
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items
        ]);
        
    } catch (Exception $e) {
        $errorMsg = 'Error fetching order details: ' . $e->getMessage();
        error_log($errorMsg . ' at ' . date('Y-m-d H:i:s'));
        echo json_encode(['error' => $errorMsg]);
    }
    exit;
}

// ==================== RETURN DETAILS AJAX ENDPOINT ====================
if (isset($_GET['get_return_details'])) {
    $return_id = intval($_GET['get_return_details']);
    header('Content-Type: application/json');
    
    if ($return_id <= 0) {
        echo json_encode(['error' => 'Invalid return ID']);
        exit;
    }

    try {
        $stmt = $conn->prepare("
            SELECT r.*, o.user_id, u.username, u.email, u.phone, p.name as product_name, p.price as product_price
            FROM returns r 
            LEFT JOIN orders o ON r.order_id = o.id 
            LEFT JOIN users u ON o.user_id = u.id 
            LEFT JOIN products p ON r.product_id = p.id 
            WHERE r.id = ?
        ");
        if (!$stmt) {
            throw new Exception('Database preparation failed: ' . $conn->error);
        }
        $stmt->bind_param("i", $return_id);
        if (!$stmt->execute()) {
            throw new Exception('Database execution failed: ' . $stmt->error);
        }
        $res = $stmt->get_result();
        $return = $res->fetch_assoc();
        $stmt->close();
        
        if ($return) {
            echo json_encode(['success' => true, 'return' => $return]);
        } else {
            echo json_encode(['error' => 'Return request not found']);
        }
        
    } catch (Exception $e) {
        $errorMsg = 'Error fetching return details: ' . $e->getMessage();
        error_log($errorMsg . ' at ' . date('Y-m-d H:i:s'));
        echo json_encode(['error' => $errorMsg]);
    }
    exit;
}

// Global categories
$valid_categories = ['Bags', 'Branded Jumpers', 'Bottles', 'Pens', 'Note Books', 'Wall Clocks', 'T-Shirts'];

// Product movement types
$movement_types = ['Sale', 'Gift', 'Return', 'Damaged', 'Promotion', 'Adjustment'];

// Order statuses
$order_statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled', 'Refunded'];

// Return statuses
$return_statuses = ['Pending', 'Approved', 'Rejected', 'Completed'];

// User roles
$user_roles = ['admin', 'manager', 'staff', 'customer'];

// Payment methods
$payment_methods = ['Cash', 'Mobile Money', 'Bank Transfer', 'Card', 'PayPal'];

// Helper
function post_val($k, $d='') { return isset($_POST[$k]) ? $_POST[$k] : $d; }
$message = null;

// --------------------
// HANDLE REQUESTS
// --------------------

// Add / Edit Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add', 'edit'])) {
    $action = $_POST['action'];
    $name = $conn->real_escape_string(post_val('name'));
    $price = floatval(post_val('price', 0));
    $stock = intval(post_val('stock', 0));
    $category = $conn->real_escape_string(post_val('category'));
    $caption = $conn->real_escape_string(post_val('caption', ''));
    $id = intval(post_val('id', 0)); // For edit
    $image_path = null;

    // Basic validation
    if (!in_array($category, $valid_categories)) {
        $message = "Invalid category selected.";
    } elseif ($price <= 0) {
        $message = "Price must be greater than zero.";
    } elseif ($stock < 0) {
        $message = "Stock cannot be negative.";
    } elseif (strlen($caption) > 255) {
        $message = "Caption must be 255 characters or less.";
    } elseif ($action === 'edit' && $id <= 0) {
        $message = "Invalid product ID for editing.";
    } else {

        // Image upload (optional for add, update for edit)
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $image = $_FILES['image'];
            $image_info = getimagesize($image['tmp_name']);
            $allowed = ['image/jpeg','image/png','image/jpg'];
            if ($image_info && in_array($image_info['mime'], $allowed)) {
                if (!is_dir('images')) mkdir('images', 0755, true);
                $fn = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($image['name']));
                $new_path = 'images/' . $fn;
                if (move_uploaded_file($image['tmp_name'], $new_path)) {
                    $image_path = $new_path;
                    // Delete old image if editing
                    if ($action === 'edit') {
                        $stmt = $conn->prepare("SELECT image_path FROM products WHERE id = ?");
                        $stmt->bind_param("i", $id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($old_row = $result->fetch_assoc()) {
                            if (!empty($old_row['image_path']) && file_exists($old_row['image_path'])) {
                                @unlink($old_row['image_path']);
                            }
                        }
                        $stmt->close();
                    }
                } else {
                    $message = "Failed to upload image.";
                }
            } else {
                $message = "Invalid image format. Use JPEG or PNG.";
            }
        }

        if (!isset($message)) {
            if ($action === 'add') {
                $sql = "INSERT INTO products (name, price, stock, category, caption, image_path) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sdisss", $name, $price, $stock, $category, $caption, $image_path);
                if ($stmt->execute()) {
                    $message = "Product added successfully.";
                } else {
                    $message = "Failed to add product: " . $stmt->error;
                    error_log("Add product failed: " . $stmt->error);
                }
                $stmt->close();
            } else { // edit
                // Get current image path if no new image uploaded
                if (!$image_path) {
                    $stmt = $conn->prepare("SELECT image_path FROM products WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($old_row = $result->fetch_assoc()) {
                        $image_path = $old_row['image_path'];
                    }
                    $stmt->close();
                }
                
                $sql = "UPDATE products SET name = ?, price = ?, stock = ?, category = ?, caption = ?, image_path = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sdisssi", $name, $price, $stock, $category, $caption, $image_path, $id);
                if ($stmt->execute()) {
                    $message = "Product updated successfully.";
                } else {
                    $message = "Failed to update product: " . $stmt->error;
                    error_log("Update product failed: " . $stmt->error);
                }
                $stmt->close();
            }
        }
    }
}

// Delete product
if (isset($_GET['delete_product'])) {
    $id = intval($_GET['delete_product']);
    if ($id > 0) {
        $sql = "SELECT image_path FROM products WHERE id = ?";
        $st = $conn->prepare($sql);
        $st->bind_param("i",$id);
        $st->execute();
        $r = $st->get_result();
        if ($r && ($row = $r->fetch_assoc()) && !empty($row['image_path'])) {
            if (file_exists($row['image_path'])) @unlink($row['image_path']);
        }
        $st->close();

        $del = $conn->prepare("DELETE FROM products WHERE id = ?");
        $del->bind_param("i",$id);
        if ($del->execute()) $message = "Product deleted.";
        else { $message = "Failed to delete product: ".$del->error; error_log("Delete product failed: ".$del->error); }
        $del->close();
    }
}

// --------------------
// CATEGORY MANAGEMENT
// --------------------

// Add/Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['category_action'])) {
    $category_action = $_POST['category_action'];
    $category_name = trim($conn->real_escape_string(post_val('category_name', '')));
    $category_id = intval(post_val('category_id', 0));
    
    if (empty($category_name)) {
        $message = "Category name cannot be empty.";
    } elseif (strlen($category_name) > 50) {
        $message = "Category name must be 50 characters or less.";
    } else {
        if ($category_action === 'add') {
            // Check if category already exists
            $check_stmt = $conn->prepare("SELECT id FROM products WHERE category = ? LIMIT 1");
            $check_stmt->bind_param("s", $category_name);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $message = "Category already exists and cannot be added again.";
            } else {
                // Add new category by inserting a dummy product (or you can create a categories table)
                $stmt = $conn->prepare("INSERT INTO products (name, price, stock, category, caption, image_path) VALUES (?, 0, 0, ?, 'System Category', '')");
                $dummy_name = "CATEGORY_" . strtoupper($category_name);
                $stmt->bind_param("ss", $dummy_name, $category_name);
                if ($stmt->execute()) {
                    $message = "Category added successfully.";
                    // Update valid categories array
                    $valid_categories[] = $category_name;
                } else {
                    $message = "Failed to add category: " . $stmt->error;
                    error_log("Add category failed: " . $stmt->error);
                }
                $stmt->close();
            }
            $check_stmt->close();
            
        } elseif ($category_action === 'edit' && $category_id > 0) {
            // Get old category name first
            $old_stmt = $conn->prepare("SELECT category FROM products WHERE id = ?");
            $old_stmt->bind_param("i", $category_id);
            $old_stmt->execute();
            $old_result = $old_stmt->get_result();
            $old_category = '';
            if ($old_row = $old_result->fetch_assoc()) {
                $old_category = $old_row['category'];
            }
            $old_stmt->close();
            
            // Update category name across all products
            $stmt = $conn->prepare("UPDATE products SET category = ? WHERE category = ?");
            $stmt->bind_param("ss", $category_name, $old_category);
            if ($stmt->execute()) {
                $message = "Category updated successfully.";
                // Update valid categories array
                $key = array_search($old_category, $valid_categories);
                if ($key !== false) {
                    $valid_categories[$key] = $category_name;
                }
            } else {
                $message = "Failed to update category: " . $stmt->error;
                error_log("Update category failed: " . $stmt->error);
            }
            $stmt->close();
        }
    }
}

// Delete Category
if (isset($_GET['delete_category'])) {
    $category_to_delete = $conn->real_escape_string($_GET['delete_category']);
    
    if (!empty($category_to_delete)) {
        // Check if category has products
        $check_stmt = $conn->prepare("SELECT COUNT(*) as product_count FROM products WHERE category = ? AND name NOT LIKE 'CATEGORY_%'");
        $check_stmt->bind_param("s", $category_to_delete);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $row = $result->fetch_assoc();
        $product_count = $row['product_count'];
        $check_stmt->close();
        
        if ($product_count > 0) {
            $message = "Cannot delete category '$category_to_delete' - it contains $product_count product(s). Move or delete products first.";
        } else {
            // Delete the category (dummy products)
            $stmt = $conn->prepare("DELETE FROM products WHERE category = ? AND name LIKE 'CATEGORY_%'");
            $stmt->bind_param("s", $category_to_delete);
            if ($stmt->execute()) {
                $message = "Category deleted successfully.";
                // Remove from valid categories array
                $key = array_search($category_to_delete, $valid_categories);
                if ($key !== false) {
                    unset($valid_categories[$key]);
                }
            } else {
                $message = "Failed to delete category: " . $stmt->error;
                error_log("Delete category failed: " . $stmt->error);
            }
            $stmt->close();
        }
    }
}

// ==================== PRODUCT MOVEMENT MANAGEMENT ====================

// Handle product movement transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_movement'])) {
    $product_id = intval(post_val('product_id', 0));
    $movement_type = $conn->real_escape_string(post_val('movement_type', ''));
    $quantity = intval(post_val('quantity', 0));
    $received_by = $conn->real_escape_string(post_val('received_by', ''));
    $remarks = $conn->real_escape_string(post_val('remarks', ''));
    $issued_by = $_SESSION['username'];
    
    error_log("Movement form submitted: product_id=$product_id, type=$movement_type, quantity=$quantity");
    
    if ($product_id <= 0) {
        $message = "Please select a valid product.";
    } elseif (!in_array($movement_type, $movement_types)) {
        $message = "Invalid movement type selected.";
    } elseif ($quantity <= 0) {
        $message = "Quantity must be greater than zero.";
    } else {
        // Check stock availability for outgoing movements
        if (in_array($movement_type, ['Sale', 'Gift', 'Damaged', 'Promotion'])) {
            $stock_check = $conn->prepare("SELECT stock, name FROM products WHERE id = ?");
            $stock_check->bind_param("i", $product_id);
            $stock_check->execute();
            $stock_result = $stock_check->get_result();
            
            if ($stock_row = $stock_result->fetch_assoc()) {
                if ($stock_row['stock'] < $quantity) {
                    $message = "Insufficient stock for '{$stock_row['name']}'. Only " . $stock_row['stock'] . " items available.";
                }
            } else {
                $message = "Product not found.";
            }
            $stock_check->close();
        }
        
        if (!isset($message)) {
            if (logProductMovement($product_id, $movement_type, $quantity, $issued_by, $received_by, $remarks)) {
                $message = "Product movement recorded successfully.";
                error_log("Movement recorded successfully: $movement_type for product $product_id, quantity $quantity");
            } else {
                $message = "Failed to record product movement.";
                error_log("Product movement recording failed for product $product_id");
            }
        }
    }
}

// ==================== DELIVERY COMPLETION WITH STOCK REDUCTION ====================
if (isset($_GET['complete_delivery'])) {
    $id = intval($_GET['complete_delivery']);
    if ($id > 0) {
        // Start transaction for data consistency
        $conn->begin_transaction();
        
        try {
            // Get delivery details including product_id and quantity
            $stmt = $conn->prepare("SELECT product_id, quantity, username FROM pending_deliveries WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $delivery = $result->fetch_assoc();
                $product_id = $delivery['product_id'];
                $quantity = $delivery['quantity'];
                $username = $delivery['username'];
                
                // Update delivery status
                $update_stmt = $conn->prepare("UPDATE pending_deliveries SET status = 'Completed' WHERE id = ?");
                $update_stmt->bind_param("i", $id);
                $update_stmt->execute();
                
                // Reduce product stock if product_id exists and log as sale
                if ($product_id && $product_id !== 'N/A') {
                    $stock_stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
                    $stock_stmt->bind_param("iii", $quantity, $product_id, $quantity);
                    
                    if ($stock_stmt->execute()) {
                        if ($stock_stmt->affected_rows > 0) {
                            // Log this as a sale in product movements
                            logProductMovement($product_id, 'Sale', $quantity, $_SESSION['username'], $username, 'Online order completed');
                            $message = "Delivery marked completed and stock reduced successfully.";
                        } else {
                            // If stock reduction failed (not enough stock), rollback
                            throw new Exception("Not enough stock to complete this delivery.");
                        }
                    } else {
                        throw new Exception("Failed to update product stock: " . $stock_stmt->error);
                    }
                    $stock_stmt->close();
                } else {
                    $message = "Delivery marked completed (no stock adjustment - product ID missing).";
                }
                
                $update_stmt->close();
            } else {
                throw new Exception("Delivery not found.");
            }
            
            $stmt->close();
            $conn->commit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Failed to complete delivery: " . $e->getMessage();
            error_log("Complete delivery failed: " . $e->getMessage());
        }
    }
}

// Delete delivery (optional)
if (isset($_GET['delete_delivery'])) {
    $id = intval($_GET['delete_delivery']);
    if ($id > 0) {
        $del = $conn->prepare("DELETE FROM pending_deliveries WHERE id = ?");
        $del->bind_param("i",$id);
        if ($del->execute()) $message = "Delivery deleted.";
        else { $message = "Failed to delete delivery: ".$del->error; error_log("Delete delivery failed: ".$del->error); }
        $del->close();
    }
}

// Notifications: add (send), edit, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    $notification_message = trim($conn->real_escape_string(post_val('message')));
    if (empty($notification_message)) {
        $message = "Notification cannot be empty.";
    } else {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (NULL, ?, NOW())");
        $stmt->bind_param("s", $notification_message);
        if ($stmt->execute()) $message = "Notification sent.";
        else { $message = "Failed to send notification: ".$stmt->error; error_log("Send notification failed: ".$stmt->error); }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_notification'])) {
    $nid = intval(post_val('nid', 0));
    $msg = $conn->real_escape_string(post_val('nmessage', ''));
    if ($nid <= 0 || $msg === '') $message = "Invalid notification edit.";
    else {
        $stmt = $conn->prepare("UPDATE notifications SET message = ?, created_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $msg, $nid);
        if ($stmt->execute()) $message = "Notification updated.";
        else { $message = "Failed to update notification: ".$stmt->error; error_log("Edit notification failed: ".$stmt->error); }
        $stmt->close();
    }
}

if (isset($_GET['delete_notification'])) {
    $nid = intval($_GET['delete_notification']);
    if ($nid > 0) {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ?");
        $stmt->bind_param("i",$nid);
        if ($stmt->execute()) $message = "Notification deleted.";
        else { $message = "Failed to delete notification: ".$stmt->error; error_log("Delete notification failed: ".$stmt->error); }
        $stmt->close();
    }
}

// ==================== FEEDBACK MANAGEMENT ====================

// Handle admin reply to feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_feedback'])) {
    $feedback_id = intval(post_val('feedback_id', 0));
    $admin_reply = trim($conn->real_escape_string(post_val('admin_reply', '')));
    
    if ($feedback_id <= 0) {
        $message = "Invalid feedback ID.";
    } elseif (empty($admin_reply)) {
        $message = "Reply message cannot be empty.";
    } else {
        // First get user details and original message
        $stmt = $conn->prepare("SELECT user_id, name, email, message FROM feedback WHERE id = ?");
        $stmt->bind_param("i", $feedback_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $feedback = $result->fetch_assoc();
            $userName = $feedback['name'];
            $userEmail = $feedback['email'];
            $userMessage = $feedback['message'];
            $userId = $feedback['user_id'];
            
            // Update the feedback with admin reply
            $update_stmt = $conn->prepare("UPDATE feedback SET admin_reply = ?, status = 'replied', replied_at = NOW() WHERE id = ?");
            $update_stmt->bind_param("si", $admin_reply, $feedback_id);
            
            if ($update_stmt->execute()) {
                $emailSent = false;
                $notificationCreated = false;
                
                // Send email notification if user provided email
                if (!empty($userEmail) && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    $emailSent = sendFeedbackReplyEmail($userEmail, $userName, $userMessage, $admin_reply);
                    error_log("Email send attempt result: " . ($emailSent ? "SUCCESS" : "FAILED"));
                } else {
                    error_log("No valid email provided for feedback ID: $feedback_id");
                }
                
                // Create in-app notification if user is registered
                if ($userId) {
                    $notificationMessage = "We've replied to your feedback: " . (strlen($admin_reply) > 50 ? substr($admin_reply, 0, 50) . "..." : $admin_reply);
                    $notificationCreated = createUserNotification($userId, $notificationMessage);
                }
                
                // Build success message
                if ($emailSent && $notificationCreated) {
                    $message = "Reply sent successfully! User notified via email and in-app notification.";
                } elseif ($emailSent) {
                    $message = "Reply sent successfully! User notified via email.";
                } elseif ($notificationCreated) {
                    $message = "Reply saved successfully! User notified via in-app notification.";
                } else {
                    $message = "Reply saved successfully! (No notifications sent - user has no email or account)";
                }
                
            } else {
                $message = "Failed to save reply: " . $update_stmt->error;
                error_log("Reply feedback failed: " . $update_stmt->error);
            }
            $update_stmt->close();
        } else {
            $message = "Feedback not found.";
        }
        $stmt->close();
    }
}

// Mark feedback as read
if (isset($_GET['mark_feedback_read'])) {
    $feedback_id = intval($_GET['mark_feedback_read']);
    if ($feedback_id > 0) {
        $stmt = $conn->prepare("UPDATE feedback SET status = 'read' WHERE id = ?");
        $stmt->bind_param("i", $feedback_id);
        if ($stmt->execute()) {
            $message = "Feedback marked as read.";
        } else {
            $message = "Failed to update feedback: " . $stmt->error;
            error_log("Mark feedback read failed: " . $stmt->error);
        }
        $stmt->close();
    }
}

// Delete feedback
if (isset($_GET['delete_feedback'])) {
    $feedback_id = intval($_GET['delete_feedback']);
    if ($feedback_id > 0) {
        $stmt = $conn->prepare("DELETE FROM feedback WHERE id = ?");
        $stmt->bind_param("i", $feedback_id);
        if ($stmt->execute()) {
            $message = "Feedback deleted successfully.";
        } else {
            $message = "Failed to delete feedback: " . $stmt->error;
            error_log("Delete feedback failed: " . $stmt->error);
        }
        $stmt->close();
    }
}

// ==================== ORDER MANAGEMENT ====================

// Update order status
if (isset($_GET['update_order_status'])) {
    $order_id = intval($_GET['order_id']);
    $new_status = $conn->real_escape_string($_GET['status']);
    
    if ($order_id > 0 && in_array($new_status, $order_statuses)) {
        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $order_id);
        if ($stmt->execute()) {
            $message = "Order #$order_id status updated to $new_status successfully.";
            
            // Create notification for user if order is delivered
            if ($new_status === 'Delivered') {
                $user_stmt = $conn->prepare("SELECT user_id FROM orders WHERE id = ?");
                $user_stmt->bind_param("i", $order_id);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_row = $user_result->fetch_assoc()) {
                    $notification_msg = "Your order #$order_id has been delivered successfully!";
                    createUserNotification($user_row['user_id'], $notification_msg);
                }
                $user_stmt->close();
            }
        } else {
            $message = "Failed to update order status: " . $stmt->error;
            error_log("Update order status failed: " . $stmt->error);
        }
        $stmt->close();
    }
}

// Delete order
if (isset($_GET['delete_order'])) {
    $order_id = intval($_GET['delete_order']);
    if ($order_id > 0) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete order items first
            $delete_items = $conn->prepare("DELETE FROM order_items WHERE order_id = ?");
            $delete_items->bind_param("i", $order_id);
            $delete_items->execute();
            $delete_items->close();
            
            // Delete order
            $delete_order = $conn->prepare("DELETE FROM orders WHERE id = ?");
            $delete_order->bind_param("i", $order_id);
            if ($delete_order->execute()) {
                $message = "Order deleted successfully.";
            } else {
                throw new Exception("Failed to delete order: " . $delete_order->error);
            }
            $delete_order->close();
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Failed to delete order: " . $e->getMessage();
            error_log("Delete order failed: " . $e->getMessage());
        }
    }
}

// Generate invoice
if (isset($_GET['generate_invoice'])) {
    $order_id = intval($_GET['generate_invoice']);
    if ($order_id > 0) {
        // This would typically generate a PDF invoice
        // For now, we'll just show a success message
        $message = "Invoice generated for order #$order_id (PDF generation would be implemented here).";
    }
}

// ==================== USER MANAGEMENT ====================

// Update user role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user_role'])) {
    $user_id = intval(post_val('user_id', 0));
    $new_role = $conn->real_escape_string(post_val('role', ''));
    
    if ($user_id > 0 && in_array($new_role, $user_roles)) {
        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->bind_param("si", $new_role, $user_id);
        if ($stmt->execute()) {
            $message = "User role updated successfully.";
        } else {
            $message = "Failed to update user role: " . $stmt->error;
            error_log("Update user role failed: " . $stmt->error);
        }
        $stmt->close();
    }
}

// Delete user
if (isset($_GET['delete_user'])) {
    $user_id = intval($_GET['delete_user']);
    if ($user_id > 0 && $user_id != $_SESSION['user_id']) { // Prevent self-deletion
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $message = "User deleted successfully.";
        } else {
            $message = "Failed to delete user: " . $stmt->error;
            error_log("Delete user failed: " . $stmt->error);
        }
        $stmt->close();
    } elseif ($user_id == $_SESSION['user_id']) {
        $message = "You cannot delete your own account.";
    }
}

// ==================== RETURNS MANAGEMENT ====================

// Process return
if (isset($_GET['process_return'])) {
    $return_id = intval($_GET['return_id']);
    $action = $conn->real_escape_string($_GET['action']);
    
    if ($return_id > 0 && in_array($action, ['approve', 'reject', 'complete'])) {
        $new_status = '';
        switch ($action) {
            case 'approve': $new_status = 'Approved'; break;
            case 'reject': $new_status = 'Rejected'; break;
            case 'complete': $new_status = 'Completed'; break;
        }
        
        $stmt = $conn->prepare("UPDATE returns SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $return_id);
        if ($stmt->execute()) {
            $message = "Return request $new_status successfully.";
            
            // If approved, process refund and restock
            if ($action === 'approve') {
                // Get return details
                $return_stmt = $conn->prepare("SELECT r.order_id, r.product_id, r.quantity, r.refund_amount FROM returns r WHERE r.id = ?");
                $return_stmt->bind_param("i", $return_id);
                $return_stmt->execute();
                $return_result = $return_stmt->get_result();
                
                if ($return_row = $return_result->fetch_assoc()) {
                    // Restock product
                    $restock_stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                    $restock_stmt->bind_param("ii", $return_row['quantity'], $return_row['product_id']);
                    $restock_stmt->execute();
                    $restock_stmt->close();
                    
                    // Log the return in product movements
                    logProductMovement($return_row['product_id'], 'Return', $return_row['quantity'], $_SESSION['username'], 'System', 'Return from order #' . $return_row['order_id']);
                    
                    // Update order status to refunded if refund amount > 0
                    if ($return_row['refund_amount'] > 0) {
                        $update_order_stmt = $conn->prepare("UPDATE orders SET status = 'Refunded' WHERE id = ?");
                        $update_order_stmt->bind_param("i", $return_row['order_id']);
                        $update_order_stmt->execute();
                        $update_order_stmt->close();
                    }
                }
                $return_stmt->close();
            }
        } else {
            $message = "Failed to process return: " . $stmt->error;
            error_log("Process return failed: " . $stmt->error);
        }
        $stmt->close();
    }
}

// Delete return
if (isset($_GET['delete_return'])) {
    $return_id = intval($_GET['delete_return']);
    if ($return_id > 0) {
        $stmt = $conn->prepare("DELETE FROM returns WHERE id = ?");
        $stmt->bind_param("i", $return_id);
        if ($stmt->execute()) {
            $message = "Return request deleted successfully.";
        } else {
            $message = "Failed to delete return request: " . $stmt->error;
            error_log("Delete return failed: " . $stmt->error);
        }
        $stmt->close();
    }
}

// ==================== REPORT GENERATION ====================
if (isset($_GET['print_report'])) {
    $report_type = $_GET['print_report'];
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-t');
    
    // Generate report data
    $report_data = [];
    $report_title = "";
    
    if ($report_type === 'sales_summary') {
        $report_title = "Sales Summary Report";
        
        // Sales summary report with proper joins
        $stmt = $conn->prepare("
            SELECT 
                DATE(o.created_at) as sale_date,
                o.id as order_id,
                u.username as customer_name,
                COUNT(DISTINCT oi.product_id) as product_count,
                SUM(oi.quantity) as total_quantity,
                o.total_amount,
                o.payment_method,
                o.status
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            GROUP BY o.id
            ORDER BY sale_date DESC
        ");
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $report_data[] = $row;
        }
        $stmt->close();
        
        // If no data found, show empty message
        if (empty($report_data)) {
            $report_data[] = [
                'sale_date' => $start_date,
                'product_count' => 0,
                'total_quantity' => 0,
                'total_amount' => 0,
                'status' => 'No data found'
            ];
        }
    } elseif ($report_type === 'category_sales') {
        $report_title = "Category Sales Report";
        
        // Category sales report
        $stmt = $conn->prepare("
            SELECT 
                p.category,
                COUNT(DISTINCT p.id) as product_count,
                SUM(oi.quantity) as total_sold,
                SUM(oi.quantity * oi.price) as total_revenue,
                AVG(p.price) as avg_price
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN orders o ON oi.order_id = o.id
            WHERE o.status = 'Delivered' AND o.created_at BETWEEN ? AND ?
            GROUP BY p.category
            ORDER BY total_revenue DESC
        ");
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $report_data[] = $row;
        }
        $stmt->close();
    } elseif ($report_type === 'stock_report') {
        $report_title = "Stock Inventory Report";
        
        // Stock report
        $stmt = $conn->prepare("
            SELECT 
                name,
                category,
                price,
                stock,
                (price * stock) as stock_value
            FROM products 
            WHERE name NOT LIKE 'CATEGORY_%'
            ORDER BY stock_value DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $report_data[] = $row;
        }
        $stmt->close();
    } elseif ($report_type === 'movement_report') {
        $report_title = "Product Movement Report";
        
        // Movement report
        $stmt = $conn->prepare("
            SELECT 
                DATE(pm.created_at) as movement_date,
                p.name as product_name,
                pm.movement_type,
                pm.quantity,
                pm.issued_by,
                pm.received_by,
                pm.remarks
            FROM product_movements pm
            LEFT JOIN products p ON pm.product_id = p.id
            WHERE DATE(pm.created_at) BETWEEN ? AND ?
            ORDER BY pm.created_at DESC
        ");
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $report_data[] = $row;
        }
        $stmt->close();
    } elseif ($report_type === 'customer_behavior') {
        $report_title = "Customer Behavior Report";
        
        // Customer behavior report
        $stmt = $conn->prepare("
            SELECT 
                u.username,
                u.email,
                COUNT(o.id) as total_orders,
                SUM(o.total_amount) as total_spent,
                AVG(o.total_amount) as avg_order_value,
                MAX(o.created_at) as last_order_date
            FROM users u
            LEFT JOIN orders o ON u.id = o.user_id
            WHERE o.created_at BETWEEN ? AND ?
            GROUP BY u.id
            HAVING total_orders > 0
            ORDER BY total_spent DESC
        ");
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $report_data[] = $row;
        }
        $stmt->close();
    } elseif ($report_type === 'financial') {
        $report_title = "Financial Summary Report";
        
        // Financial report
        $revenue_stmt = $conn->prepare("
            SELECT SUM(total_amount) as total_revenue
            FROM orders 
            WHERE status = 'Delivered' AND created_at BETWEEN ? AND ?
        ");
        $revenue_stmt->bind_param("ss", $start_date, $end_date);
        $revenue_stmt->execute();
        $revenue_result = $revenue_stmt->get_result();
        $revenue = $revenue_result->fetch_assoc();
        $revenue_stmt->close();
        
        $refunds_stmt = $conn->prepare("
            SELECT SUM(refund_amount) as total_refunds
            FROM returns 
            WHERE status IN ('Approved', 'Completed') AND created_at BETWEEN ? AND ?
        ");
        $refunds_stmt->bind_param("ss", $start_date, $end_date);
        $refunds_stmt->execute();
        $refunds_result = $refunds_stmt->get_result();
        $refunds = $refunds_result->fetch_assoc();
        $refunds_stmt->close();
        
        $orders_stmt = $conn->prepare("
            SELECT COUNT(*) as total_orders, AVG(total_amount) as avg_order_value
            FROM orders 
            WHERE created_at BETWEEN ? AND ?
        ");
        $orders_stmt->bind_param("ss", $start_date, $end_date);
        $orders_stmt->execute();
        $orders_result = $orders_stmt->get_result();
        $orders = $orders_result->fetch_assoc();
        $orders_stmt->close();
        
        $report_data = [
            'total_revenue' => $revenue['total_revenue'] ?? 0,
            'total_refunds' => $refunds['total_refunds'] ?? 0,
            'net_revenue' => ($revenue['total_revenue'] ?? 0) - ($refunds['total_refunds'] ?? 0),
            'total_orders' => $orders['total_orders'] ?? 0,
            'avg_order_value' => $orders['avg_order_value'] ?? 0,
            'start_date' => $start_date,
            'end_date' => $end_date
        ];
    }
    
    // Store report data in session for printing
    $_SESSION['print_report'] = [
        'title' => $report_title,
        'type' => $report_type,
        'data' => $report_data,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'generated_at' => date('Y-m-d H:i:s')
    ];
    
    header("Location: admin_dashboard.php?show_print=true&section=reports");
    exit;
}

// --------------------
// FETCH DASHBOARD DATA
// --------------------
$totalSales = 0;
if ($res = $conn->query("SELECT COALESCE(SUM(total_amount), 0) AS total_sales FROM orders WHERE status = 'Delivered'")) {
    $r = $res->fetch_assoc(); $totalSales = floatval($r['total_sales']);
}

$totalProducts = 0;
if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE name NOT LIKE 'CATEGORY_%'")) { $r = $res->fetch_assoc(); $totalProducts = intval($r['cnt']); }

$pendingCount = 0;
if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM pending_deliveries WHERE status = 'Pending'")) { $r = $res->fetch_assoc(); $pendingCount = intval($r['cnt']); }

$notifCount = 0;
if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM notifications WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")) { $r = $res->fetch_assoc(); $notifCount = intval($r['cnt']); }

// Feedback counts
$unreadFeedbackCount = 0;
if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM feedback WHERE status = 'new'")) { $r = $res->fetch_assoc(); $unreadFeedbackCount = intval($r['cnt']); }

$totalFeedbackCount = 0;
if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM feedback")) { $r = $res->fetch_assoc(); $totalFeedbackCount = intval($r['cnt']); }

// ==================== STOCK DATA ====================
$totalStockValue = 0;
if ($res = $conn->query("SELECT COALESCE(SUM(price * stock), 0) AS total_value FROM products WHERE name NOT LIKE 'CATEGORY_%'")) { 
    $r = $res->fetch_assoc(); $totalStockValue = floatval($r['total_value']); 
}

$totalStockItems = 0;
if ($res = $conn->query("SELECT COALESCE(SUM(stock), 0) AS total_items FROM products WHERE name NOT LIKE 'CATEGORY_%'")) { 
    $r = $res->fetch_assoc(); $totalStockItems = intval($r['total_items']); 
}

$lowStockCount = 0;
if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE stock <= 5 AND name NOT LIKE 'CATEGORY_%'")) { 
    $r = $res->fetch_assoc(); $lowStockCount = intval($r['cnt']); 
}

// ==================== PRODUCT MOVEMENT DATA ====================
$totalSold = 0;
if ($res = $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM product_movements WHERE movement_type = 'Sale'")) { 
    $r = $res->fetch_assoc(); $totalSold = intval($r['total']); 
}

$totalGifts = 0;
if ($res = $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM product_movements WHERE movement_type = 'Gift'")) { 
    $r = $res->fetch_assoc(); $totalGifts = intval($r['total']); 
}

$totalDamaged = 0;
if ($res = $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM product_movements WHERE movement_type = 'Damaged'")) { 
    $r = $res->fetch_assoc(); $totalDamaged = intval($r['total']); 
}

$totalReturns = 0;
if ($res = $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM product_movements WHERE movement_type = 'Return'")) { 
    $r = $res->fetch_assoc(); $totalReturns = intval($r['total']); 
}

$totalPromotions = 0;
if ($res = $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM product_movements WHERE movement_type = 'Promotion'")) { 
    $r = $res->fetch_assoc(); $totalPromotions = intval($r['total']); 
}

$totalAdjustments = 0;
if ($res = $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM product_movements WHERE movement_type = 'Adjustment'")) { 
    $r = $res->fetch_assoc(); $totalAdjustments = intval($r['total']); 
}

// Total products moved (all types except returns)
$totalProductsMoved = $totalSold + $totalGifts + $totalDamaged + $totalPromotions;

// Movement distribution for chart
$movementLabels = ['Sold', 'Gifts', 'Damaged', 'Promotions', 'Returns', 'Adjustments'];
$movementData = [$totalSold, $totalGifts, $totalDamaged, $totalPromotions, $totalReturns, $totalAdjustments];

// Sales by month (last 12 months) - FIXED with COALESCE
$salesLabels = []; $salesData = [];
$months_sql = "
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(total_amount), 0) AS total
    FROM orders
    WHERE status = 'Delivered' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
    GROUP BY ym
    ORDER BY ym ASC
";
$monthMap = [];
if ($res = $conn->query($months_sql)) {
    while ($row = $res->fetch_assoc()) $monthMap[$row['ym']] = floatval($row['total']);
}
for ($i=11;$i>=0;$i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $salesLabels[] = date('M Y', strtotime($m.'-01'));
    $salesData[] = isset($monthMap[$m]) ? $monthMap[$m] : 0;
}

// Category distribution
$catLabels = []; $catData = [];
if ($res = $conn->query("SELECT category, COUNT(*) AS cnt FROM products WHERE name NOT LIKE 'CATEGORY_%' GROUP BY category")) {
    while ($r = $res->fetch_assoc()) { $catLabels[] = $r['category']; $catData[] = intval($r['cnt']); }
}

// Recent orders (last 8)
$recentOrders = [];
if ($res = $conn->query("SELECT o.id, u.username, o.total_amount, o.payment_method, o.status, o.created_at 
                         FROM orders o 
                         LEFT JOIN users u ON o.user_id = u.id 
                         ORDER BY o.created_at DESC LIMIT 8")) {
    while ($r = $res->fetch_assoc()) {
        $recentOrders[] = $r;
    }
}

// Products and pending lists for tables
$products = []; if ($res = $conn->query("SELECT * FROM products WHERE name NOT LIKE 'CATEGORY_%' ORDER BY id DESC LIMIT 200")) while ($r=$res->fetch_assoc()) $products[] = $r;
// Updated: Fetch pending deliveries with product information
$pendingDeliveries = []; 
if ($res = $conn->query("
    SELECT 
        pd.id, 
        pd.username, 
        pd.phone, 
        pd.location, 
        pd.payment_method, 
        pd.amount, 
        pd.status, 
        pd.created_at,
        pd.product_id,
        pd.product_name,
        pd.product_image,
        pd.quantity,
        COALESCE(pd.product_name, 'Unknown Product') as display_product_name,
        COALESCE(pd.product_image, '') as display_product_image
    FROM pending_deliveries pd
    ORDER BY pd.created_at DESC LIMIT 200
")) {
    while ($r = $res->fetch_assoc()) {
        // Use product information from pending_deliveries
        $r['product_name'] = $r['display_product_name'];
        $r['product_image'] = $r['display_product_image'];
        $r['product_id'] = $r['product_id'] ?? 'N/A';
        $r['quantity'] = $r['quantity'] ?? 1;
        $pendingDeliveries[] = $r;
    }
}

// Notifications list
$notifications = [];
if ($res = $conn->query("SELECT id, user_id, message, created_at FROM notifications ORDER BY created_at DESC LIMIT 200")) {
    while ($r = $res->fetch_assoc()) $notifications[] = $r;
}

// Fetch categories for management (exclude dummy category products)
$categories = [];
if ($res = $conn->query("SELECT DISTINCT category, COUNT(*) as product_count FROM products WHERE name NOT LIKE 'CATEGORY_%' GROUP BY category ORDER BY category")) {
    while ($r = $res->fetch_assoc()) {
        $categories[] = $r;
    }
}

// Fetch feedback for management
$feedbackList = [];
if ($res = $conn->query("SELECT id, user_id, name, email, message, admin_reply, status, created_at, replied_at FROM feedback ORDER BY created_at DESC LIMIT 200")) {
    while ($r = $res->fetch_assoc()) {
        $feedbackList[] = $r;
    }
}

// Fetch product movements for management
$productMovements = [];
if ($res = $conn->query("
    SELECT pm.*, p.name as product_name 
    FROM product_movements pm 
    LEFT JOIN products p ON pm.product_id = p.id 
    ORDER BY pm.created_at DESC LIMIT 200
")) {
    while ($r = $res->fetch_assoc()) {
        $productMovements[] = $r;
    }
}

// ==================== NEW DATA FETCHES ====================

// Fetch orders for order management - UPDATED TO INCLUDE PRODUCT INFO
$orders = [];
// Check if created_at column exists, otherwise use id for ordering
$check_column = $conn->query("SHOW COLUMNS FROM orders LIKE 'created_at'");
if ($check_column->num_rows > 0) {
    // created_at exists
    $order_query = "
        SELECT o.*, u.username, u.email,
               GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') as product_names,
               GROUP_CONCAT(DISTINCT oi.quantity SEPARATOR ', ') as quantities,
               COUNT(oi.id) as item_count
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        LEFT JOIN order_items oi ON o.id = oi.order_id
        LEFT JOIN products p ON oi.product_id = p.id
        GROUP BY o.id
        ORDER BY o.created_at DESC LIMIT 200
    ";
} else {
    // created_at doesn't exist, use id for ordering
    $order_query = "
        SELECT o.*, u.username, u.email,
               GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') as product_names,
               GROUP_CONCAT(DISTINCT oi.quantity SEPARATOR ', ') as quantities,
               COUNT(oi.id) as item_count
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        LEFT JOIN order_items oi ON o.id = oi.order_id
        LEFT JOIN products p ON oi.product_id = p.id
        GROUP BY o.id
        ORDER BY o.id DESC LIMIT 200
    ";
}
$check_column->close();

if ($res = $conn->query($order_query)) {
    while ($r = $res->fetch_assoc()) {
        $orders[] = $r;
    }
}

// Fetch order items for specific orders
function getOrderItems($order_id) {
    global $conn;
    $items = [];
    $stmt = $conn->prepare("
        SELECT oi.*, p.name as product_name, p.image_path, p.category
        FROM order_items oi 
        LEFT JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

// Fetch order details with customer info
function getOrderDetails($order_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT o.*, u.username, u.email, u.phone, u.address,
               o.shipping_address, o.payment_method, o.total_amount, o.status, o.created_at
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();
    $stmt->close();
    return $order;
}

// Fetch users for user management
$users = [];
if ($res = $conn->query("SELECT id, username, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 200")) {
    while ($r = $res->fetch_assoc()) {
        $users[] = $r;
    }
}

// Fetch returns for returns management
$returns = [];
if ($res = $conn->query("
    SELECT r.*, o.id as order_id, u.username, p.name as product_name, p.price as product_price
    FROM returns r 
    LEFT JOIN orders o ON r.order_id = o.id 
    LEFT JOIN users u ON o.user_id = u.id 
    LEFT JOIN products p ON r.product_id = p.id 
    ORDER BY r.created_at DESC LIMIT 200
")) {
    while ($r = $res->fetch_assoc()) {
        $returns[] = $r;
    }
}

// Calculate additional metrics
$totalOrders = 0;
if ($res = $conn->query("SELECT COUNT(*) AS cnt FROM orders")) { 
    $r = $res->fetch_assoc(); $totalOrders = intval($r['cnt']); 
}

$avgOrderValue = 0;
if ($totalOrders > 0) {
    $avgOrderValue = $totalSales / $totalOrders;
}

// Top selling products
$topProducts = [];
if ($res = $conn->query("
    SELECT p.name, SUM(oi.quantity) as total_sold, SUM(oi.quantity * oi.price) as total_revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.status = 'Delivered'
    GROUP BY p.id, p.name
    ORDER BY total_revenue DESC
    LIMIT 5
")) {
    while ($r = $res->fetch_assoc()) {
        $topProducts[] = $r;
    }
}

// JSON for charts - FIXED: Ensure proper data formatting
$salesLabelsJson = json_encode($salesLabels);
$salesDataJson = json_encode($salesData);
$catLabelsJson = json_encode($catLabels);
$catDataJson = json_encode($catData);
$movementLabelsJson = json_encode($movementLabels);
$movementDataJson = json_encode($movementData);
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Dashboard — CampusShop</title>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous"/>

<style>
:root{
    --primary:#091bbe; --primary-2:#1231d1; --accent:#4591e7; --muted:#6b7280;
    --bg:#f3f4f6; --card:#fff; --success:#1059b9; --danger:#dc2626; --warning:#f59e0b;
    --sidebar-width:260px; --base-font:16px;
}
*{box-sizing:border-box;font-family: 'Poppins', Arial, sans-serif}
body{margin:0;background:var(--bg);color:#111827;font-size:var(--base-font)}
a{color:inherit;text-decoration:none}

/* Layout */
.sidebar{
    position:fixed; left:0; top:0; bottom:0; width:var(--sidebar-width);
    background:linear-gradient(180deg,var(--primary),var(--primary-2));
    color:#fff; padding:28px 18px; display:flex; flex-direction:column; gap:18px; z-index:1000;
}
.main-wrap{ margin-left:var(--sidebar-width); padding:28px 32px; min-height:100vh; transition:all .2s ease; }

/* Sidebar */
.brand{ display:flex; gap:12px; align-items:center }
.brand .logo{ width:52px;height:52px;background:#fff;color:var(--primary);border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px }
.brand h1{margin:0;font-size:20px;font-weight:700}
.brand p{margin:0;font-size:13px;opacity:0.95;color:#eaf3ff}

.menu{ margin-top:6px; display:flex; flex-direction:column; gap:8px; }
.menu a{ display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; font-weight:600; color:rgba(255,255,255,0.95); }
.menu a:hover{ background:rgba(255,255,255,0.06); }
.menu a.active{ background:rgba(255,255,255,0.08); }

.sidebar .footer{ margin-top:auto; display:flex; align-items:center; gap:12px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.04) }
.sidebar .footer .info{ font-size:14px }
.sidebar .footer .info small{ display:block; color:#e6f0ff; opacity:0.9; font-weight:500 }

/* Topbar & content */
.topbar{ display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px }
.search{ display:flex; align-items:center; gap:10px; background:var(--card); padding:10px 14px; border-radius:12px; box-shadow:0 6px 18px rgba(17,24,39,0.06); width:66% }
.search input{ border:0; outline:0; font-size:15px; width:100% }
.top-actions{ display:flex; align-items:center; gap:10px }

.card-row{ display:grid; grid-template-columns: repeat(5, 1fr); gap:16px; margin-bottom:20px }
.stat-card{ background:var(--card); padding:18px; border-radius:12px; box-shadow:0 6px 24px rgba(17,24,39,0.04) }
.stat-label{ color:var(--muted); font-size:14px }
.stat-value{ font-size:20px; font-weight:700; margin-top:6px }
.stat-warning { color: var(--warning); }
.stat-danger { color: var(--danger); }

/* Grid and panels */
.grid{ display:grid; grid-template-columns: 2fr 1fr; gap:16px; margin-bottom:18px }
.panel{ background:var(--card); padding:18px; border-radius:12px; box-shadow:0 6px 24px rgba(17,24,39,0.04) }

table{ width:100%; border-collapse:collapse; font-size:14px }
th, td{ padding:10px 12px; border-bottom:1px solid #f1f5f9; text-align:left; vertical-align:middle }
th{ background: linear-gradient(90deg,var(--primary),var(--primary-2)); color:#fff; font-weight:700; }
td img{ width:56px; height:56px; object-fit:cover; border-radius:8px }

.btn{ display:inline-block; padding:8px 12px; border-radius:8px; background:var(--primary); color:#fff; font-weight:700; border:none; cursor:pointer }
.btn.secondary{ background:#eef3ff; color:var(--primary); font-weight:700 }
.btn.warning{ background:var(--warning); color:#fff; }
.small{ font-size:0.85rem; padding:6px 8px; border-radius:6px }

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(2, 6, 23, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
}

.modal {
    background: var(--card);
    border-radius: 10px;
    padding: 18px;
    width: 560px;
    max-width: 96%;
    box-shadow: 0 10px 40px rgba(2, 6, 23, 0.2);
    position: relative;
    max-height: 90vh;
    overflow-y: auto;
}

body.dark .modal {
    background: #071229;
    border: 1px solid rgba(255, 255, 255, 0.03);
}

.modal-content {
    background: none;
    margin: 0;
    padding: 0;
    width: 100%;
}

/* Feedback specific styles */
.feedback-item {
    border: 1px solid #e6eefc;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 12px;
    background: #fafbfe;
}

.feedback-item.unread {
    background: #e8f4fd;
    border-left: 4px solid var(--primary);
}

.feedback-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10px;
}

.feedback-user {
    font-weight: 600;
    color: var(--primary);
}

.feedback-date {
    color: var(--muted);
    font-size: 12px;
}

.feedback-message {
    background: white;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 10px;
    border-left: 3px solid #e6eefc;
}

.feedback-reply {
    background: #f0f7ff;
    padding: 12px;
    border-radius: 6px;
    margin-top: 10px;
    border-left: 3px solid var(--success);
}

.feedback-status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-new { background: #ffebee; color: #d32f2f; }
.status-read { background: #e3f2fd; color: #1976d2; }
.status-replied { background: #e8f5e8; color: #388e3c; }

/* Stock warning styles */
.stock-warning { color: var(--warning); font-weight: 600; }
.stock-danger { color: var(--danger); font-weight: 600; }

/* Movement type badges */
.movement-sale { background: #e8f5e8; color: #388e3c; }
.movement-gift { background: #fff3cd; color: #856404; }
.movement-damaged { background: #f8d7da; color: #721c24; }
.movement-return { background: #d1ecf1; color: #0c5460; }
.movement-promotion { background: #d6d8db; color: #383d41; }
.movement-adjustment { background: #e2e3e5; color: #383d41; }

/* Order status badges */
.status-pending { background: #fff3cd; color: #856404; }
.status-processing { background: #d1ecf1; color: #0c5460; }
.status-shipped { background: #d4edda; color: #155724; }
.status-delivered { background: #e8f5e8; color: #388e3c; }
.status-cancelled { background: #f8d7da; color: #721c24; }
.status-refunded { background: #e2e3e5; color: #383d41; }

/* Return status badges */
.return-pending { background: #fff3cd; color: #856404; }
.return-approved { background: #d4edda; color: #155724; }
.return-rejected { background: #f8d7da; color: #721c24; }
.return-completed { background: #e8f5e8; color: #388e3c; }

/* Payment method badges */
.payment-cash { background: #d1f7c4; color: #2e7d32; }
.payment-mobile { background: #e6f3ff; color: #0b63ff; }
.payment-bank { background: #f0f4c3; color: #9e9d24; }
.payment-card { background: #f8bbd0; color: #ad1457; }
.payment-paypal { background: #e3f2fd; color: #1565c0; }

/* Print Styles */
@media print {
    body * {
        visibility: hidden;
    }
    #printableReport, #printableReport * {
        visibility: visible;
    }
    #printableReport {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background: white;
        padding: 20px;
    }
    .btn, .sidebar, .topbar, .panel:not(#printableReport) {
        display: none !important;
    }
}

/* spacing/responsive */
@media (max-width:1100px) {
    .card-row{ grid-template-columns: repeat(2,1fr) }
    .grid{ grid-template-columns: 1fr }
    .search{ width:100% }
}
@media (max-width:700px) {
    .sidebar{ display:none }
    .main-wrap{ margin-left:0; padding:14px }
}

/* dark theme */
body.dark{ background:#071029; color:#dbeafe }
body.dark .panel, body.dark .stat-card, body.dark .search, body.dark .modal{ background:#071229; color:#e6f0ff; box-shadow:none; border:1px solid rgba(255,255,255,0.03) }
body.dark th{ background: linear-gradient(90deg,#0b2b5b,#08306d) }
body.dark .feedback-item { background: #0a1a3a; border-color: #1e3a5f; }
body.dark .feedback-item.unread { background: #0a2342; }
body.dark .feedback-message { background: #071229; }
body.dark .feedback-reply { background: #0a2a4a; }

/* messages */
.message{ padding:10px 12px; border-radius:8px; margin-bottom:12px }
.success{ background: rgba(16,89,185,0.08); color:var(--success); border:1px solid rgba(16,89,185,0.2) }
.error{ background: rgba(220,38,38,0.06); color:var(--danger); border:1px solid rgba(220,38,38,0.2) }
.warning{ background: rgba(245,158,11,0.08); color:var(--warning); border:1px solid rgba(245,158,11,0.2) }

textarea { resize: vertical; min-height: 60px; }

/* Tab styles */
.tabs { display: flex; border-bottom: 1px solid #e6eefc; margin-bottom: 16px; }
.tab { padding: 10px 16px; cursor: pointer; border-bottom: 2px solid transparent; }
.tab.active { border-bottom-color: var(--primary); font-weight: 600; color: var(--primary); }
.tab-content { display: none; }
.tab-content.active { display: block; }

/* User role badges */
.role-admin { background: #dc2626; color: white; }
.role-manager { background: #f59e0b; color: white; }
.role-staff { background: #10b981; color: white; }
.role-customer { background: #6b7280; color: white; }

/* Dropdown styles */
.dropdown { position: relative; display: inline-block; }
.dropdown-content { display: none; position: absolute; background-color: white; min-width: 160px; box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); z-index: 1; border-radius: 8px; overflow: hidden; }
.dropdown-content a { color: black; padding: 12px 16px; text-decoration: none; display: block; border-bottom: 1px solid #f1f5f9; }
.dropdown-content a:hover { background-color: #f1f5f9; }
.dropdown:hover .dropdown-content { display: block; }

/* Chart containers */
.chart-container { position: relative; height: 300px; width: 100%; }

/* Product names in orders table */
.product-names {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Order details styles */
.order-details-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e6eefc;
}

.order-details-label {
    font-weight: 600;
    color: var(--muted);
}

.order-details-value {
    text-align: right;
}

.order-items-table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0;
}

.order-items-table th {
    background: #f1f5f9;
    padding: 8px;
    text-align: left;
    font-weight: 600;
    color: var(--muted);
}

.order-items-table td {
    padding: 8px;
    border-bottom: 1px solid #e6eefc;
}

.order-total {
    background: #f8fafc;
    font-weight: 700;
    font-size: 16px;
}
</style>
</head>
<body>
    <!-- SIDEBAR -->
    <aside class="sidebar" role="navigation" aria-label="Main sidebar">
        <div class="brand">
            <div class="logo"><img src="images/download.png" style="height: 40px; width: 40px;" alt="Logo"></div>
            <div>
                <h1>Bugema CampusShop</h1>
                <p>Admin Dashboard</p>
            </div>
        </div>

        <nav class="menu" aria-label="Sidebar menu">
            <a href="#" data-section="overview" class="active"><i class="fa fa-home"></i> Dashboard</a>
            <a href="#" data-section="products"><i class="fa fa-box"></i> Products</a>
            <a href="#" data-section="categories"><i class="fa fa-tags"></i> Categories</a>
            <a href="#" data-section="orders"><i class="fa fa-shopping-cart"></i> Order Management</a>
            <a href="#" data-section="deliveries"><i class="fa fa-truck"></i> Deliveries</a>
            <a href="#" data-section="transactions"><i class="fa fa-exchange-alt"></i> Transactions</a>
            <a href="#" data-section="movement-history"><i class="fa fa-history"></i> Movement History</a>
            <a href="#" data-section="users"><i class="fa fa-users"></i> User Management</a>
            <a href="#" data-section="returns"><i class="fa fa-undo"></i> Returns & Refunds</a>
            <a href="#" data-section="reports"><i class="fa fa-chart-line"></i> Reports</a>
            <a href="#" data-section="notifications"><i class="fa fa-bell"></i> Notifications</a>
            <a href="#" data-section="feedback"><i class="fa fa-comments"></i> User Feedback 
                <?php if ($unreadFeedbackCount > 0): ?>
                    <span style="background:#ff4757; color:white; border-radius:50%; width:18px; height:18px; display:inline-flex; align-items:center; justify-content:center; font-size:10px; margin-left:auto;">
                        <?php echo $unreadFeedbackCount; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="logout.php"><i class="fa fa-sign-out-alt"></i> Log Out</a>
        </nav>

        <div class="footer">
            <div class="info">
                <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
                <small>Administrator</small>
            </div>
            <div style="margin-left:auto; width:46px; height:46px; border-radius:8px; background:#fff; display:flex; align-items:center; justify-content:center; color:var(--primary)">A</div>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="main-wrap" id="mainWrap">
        <!-- topbar -->
        <div class="topbar">
            <div class="search" role="search" aria-label="Search products and orders">
                <i class="fa fa-search" style="color:var(--muted)"></i>
                <input id="searchInput" placeholder="Search products, orders, notifications..." aria-label="Search input" />
            </div>

            <div class="top-actions">
                <button id="themeToggle" title="Toggle theme" class="btn secondary" aria-pressed="false"><i class="fa fa-moon"></i></button>
                <div style="background:var(--card); padding:10px 12px; border-radius:10px; display:flex; align-items:center; gap:8px;">
                    <i class="fa fa-bell"></i> <strong style="margin-left:6px;"><?php echo $notifCount; ?></strong>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="text-align:right;">
                        <div style="font-weight:800;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                        <div style="font-size:13px; color:var(--muted);">Administrator</div>
                    </div>
                    <div style="width:46px; height:46px; background:#eef3ff; border-radius:10px; display:flex; align-items:center; justify-content:center; color:var(--primary)"><i class="fa fa-user"></i></div>
                </div>
            </div>
        </div>

        <!-- show message if exists -->
        <?php if (isset($message) && $message): ?>
            <div class="message <?php 
                if (stripos($message,'success')!==false || stripos($message,'added')!==false || stripos($message,'updated')!==false) echo 'success';
                elseif (stripos($message,'warning')!==false || stripos($message,'stock')!==false) echo 'warning';
                else echo 'error'; 
            ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Overview (default) -->
        <section id="overviewSection">
            <div class="card-row">
                <div class="stat-card">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value">UGX <?php echo number_format($totalSales); ?></div>
                    <div style="color:var(--muted); margin-top:8px; font-size:13px;">Gross sales for the period</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Orders</div>
                    <div class="stat-value"><?php echo $totalOrders; ?></div>
                    <div style="color:var(--muted); margin-top:8px; font-size:13px;">Number of successful transactions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Average Order Value</div>
                    <div class="stat-value">UGX <?php echo number_format($avgOrderValue, 2); ?></div>
                    <div style="color:var(--muted); margin-top:8px; font-size:13px;">Total Revenue / Total Orders</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Products</div>
                    <div class="stat-value"><?php echo $totalProducts; ?></div>
                    <div style="color:var(--muted); margin-top:8px; font-size:13px;">Items in catalog</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Pending Deliveries</div>
                    <div class="stat-value"><?php echo $pendingCount; ?></div>
                    <div style="color:var(--muted); margin-top:8px; font-size:13px;">Awaiting completion</div>
                </div>
            </div>
            
            <!-- Inventory Alerts -->
            <div class="card-row">
                <div class="stat-card">
                    <div class="stat-label">Stock Value</div>
                    <div class="stat-value">UGX <?php echo number_format($totalStockValue); ?></div>
                    <div style="color:var(--muted); margin-top:8px; font-size:13px;">Total inventory value</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Items in Stock</div>
                    <div class="stat-value <?php echo $totalStockItems < 100 ? 'stat-warning' : ''; ?>"><?php echo number_format($totalStockItems); ?></div>
                    <div style="color:var(--muted); margin-top:8px; font-size:13px;">
                        <?php if ($lowStockCount > 0): ?>
                            <span class="stock-warning"><?php echo $lowStockCount; ?> low stock items</span>
                        <?php else: ?>
                            Stock levels good
                        <?php endif; ?>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Products Sold</div>
                    <div class="stat-value"><?php echo number_format($totalSold); ?></div>
                    <div style="color:var(--muted); margin-top:8px; font-size:13px;">Completed sales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Gifts Given</div>
                    <div class="stat-value"><?php echo number_format($totalGifts); ?></div>
                    <div style="color:var(--muted); margin-top:8px; font-size:13px;">Promotional items</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Outflows</div>
                    <div class="stat-value <?php echo $totalProductsMoved > 0 ? 'stat-warning' : ''; ?>">
                        <?php echo number_format($totalProductsMoved); ?>
                    </div>
                    <div style="color:var(--muted); margin-top:8px; font-size:13px;">Sold + Gifts + Damaged</div>
                </div>
            </div>

            <div class="grid">
                <div class="panel">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <h2 style="margin:0; font-size:18px;">Sales Performance</h2>
                        <div>
                            <select id="rangeSelect" style="padding:8px; border-radius:8px;">
                                <option selected>Last 12 months</option>
                                <option>Last 6 months</option>
                                <option>Last 30 days</option>
                            </select>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="salesChart" aria-label="Sales chart"></canvas>
                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:12px;">
                    <div class="panel">
                        <h4 style="margin:0 0 10px 0;">Top 5 Selling Products</h4>
                        <?php if (count($topProducts) > 0): ?>
                            <ul style="padding:0; list-style:none; margin:0;">
                                <?php foreach ($topProducts as $product): ?>
                                    <li style="padding:8px 0; border-bottom:1px dashed #f1f5f9;">
                                        <span style="font-weight:600;"><?php echo htmlspecialchars($product['name']); ?></span>
                                        <span style="float:right; font-weight:600;">
                                            UGX <?php echo number_format($product['total_revenue']); ?>
                                        </span>
                                        <div style="font-size:12px; color:var(--muted);">
                                            Sold: <?php echo $product['total_sold']; ?> units
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p style="color:var(--muted); margin:0;">No sales data available.</p>
                        <?php endif; ?>
                    </div>

                    <div class="panel">
                        <h4 style="margin:0 0 10px 0;">Product Movement Distribution</h4>
                        <div class="chart-container">
                            <canvas id="movementChart"></canvas>
                        </div>
                    </div>

                    <div class="panel">
                        <h4 style="margin:0 0 10px 0;">Stock Alerts</h4>
                        <?php
                        $lowStockProducts = [];
                        if ($res = $conn->query("SELECT name, stock FROM products WHERE stock <= 5 AND name NOT LIKE 'CATEGORY_%' ORDER BY stock ASC LIMIT 5")) {
                            while ($row = $res->fetch_assoc()) {
                                $lowStockProducts[] = $row;
                            }
                        }
                        ?>
                        <?php if (count($lowStockProducts) > 0): ?>
                            <ul style="padding:0; list-style:none; margin:0;">
                                <?php foreach ($lowStockProducts as $product): ?>
                                    <li style="padding:8px 0; border-bottom:1px dashed #f1f5f9;">
                                        <span style="font-weight:600;"><?php echo htmlspecialchars($product['name']); ?></span>
                                        <span class="<?php echo $product['stock'] == 0 ? 'stock-danger' : 'stock-warning'; ?>" style="float:right;">
                                            <?php echo $product['stock']; ?> left
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div style="margin-top:10px;">
                                <a href="?section=products" class="btn warning small">Manage Stock</a>
                            </div>
                        <?php else: ?>
                            <p style="color:var(--muted); margin:0;">No stock alerts. All products have sufficient stock.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- Products Section -->
        <section id="productsSection" style="display:none;">
            <div class="panel" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Manage Products</h3>
                    <div>
                        <button class="btn secondary small" id="addNewBtn">Add New</button>
                    </div>
                </div>
            </div>

            <div class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Image</th>
                            <th>Name</th>
                            <th>Caption</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Category</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="productTable">
                        <?php if (count($products) === 0): ?>
                            <tr><td colspan="8">No products found.</td></tr>
                        <?php else: foreach ($products as $p):
                            $image_path = !empty($p['image_path']) ? htmlspecialchars($p['image_path']) : '';
                            $caption = htmlspecialchars($p['caption'] ?? 'No caption');
                            $stock_class = '';
                            if ($p['stock'] == 0) $stock_class = 'stock-danger';
                            elseif ($p['stock'] <= 5) $stock_class = 'stock-warning';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['id']); ?></td>
                            <td>
                                <?php if($image_path): ?>
                                    <img src="<?php echo $image_path; ?>" alt="Product image" style="width:56px; height:56px; object-fit:cover; border-radius:8px;">
                                <?php else: ?>
                                    <div style="width:56px;height:56px;background:#eef3ff;border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:12px;">No img</div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td title="<?php echo $caption; ?>"><?php echo strlen($caption) > 30 ? substr($caption, 0, 30) . '...' : $caption; ?></td>
                            <td>UGX <?php echo number_format($p['price']); ?></td>
                            <td class="<?php echo $stock_class; ?>"><?php echo htmlspecialchars($p['stock']); ?></td>
                            <td><?php echo htmlspecialchars($p['category']); ?></td>
                            <td>
                                <button class="small btn secondary" onclick="openEditProduct(<?php echo $p['id'];?>)">Edit</button>
                                <a class="small btn" style="background:#ff5b5b; padding:6px 8px;" href="?delete_product=<?php echo $p['id'];?>" onclick="return confirm('Delete this product? This cannot be undone.')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Quick Add (scroll target for 'Add New') -->
            <div class="panel" id="add-section" style="margin-top:16px;">
                <h4 style="margin:0 0 8px 0;">Add Product</h4>
                <form id="addProductForm" method="POST" action="admin_dashboard.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <input name="name" placeholder="Product name" required style="padding:10px; border-radius:8px; border:1px solid #e6eefc;" />
                        <input type="number" step="0.01" name="price" placeholder="Price (UGX)" required style="padding:10px; border-radius:8px; border:1px solid #e6eefc;" />
                        <input type="number" name="stock" placeholder="Stock qty" value="0" min="0" required style="padding:10px; border-radius:8px; border:1px solid #e6eefc;" />
                        <select name="category" required style="padding:10px; border-radius:8px; border:1px solid #e6eefc;">
                            <option value="">Category</option>
                            <?php foreach ($valid_categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <textarea name="caption" placeholder="Short caption" style="padding:10px; border-radius:8px; border:1px solid #e6eefc;"></textarea>
                        <input type="file" name="image" accept="image/*" />
                        <div style="display:flex; gap:10px;">
                            <button class="btn" type="submit">Add Product</button>
                            <button type="button" id="clearAdd" class="btn secondary">Clear</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <!-- Categories Section -->
        <section id="categoriesSection" style="display:none;">
            <div class="panel" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Manage Categories</h3>
                    <div>
                        <button class="btn secondary small" id="addCategoryBtn">Add New Category</button>
                    </div>
                </div>
            </div>

            <div class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>Category Name</th>
                            <th>Product Count</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="categoriesTable">
                        <?php if (count($categories) === 0): ?>
                            <tr><td colspan="3">No categories found.</td></tr>
                        <?php else: foreach ($categories as $cat): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cat['category']); ?></td>
                            <td><?php echo htmlspecialchars($cat['product_count']); ?></td>
                            <td>
                                <button class="small btn secondary" onclick="openEditCategory('<?php echo htmlspecialchars($cat['category']); ?>')">Edit</button>
                                <a class="small btn" style="background:#ff5b5b; padding:6px 8px;" 
                                   href="?delete_category=<?php echo urlencode($cat['category']); ?>" 
                                   onclick="return confirm('Delete category <?php echo htmlspecialchars(addslashes($cat['category'])); ?>? This will only work if no products are assigned to this category.')">
                                   Delete
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Add/Edit Category Form -->
            <div class="panel" id="category-form-section" style="margin-top:16px;">
                <h4 style="margin:0 0 8px 0;" id="categoryFormTitle">Add Category</h4>
                <form id="categoryForm" method="POST" action="admin_dashboard.php">
                    <input type="hidden" name="category_action" id="categoryAction" value="add">
                    <input type="hidden" name="category_id" id="categoryId" value="">
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <input id="categoryName" name="category_name" placeholder="Category name" required 
                               style="padding:10px; border-radius:8px; border:1px solid #e6eefc;" />
                        <div style="display:flex; gap:10px;">
                            <button class="btn" type="submit" id="categorySubmitBtn">Add Category</button>
                            <button type="button" id="clearCategory" class="btn secondary">Clear</button>
                            <button type="button" id="cancelEditCategory" class="btn secondary" style="display:none;">Cancel</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <!-- Order Management Section -->
        <section id="ordersSection" style="display:none;">
            <div class="panel" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Order Management</h3>
                    <div>
                        <button class="btn secondary small" id="refreshOrdersBtn">Refresh</button>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="tabs">
                    <div class="tab active" data-tab="all">All Orders (<?php echo count($orders); ?>)</div>
                    <div class="tab" data-tab="pending">Pending (<?php echo count(array_filter($orders, fn($o) => $o['status'] === 'Pending')); ?>)</div>
                    <div class="tab" data-tab="processing">Processing (<?php echo count(array_filter($orders, fn($o) => $o['status'] === 'Processing')); ?>)</div>
                    <div class="tab" data-tab="shipped">Shipped (<?php echo count(array_filter($orders, fn($o) => $o['status'] === 'Shipped')); ?>)</div>
                    <div class="tab" data-tab="delivered">Delivered (<?php echo count(array_filter($orders, fn($o) => $o['status'] === 'Delivered')); ?>)</div>
                </div>

                <div class="tab-content active" id="tab-all">
                    <table>
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Products Ordered</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($orders) === 0): ?>
                                <tr><td colspan="9" style="text-align:center; padding:20px;">No orders found.</td></tr>
                            <?php else: foreach ($orders as $order): 
                                $status_class = 'status-' . strtolower(str_replace(' ', '-', $order['status']));
                                $product_names = $order['product_names'] ?? 'No products';
                                $payment_method = $order['payment_method'] ?? 'Cash';
                                $payment_class = 'payment-' . strtolower(str_replace(' ', '-', $payment_method));
                                $item_count = $order['item_count'] ?? 0;
                            ?>
                            <tr>
                                <td><strong>#<?php echo htmlspecialchars($order['id']); ?></strong></td>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($order['username'] ?? 'Guest'); ?></div>
                                    <div style="font-size:12px; color:var(--muted);"><?php echo htmlspecialchars($order['email'] ?? 'N/A'); ?></div>
                                </td>
                                <td>
                                    <span class="btn secondary small"><?php echo $item_count; ?> items</span>
                                </td>
                                <td class="product-names" title="<?php echo htmlspecialchars($product_names); ?>">
                                    <?php echo htmlspecialchars($product_names); ?>
                                </td>
                                <td><strong>UGX <?php echo number_format($order['total_amount']); ?></strong></td>
                                <td>
                                    <span class="status-badge <?php echo $payment_class; ?>" style="padding:4px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase;">
                                        <?php echo htmlspecialchars($payment_method); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>" style="padding:4px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase;">
                                        <?php echo htmlspecialchars($order['status']); ?>
                                    </span>
                                </td>
                                <td style="font-size:12px;"><?php echo htmlspecialchars(date('M j, Y', strtotime($order['created_at'] ?? $order['id']))); ?></td>
                                <td>
                                    <button class="small btn secondary" onclick="viewOrderDetails(<?php echo $order['id']; ?>)">View</button>
                                    <div class="dropdown" style="display:inline-block;">
                                        <button class="small btn">Update</button>
                                        <div class="dropdown-content">
                                            <?php foreach ($order_statuses as $status): 
                                                if ($status !== $order['status']): ?>
                                                    <a href="?update_order_status=1&order_id=<?php echo $order['id']; ?>&status=<?php echo urlencode($status); ?>" 
                                                       onclick="return confirm('Change order #<?php echo $order['id']; ?> status to <?php echo $status; ?>?')">
                                                        <?php echo $status; ?>
                                                    </a>
                                                <?php endif;
                                            endforeach; ?>
                                        </div>
                                    </div>
                                    <a class="small btn" style="background:#ff5b5b" href="?delete_order=<?php echo $order['id']; ?>" onclick="return confirm('Delete order #<?php echo $order['id']; ?>? This action cannot be undone.')">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Deliveries Section -->
        <section id="deliveriesSection" style="display:none;">
            <div class="panel" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Pending Deliveries</h3>
                    <div><a href="#deliveries" class="btn secondary small" onclick="document.querySelector('#deliveriesSection').scrollIntoView({behavior:'smooth'})">View All</a></div>
                </div>
            </div>

            <div class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>User</th>
                            <th>Phone</th>
                            <th>Location</th>
                            <th>Payment</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="pendingTable">
                        <?php if (count($pendingDeliveries) === 0): ?>
                            <tr><td colspan="11">No pending deliveries.</td></tr>
                        <?php else: foreach ($pendingDeliveries as $p): 
                            $product_image = !empty($p['product_image']) ? htmlspecialchars($p['product_image']) : '';
                            $product_name = !empty($p['product_name']) ? htmlspecialchars($p['product_name']) : 'Unknown Product';
                            $quantity = isset($p['quantity']) ? intval($p['quantity']) : 1;
                            $created_date = date('M j, Y g:i A', strtotime($p['created_at']));
                            
                            // Check stock availability for pending deliveries
                            $stock_available = true;
                            if ($p['status'] === 'Pending' && $p['product_id'] && $p['product_id'] !== 'N/A') {
                                $stock_check = $conn->prepare("SELECT stock FROM products WHERE id = ?");
                                $stock_check->bind_param("i", $p['product_id']);
                                $stock_check->execute();
                                $stock_result = $stock_check->get_result();
                                if ($stock_row = $stock_result->fetch_assoc()) {
                                    $stock_available = ($stock_row['stock'] >= $quantity);
                                }
                                $stock_check->close();
                            }
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['id']); ?></td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:8px; min-width:200px;">
                                        <?php if($product_image && file_exists($product_image)): ?>
                                            <img src="<?php echo $product_image; ?>" alt="Product image" style="width:40px; height:40px; object-fit:cover; border-radius:6px;">
                                        <?php else: ?>
                                            <div style="width:40px;height:40px;background:#eef3ff;border-radius:6px; display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:10px;">No img</div>
                                        <?php endif; ?>
                                        <div>
                                            <div style="font-weight:600; font-size:13px;"><?php echo $product_name; ?></div>
                                            <div style="font-size:11px; color:var(--muted);">ID: <?php echo htmlspecialchars($p['product_id'] ?? 'N/A'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align:center;"><?php echo $quantity; ?></td>
                                <td><?php echo htmlspecialchars($p['username']); ?></td>
                                <td><?php echo htmlspecialchars($p['phone'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($p['location'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($p['payment_method'] ?? 'N/A'); ?></td>
                                <td><?php echo $p['amount'] ? 'UGX '.number_format($p['amount']):'N/A'; ?></td>
                                <td>
                                    <span style="padding:4px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; 
                                        <?php if($p['status'] === 'Completed'): ?>
                                            background:#e8f5e8; color:#388e3c;
                                        <?php elseif($p['status'] === 'Pending'): ?>
                                            background:#fff3cd; color:#856404;
                                        <?php else: ?>
                                            background:#f8d7da; color:#721c24;
                                        <?php endif; ?>
                                    ">
                                        <?php echo htmlspecialchars($p['status']); ?>
                                    </span>
                                    <?php if ($p['status'] === 'Pending' && !$stock_available): ?>
                                        <br><small style="color:var(--danger); font-size:10px;">Insufficient stock</small>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px; color:var(--muted);"><?php echo $created_date; ?></td>
                                <td>
                                    <?php if ($p['status'] === 'Pending'): ?>
                                        <?php if ($stock_available): ?>
                                            <a class="small btn secondary" href="?complete_delivery=<?php echo $p['id'];?>" onclick="return confirm('Mark this delivery as completed? This will reduce the product stock.')">Complete</a>
                                        <?php else: ?>
                                            <button class="small btn secondary" disabled title="Insufficient stock to complete delivery">Complete</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a class="small btn" style="background:#ff5b5b" href="?delete_delivery=<?php echo $p['id'];?>" onclick="return confirm('Delete this delivery?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Transactions Section -->
        <section id="transactionsSection" style="display:none;">
            <div class="panel" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Record Product Movement</h3>
                    <div>
                        <button class="btn secondary small" id="addMovementBtn">Add New Transaction</button>
                    </div>
                </div>
            </div>

            <div class="panel" id="movement-form-section">
                <h4 style="margin:0 0 8px 0;">Record Product Transaction</h4>
                <form id="movementForm" method="POST" action="admin_dashboard.php">
                    <input type="hidden" name="add_movement" value="1">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                        <div>
                            <label for="product_id" style="display:block; margin-bottom:5px; font-weight:600;">Product</label>
                            <select id="product_id" name="product_id" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6eefc;">
                                <option value="">Select Product</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>" data-stock="<?php echo $product['stock']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?> (Stock: <?php echo $product['stock']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="movement_type" style="display:block; margin-bottom:5px; font-weight:600;">Transaction Type</label>
                            <select id="movement_type" name="movement_type" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6eefc;">
                                <option value="">Select Type</option>
                                <?php foreach ($movement_types as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="quantity" style="display:block; margin-bottom:5px; font-weight:600;">Quantity</label>
                            <input type="number" id="quantity" name="quantity" min="1" required 
                                   style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6eefc;">
                        </div>
                        
                        <div>
                            <label for="received_by" style="display:block; margin-bottom:5px; font-weight:600;">Recipient (if any)</label>
                            <input type="text" id="received_by" name="received_by" 
                                   placeholder="Name of recipient or department"
                                   style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6eefc;">
                        </div>
                        
                        <div style="grid-column: span 2;">
                            <label for="remarks" style="display:block; margin-bottom:5px; font-weight:600;">Remarks</label>
                            <textarea id="remarks" name="remarks" rows="3" 
                                      placeholder="Additional notes about this transaction"
                                      style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6eefc;"></textarea>
                        </div>
                        
                        <div style="grid-column: span 2; display:flex; gap:10px;">
                            <button class="btn" type="submit">Record Transaction</button>
                            <button type="button" id="clearMovement" class="btn secondary">Clear Form</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <!-- Movement History Section -->
        <section id="movementHistorySection" style="display:none;">
            <div class="panel" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Product Movement History</h3>
                    <div>
                        <button class="btn secondary small" id="exportMovementsBtn">Export to Excel</button>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div style="margin-bottom:12px; display:flex; gap:10px; flex-wrap:wrap;">
                    <input type="text" id="movementSearch" placeholder="Search by product, recipient, remarks..." 
                           style="padding:8px 12px; border-radius:8px; border:1px solid #e6eefc; flex:1; min-width:200px;">
                    <select id="movementTypeFilter" style="padding:8px 12px; border-radius:8px; border:1px solid #e6eefc;">
                        <option value="">All Types</option>
                        <?php foreach ($movement_types as $type): ?>
                            <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" id="movementDateFrom" style="padding:8px 12px; border-radius:8px; border:1px solid #e6eefc;">
                    <input type="date" id="movementDateTo" style="padding:8px 12px; border-radius:8px; border:1px solid #e6eefc;">
                    <button class="btn secondary small" id="applyFiltersBtn">Apply Filters</button>
                    <button class="btn secondary small" id="clearFiltersBtn">Clear</button>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Type</th>
                            <th>Quantity</th>
                            <th>Issued By</th>
                            <th>Received By</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody id="movementTable">
                        <?php if (count($productMovements) === 0): ?>
                            <tr><td colspan="7">No product movement records found.</td></tr>
                        <?php else: foreach ($productMovements as $movement): 
                            $movement_class = 'movement-' . strtolower($movement['movement_type']);
                            $created_date = date('M j, Y g:i A', strtotime($movement['created_at']));
                        ?>
                        <tr>
                            <td style="font-size:12px; color:var(--muted);"><?php echo $created_date; ?></td>
                            <td><?php echo htmlspecialchars($movement['product_name'] ?? 'Unknown Product'); ?></td>
                            <td>
                                <span class="movement-badge <?php echo $movement_class; ?>" style="padding:4px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase;">
                                    <?php echo htmlspecialchars($movement['movement_type']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($movement['quantity']); ?></td>
                            <td><?php echo htmlspecialchars($movement['issued_by']); ?></td>
                            <td><?php echo htmlspecialchars($movement['received_by'] ?: 'N/A'); ?></td>
                            <td title="<?php echo htmlspecialchars($movement['remarks']); ?>">
                                <?php echo strlen($movement['remarks']) > 50 ? substr($movement['remarks'], 0, 50) . '...' : $movement['remarks']; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- User Management Section -->
        <section id="usersSection" style="display:none;">
            <div class="panel" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">User Management</h3>
                    <div>
                        <button class="btn secondary small" id="addUserBtn">Add New User</button>
                    </div>
                </div>
            </div>

            <div class="panel">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($users) === 0): ?>
                            <tr><td colspan="6">No users found.</td></tr>
                        <?php else: foreach ($users as $user): 
                            $role_class = 'role-' . $user['role'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['id']); ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td>
                                <span class="role-badge <?php echo $role_class; ?>" style="padding:4px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase;">
                                    <?php echo htmlspecialchars($user['role']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($user['created_at']))); ?></td>
                            <td>
                                <button class="small btn secondary" onclick="editUserRole(<?php echo $user['id']; ?>, '<?php echo $user['role']; ?>')">Edit Role</button>
                                <?php if ($user['id'] != $_SESSION['user_id'] ?? 0): ?>
                                    <a class="small btn" style="background:#ff5b5b" href="?delete_user=<?php echo $user['id']; ?>" onclick="return confirm('Delete user <?php echo htmlspecialchars($user['username']); ?>? This action cannot be undone.')">Delete</a>
                                <?php else: ?>
                                    <button class="small btn" style="background:#6b7280" disabled>Current User</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Returns & Refunds Section -->
        <section id="returnsSection" style="display:none;">
            <div class="panel" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Returns & Refunds Management</h3>
                    <div>
                        <span class="btn secondary small">
                            Total: <?php echo count($returns); ?> | 
                            Pending: <?php echo count(array_filter($returns, fn($r) => $r['status'] === 'Pending')); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="tabs">
                    <div class="tab active" data-tab="returns-all">All Returns</div>
                    <div class="tab" data-tab="returns-pending">Pending</div>
                    <div class="tab" data-tab="returns-approved">Approved</div>
                    <div class="tab" data-tab="returns-completed">Completed</div>
                </div>

                <div class="tab-content active" id="tab-returns-all">
                    <table>
                        <thead>
                            <tr>
                                <th>Return ID</th>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Reason</th>
                                <th>Refund Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($returns) === 0): ?>
                                <tr><td colspan="10" style="text-align:center; padding:20px;">No return requests found.</td></tr>
                            <?php else: foreach ($returns as $return): 
                                $status_class = 'return-' . strtolower($return['status']);
                                $refund_amount = $return['refund_amount'] ?? ($return['product_price'] ?? 0) * ($return['quantity'] ?? 1);
                            ?>
                            <tr>
                                <td><strong>#<?php echo htmlspecialchars($return['id']); ?></strong></td>
                                <td>#<?php echo htmlspecialchars($return['order_id']); ?></td>
                                <td><?php echo htmlspecialchars($return['username']); ?></td>
                                <td><?php echo htmlspecialchars($return['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($return['quantity'] ?? 1); ?></td>
                                <td title="<?php echo htmlspecialchars($return['reason']); ?>">
                                    <?php echo strlen($return['reason']) > 50 ? substr($return['reason'], 0, 50) . '...' : $return['reason']; ?>
                                </td>
                                <td>UGX <?php echo number_format($refund_amount); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>" style="padding:4px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase;">
                                        <?php echo htmlspecialchars($return['status']); ?>
                                    </span>
                                </td>
                                <td style="font-size:12px;"><?php echo htmlspecialchars(date('M j, Y', strtotime($return['created_at']))); ?></td>
                                <td>
                                    <?php if ($return['status'] === 'Pending'): ?>
                                        <a class="small btn secondary" href="?process_return=1&return_id=<?php echo $return['id']; ?>&action=approve" onclick="return confirm('Approve this return request? This will restock the product and process refund.')">Approve</a>
                                        <a class="small btn warning" href="?process_return=1&return_id=<?php echo $return['id']; ?>&action=reject" onclick="return confirm('Reject this return request?')">Reject</a>
                                    <?php elseif ($return['status'] === 'Approved'): ?>
                                        <a class="small btn" href="?process_return=1&return_id=<?php echo $return['id']; ?>&action=complete" onclick="return confirm('Mark this return as completed?')">Complete</a>
                                    <?php endif; ?>
                                    <button class="small btn secondary" onclick="viewReturnDetails(<?php echo $return['id']; ?>)">Details</button>
                                    <a class="small btn" style="background:#ff5b5b" href="?delete_return=<?php echo $return['id']; ?>" onclick="return confirm('Delete this return request?')">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Reports Section -->
        <section id="reportsSection" style="display:none;">
            <div class="panel" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Sales Reports & Analytics</h3>
                    <div>
                        <button class="btn secondary small" id="printReportBtn">Print Report</button>
                    </div>
                </div>
            </div>

            <!-- Print Report Form -->
            <div class="panel" style="margin-bottom:16px;">
                <h4 style="margin:0 0 12px 0;">Generate Printable Report</h4>
                <form method="GET" action="admin_dashboard.php" id="reportForm">
                    <input type="hidden" name="section" value="reports">
                    <div style="display:grid; grid-template-columns: 1fr 1fr auto auto; gap:10px; align-items:end;">
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:14px;">Report Type</label>
                            <select name="print_report" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6eefc;">
                                <option value="sales_summary">Sales Summary</option>
                                <option value="category_sales">Category Sales</option>
                                <option value="stock_report">Stock Report</option>
                                <option value="movement_report">Movement Report</option>
                                <option value="customer_behavior">Customer Behavior</option>
                                <option value="financial">Financial Report</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:14px;">Start Date</label>
                            <input type="date" name="start_date" value="<?php echo date('Y-m-01'); ?>" 
                                   style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6eefc;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:14px;">End Date</label>
                            <input type="date" name="end_date" value="<?php echo date('Y-m-t'); ?>" 
                                   style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6eefc;">
                        </div>
                        <div>
                            <button class="btn" type="submit">Generate Report</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Print Report Preview -->
            <?php if (isset($_GET['show_print']) && isset($_SESSION['print_report'])): 
                $report = $_SESSION['print_report'];
            ?>
            <div class="panel" id="printableReport">
                <div style="text-align:center; margin-bottom:20px; padding-bottom:15px; border-bottom:2px solid #e6eefc;">
                    <h2 style="margin:0; color:var(--primary);">CampusShop - <?php echo htmlspecialchars($report['title']); ?></h2>
                    <p style="margin:5px 0; color:var(--muted);">
                        Period: <?php echo htmlspecialchars($report['start_date']); ?> to <?php echo htmlspecialchars($report['end_date']); ?>
                    </p>
                    <p style="margin:0; color:var(--muted); font-size:14px;">
                        Generated on: <?php echo htmlspecialchars($report['generated_at']); ?>
                    </p>
                </div>

                <?php if ($report['type'] === 'sales_summary'): ?>
                <table style="width:100%; border-collapse:collapse; margin-top:15px;">
                    <thead>
                        <tr style="background:linear-gradient(90deg,var(--primary),var(--primary-2)); color:#fff;">
                            <th style="padding:12px; text-align:left;">Date</th>
                            <th style="padding:12px; text-align:left;">Order ID</th>
                            <th style="padding:12px; text-align:left;">Customer</th>
                            <th style="padding:12px; text-align:right;">Items</th>
                            <th style="padding:12px; text-align:right;">Total Quantity</th>
                            <th style="padding:12px; text-align:right;">Amount</th>
                            <th style="padding:12px; text-align:left;">Payment</th>
                            <th style="padding:12px; text-align:left;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_total = 0;
                        $total_orders = 0;
                        $total_items = 0;
                        $total_quantity = 0;
                        foreach ($report['data'] as $row): 
                            // Ensure we have numeric values
                            $product_count = intval($row['product_count'] ?? 0);
                            $total_amount = floatval($row['total_amount'] ?? 0);
                            $total_quantity_row = intval($row['total_quantity'] ?? 0);
                            
                            $grand_total += $total_amount;
                            $total_orders++;
                            $total_items += $product_count;
                            $total_quantity += $total_quantity_row;
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['sale_date'] ?? 'N/A'); ?></td>
                            <td style="padding:10px;">#<?php echo htmlspecialchars($row['order_id'] ?? 'N/A'); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                            <td style="padding:10px; text-align:right;"><?php echo number_format($product_count); ?></td>
                            <td style="padding:10px; text-align:right;"><?php echo number_format($total_quantity_row); ?></td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($total_amount, 2); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['payment_method'] ?? 'Cash'); ?></td>
                            <td style="padding:10px;">
                                <span style="padding:3px 6px; border-radius:8px; font-size:10px; font-weight:600; text-transform:uppercase;
                                    <?php if(($row['status'] ?? '') === 'Delivered'): ?>
                                        background:#e8f5e8; color:#388e3c;
                                    <?php elseif(($row['status'] ?? '') === 'Pending'): ?>
                                        background:#fff3cd; color:#856404;
                                    <?php else: ?>
                                        background:#f8d7da; color:#721c24;
                                    <?php endif; ?>
                                ">
                                    <?php echo htmlspecialchars($row['status'] ?? 'Unknown'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background:#f8fafc; font-weight:bold;">
                            <td style="padding:12px;" colspan="3">TOTAL</td>
                            <td style="padding:12px; text-align:right;"><?php echo number_format($total_items); ?></td>
                            <td style="padding:12px; text-align:right;"><?php echo number_format($total_quantity); ?></td>
                            <td style="padding:12px; text-align:right;">UGX <?php echo number_format($grand_total, 2); ?></td>
                            <td style="padding:12px;" colspan="2"><?php echo number_format($total_orders); ?> orders</td>
                        </tr>
                    </tbody>
                </table>
                <?php elseif ($report['type'] === 'category_sales'): ?>
                <table style="width:100%; border-collapse:collapse; margin-top:15px;">
                    <thead>
                        <tr style="background:linear-gradient(90deg,var(--primary),var(--primary-2)); color:#fff;">
                            <th style="padding:12px; text-align:left;">Category</th>
                            <th style="padding:12px; text-align:right;">Products</th>
                            <th style="padding:12px; text-align:right;">Total Sold</th>
                            <th style="padding:12px; text-align:right;">Total Revenue</th>
                            <th style="padding:12px; text-align:right;">Avg. Price</th>
                            <th style="padding:12px; text-align:right;">Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grand_total = 0;
                        $total_products = 0;
                        $total_sold = 0;
                        foreach ($report['data'] as $row) {
                            $grand_total += floatval($row['total_revenue'] ?? 0);
                            $total_products += intval($row['product_count'] ?? 0);
                            $total_sold += intval($row['total_sold'] ?? 0);
                        }
                        foreach ($report['data'] as $row): 
                            $product_count = intval($row['product_count'] ?? 0);
                            $total_sold_row = intval($row['total_sold'] ?? 0);
                            $total_revenue = floatval($row['total_revenue'] ?? 0);
                            $avg_price = floatval($row['avg_price'] ?? 0);
                            $percentage = $grand_total > 0 ? ($total_revenue / $grand_total) * 100 : 0;
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['category'] ?? 'N/A'); ?></td>
                            <td style="padding:10px; text-align:right;"><?php echo number_format($product_count); ?></td>
                            <td style="padding:10px; text-align:right;"><?php echo number_format($total_sold_row); ?></td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($total_revenue, 2); ?></td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($avg_price, 2); ?></td>
                            <td style="padding:10px; text-align:right;"><?php echo number_format($percentage, 1); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background:#f8fafc; font-weight:bold;">
                            <td style="padding:12px;">TOTAL</td>
                            <td style="padding:12px; text-align:right;"><?php echo number_format($total_products); ?></td>
                            <td style="padding:12px; text-align:right;"><?php echo number_format($total_sold); ?></td>
                            <td style="padding:12px; text-align:right;">UGX <?php echo number_format($grand_total, 2); ?></td>
                            <td style="padding:12px; text-align:right;">—</td>
                            <td style="padding:12px; text-align:right;">100%</td>
                        </tr>
                    </tbody>
                </table>
                <?php elseif ($report['type'] === 'stock_report'): ?>
                <table style="width:100%; border-collapse:collapse; margin-top:15px;">
                    <thead>
                        <tr style="background:linear-gradient(90deg,var(--primary),var(--primary-2)); color:#fff;">
                            <th style="padding:12px; text-align:left;">Product</th>
                            <th style="padding:12px; text-align:left;">Category</th>
                            <th style="padding:12px; text-align:right;">Price</th>
                            <th style="padding:12px; text-align:right;">Stock</th>
                            <th style="padding:12px; text-align:right;">Value</th>
                            <th style="padding:12px; text-align:center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_stock_value = 0;
                        $total_items = 0;
                        foreach ($report['data'] as $row): 
                            $stock = intval($row['stock'] ?? 0);
                            $price = floatval($row['price'] ?? 0);
                            $value = $stock * $price;
                            $total_stock_value += $value;
                            $total_items += $stock;
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['name'] ?? 'N/A'); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['category'] ?? 'N/A'); ?></td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($price, 2); ?></td>
                            <td style="padding:10px; text-align:right;"><?php echo number_format($stock); ?></td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($value, 2); ?></td>
                            <td style="padding:10px; text-align:center;">
                                <?php if ($stock == 0): ?>
                                    <span style="color:var(--danger); font-weight:600;">Out of Stock</span>
                                <?php elseif ($stock <= 5): ?>
                                    <span style="color:var(--warning); font-weight:600;">Low Stock</span>
                                <?php else: ?>
                                    <span style="color:var(--success); font-weight:600;">In Stock</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background:#f8fafc; font-weight:bold;">
                            <td style="padding:12px;" colspan="3">TOTAL</td>
                            <td style="padding:12px; text-align:right;"><?php echo number_format($total_items); ?></td>
                            <td style="padding:12px; text-align:right;">UGX <?php echo number_format($total_stock_value, 2); ?></td>
                            <td style="padding:12px; text-align:center;">—</td>
                        </tr>
                    </tbody>
                </table>
                <?php elseif ($report['type'] === 'movement_report'): ?>
                <table style="width:100%; border-collapse:collapse; margin-top:15px;">
                    <thead>
                        <tr style="background:linear-gradient(90deg,var(--primary),var(--primary-2)); color:#fff;">
                            <th style="padding:12px; text-align:left;">Date</th>
                            <th style="padding:12px; text-align:left;">Product</th>
                            <th style="padding:12px; text-align:left;">Type</th>
                            <th style="padding:12px; text-align:right;">Quantity</th>
                            <th style="padding:12px; text-align:left;">Issued By</th>
                            <th style="padding:12px; text-align:left;">Received By</th>
                            <th style="padding:12px; text-align:left;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_quantity = 0;
                        foreach ($report['data'] as $row): 
                            $quantity = intval($row['quantity'] ?? 0);
                            $total_quantity += $quantity;
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['movement_date'] ?? 'N/A'); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['product_name'] ?? 'N/A'); ?></td>
                            <td style="padding:10px;">
                                <span style="padding:3px 6px; border-radius:8px; font-size:10px; font-weight:600; text-transform:uppercase;
                                    <?php if(($row['movement_type'] ?? '') === 'Sale'): ?>
                                        background:#e8f5e8; color:#388e3c;
                                    <?php elseif(($row['movement_type'] ?? '') === 'Return'): ?>
                                        background:#d1ecf1; color:#0c5460;
                                    <?php elseif(($row['movement_type'] ?? '') === 'Gift'): ?>
                                        background:#fff3cd; color:#856404;
                                    <?php else: ?>
                                        background:#f8d7da; color:#721c24;
                                    <?php endif; ?>
                                ">
                                    <?php echo htmlspecialchars($row['movement_type'] ?? 'Unknown'); ?>
                                </span>
                            </td>
                            <td style="padding:10px; text-align:right;"><?php echo number_format($quantity); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['issued_by'] ?? 'N/A'); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['received_by'] ?? 'N/A'); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['remarks'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background:#f8fafc; font-weight:bold;">
                            <td style="padding:12px;" colspan="3">TOTAL MOVEMENTS</td>
                            <td style="padding:12px; text-align:right;"><?php echo number_format($total_quantity); ?></td>
                            <td style="padding:12px;" colspan="3"><?php echo count($report['data']); ?> records</td>
                        </tr>
                    </tbody>
                </table>
                <?php elseif ($report['type'] === 'customer_behavior'): ?>
                <table style="width:100%; border-collapse:collapse; margin-top:15px;">
                    <thead>
                        <tr style="background:linear-gradient(90deg,var(--primary),var(--primary-2)); color:#fff;">
                            <th style="padding:12px; text-align:left;">Customer</th>
                            <th style="padding:12px; text-align:left;">Email</th>
                            <th style="padding:12px; text-align:right;">Total Orders</th>
                            <th style="padding:12px; text-align:right;">Total Spent</th>
                            <th style="padding:12px; text-align:right;">Avg. Order Value</th>
                            <th style="padding:12px; text-align:left;">Last Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_customers = 0;
                        $total_all_orders = 0;
                        $total_all_spent = 0;
                        foreach ($report['data'] as $row): 
                            $total_orders = intval($row['total_orders'] ?? 0);
                            $total_spent = floatval($row['total_spent'] ?? 0);
                            $avg_order_value = floatval($row['avg_order_value'] ?? 0);
                            $total_customers++;
                            $total_all_orders += $total_orders;
                            $total_all_spent += $total_spent;
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['username'] ?? 'N/A'); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['email'] ?? 'N/A'); ?></td>
                            <td style="padding:10px; text-align:right;"><?php echo number_format($total_orders); ?></td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($total_spent, 2); ?></td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($avg_order_value, 2); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($row['last_order_date'] ? date('M j, Y', strtotime($row['last_order_date'])) : 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background:#f8fafc; font-weight:bold;">
                            <td style="padding:12px;">TOTAL CUSTOMERS</td>
                            <td style="padding:12px;"><?php echo number_format($total_customers); ?></td>
                            <td style="padding:12px; text-align:right;"><?php echo number_format($total_all_orders); ?></td>
                            <td style="padding:12px; text-align:right;">UGX <?php echo number_format($total_all_spent, 2); ?></td>
                            <td style="padding:12px; text-align:right;">UGX <?php echo number_format($total_all_orders > 0 ? $total_all_spent / $total_all_orders : 0, 2); ?></td>
                            <td style="padding:12px;">—</td>
                        </tr>
                    </tbody>
                </table>
                <?php elseif ($report['type'] === 'financial'): ?>
                <table style="width:100%; border-collapse:collapse; margin-top:15px;">
                    <thead>
                        <tr style="background:linear-gradient(90deg,var(--primary),var(--primary-2)); color:#fff;">
                            <th style="padding:12px; text-align:left;">Metric</th>
                            <th style="padding:12px; text-align:right;">Amount (UGX)</th>
                            <th style="padding:12px; text-align:left;">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px; font-weight:600;">Total Revenue</td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($report['data']['total_revenue'] ?? 0, 2); ?></td>
                            <td style="padding:10px;">Revenue from completed orders</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px; font-weight:600;">Total Refunds</td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($report['data']['total_refunds'] ?? 0, 2); ?></td>
                            <td style="padding:10px;">Amount refunded to customers</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px; font-weight:600;">Net Revenue</td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($report['data']['net_revenue'] ?? 0, 2); ?></td>
                            <td style="padding:10px;">Revenue after refunds</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px; font-weight:600;">Total Orders</td>
                            <td style="padding:10px; text-align:right;"><?php echo number_format($report['data']['total_orders'] ?? 0); ?></td>
                            <td style="padding:10px;">Number of orders placed</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px; font-weight:600;">Average Order Value</td>
                            <td style="padding:10px; text-align:right;">UGX <?php echo number_format($report['data']['avg_order_value'] ?? 0, 2); ?></td>
                            <td style="padding:10px;">Average amount per order</td>
                        </tr>
                        <tr style="background:#f8fafc; font-weight:bold;">
                            <td style="padding:12px;">REPORT PERIOD</td>
                            <td style="padding:12px; text-align:right;" colspan="2">
                                <?php echo htmlspecialchars($report['data']['start_date'] ?? $report['start_date']); ?> 
                                to 
                                <?php echo htmlspecialchars($report['data']['end_date'] ?? $report['end_date']); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php endif; ?>

                <div style="margin-top:20px; text-align:center;">
                    <button class="btn" onclick="window.print()">Print Report</button>
                    <a href="admin_dashboard.php?section=reports" class="btn secondary">Close</a>
                </div>
            </div>
            <?php unset($_SESSION['print_report']); endif; ?>

            <!-- Existing Charts -->
            <div class="panel">
                <h3 style="margin:0 0 10px 0;">Sales Analytics</h3>
                <p style="color:var(--muted)">Sales summary and category distribution.</p>
                <div style="display:flex; gap:16px; margin-top:12px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:350px; background:var(--card); padding:12px; border-radius:8px;">
                        <div class="chart-container">
                            <canvas id="salesChart2"></canvas>
                        </div>
                    </div>
                    <div style="width:320px; background:var(--card); padding:12px; border-radius:8px;">
                        <div class="chart-container">
                            <canvas id="catChart2"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Notifications Section -->
        <section id="notificationsSection" style="display:none;">
            <div class="panel" style="margin-bottom:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Notifications</h3>
                    <div><a href="#notifications" class="btn secondary small" onclick="document.querySelector('#notificationsSection').scrollIntoView({behavior:'smooth'})">View All</a></div>
                </div>
            </div>

            <div class="panel" style="margin-bottom:12px;">
                <h4 style="margin:0 0 8px 0;">Send New Notification</h4>
                <form method="POST" action="admin_dashboard.php">
                    <textarea name="message" placeholder="Message to users" required style="width:100%; min-height:80px; padding:10px; border-radius:8px; border:1px solid #e6eefc;"></textarea>
                    <div style="margin-top:8px; text-align:right;">
                        <button class="btn" type="submit" name="send_notification">Send</button>
                    </div>
                </form>
            </div>

            <div class="panel">
                <h4 style="margin:0 0 8px 0;">Recent Notifications</h4>
                <table>
                    <thead><tr><th>ID</th><th>Message</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (count($notifications) === 0): ?>
                            <tr><td colspan="4">No notifications found.</td></tr>
                        <?php else: foreach ($notifications as $n): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($n['id']); ?></td>
                                <td><?php echo htmlspecialchars($n['message']); ?></td>
                                <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($n['created_at']))); ?></td>
                                <td>
                                    <button class="small btn secondary edit-btn" data-id="<?php echo $n['id']; ?>" data-message="<?php echo htmlspecialchars($n['message']); ?>">Edit</button>
                                    <a class="small btn" style="background:#ff5b5b" href="?delete_notification=<?php echo $n['id'];?>" onclick="return confirm('Delete notification?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Feedback Section -->
        <section id="feedbackSection" style="display:none;">
            <div class="panel" style="margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">User Feedback Management</h3>
                    <div>
                        <span class="btn secondary small">
                            Total: <?php echo $totalFeedbackCount; ?> | 
                            Unread: <span style="color:#ff4757;"><?php echo $unreadFeedbackCount; ?></span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="panel">
                <?php if (count($feedbackList) === 0): ?>
                    <div style="text-align:center; padding:40px; color:var(--muted);">
                        <i class="fa fa-comments" style="font-size:48px; margin-bottom:16px; opacity:0.5;"></i>
                        <p>No user feedback received yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($feedbackList as $feedback): ?>
                        <div class="feedback-item <?php echo $feedback['status'] === 'new' ? 'unread' : ''; ?>">
                            <div class="feedback-header">
                                <div>
                                    <span class="feedback-user">
                                        <?php echo htmlspecialchars($feedback['name']); ?>
                                        <?php if ($feedback['user_id']): ?>
                                            <small>(User ID: <?php echo $feedback['user_id']; ?>)</small>
                                        <?php endif; ?>
                                    </span>
                                    <div class="feedback-date">
                                        <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($feedback['created_at']))); ?>
                                        <?php if ($feedback['status'] === 'new'): ?>
                                            <span class="feedback-status status-new">New</span>
                                        <?php elseif ($feedback['status'] === 'read'): ?>
                                            <span class="feedback-status status-read">Read</span>
                                        <?php elseif ($feedback['status'] === 'replied'): ?>
                                            <span class="feedback-status status-replied">Replied</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="display:flex; gap:8px;">
                                    <?php if ($feedback['status'] === 'new'): ?>
                                        <a href="?mark_feedback_read=<?php echo $feedback['id']; ?>" class="small btn secondary">Mark Read</a>
                                    <?php endif; ?>
                                    <button class="small btn secondary reply-feedback-btn" 
                                            data-id="<?php echo $feedback['id']; ?>"
                                            data-email="<?php echo htmlspecialchars($feedback['email']); ?>"
                                            data-name="<?php echo htmlspecialchars($feedback['name']); ?>">
                                        <?php echo $feedback['admin_reply'] ? 'Edit Reply' : 'Reply'; ?>
                                    </button>
                                    <a href="?delete_feedback=<?php echo $feedback['id']; ?>" class="small btn" style="background:#ff5b5b;" onclick="return confirm('Delete this feedback?')">Delete</a>
                                </div>
                            </div>
                            
                            <div class="feedback-message">
                                <strong>Message:</strong><br>
                                <?php echo nl2br(htmlspecialchars($feedback['message'])); ?>
                            </div>
                            
                            <?php if ($feedback['email']): ?>
                                <div style="margin-top:8px; font-size:13px; color:var(--muted);">
                                    <strong>Email:</strong> <?php echo htmlspecialchars($feedback['email']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($feedback['admin_reply']): ?>
                                <div class="feedback-reply">
                                    <strong>Your Reply (<?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($feedback['replied_at']))); ?>):</strong><br>
                                    <?php echo nl2br(htmlspecialchars($feedback['admin_reply'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Edit Product Modal -->
        <div id="editProductModal" style="display:none;">
            <div class="modal-overlay">
                <div class="modal" role="dialog" aria-modal="true" aria-label="Edit product">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3>Edit Product</h3>
                        <button class="btn secondary" onclick="closeEditProduct()">Close</button>
                    </div>
                    <form id="editProductForm" method="POST" action="admin_dashboard.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" id="editProductHiddenId" name="id">
                        
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <input id="editName" name="name" placeholder="Product name" required style="padding:10px; border-radius:8px; border:1px solid #e6eefc;" />
                            <input type="number" step="0.01" id="editPrice" name="price" placeholder="Price (UGX)" required style="padding:10px; border-radius:8px; border:1px solid #e6eefc;" />
                            <input type="number" id="editStock" name="stock" placeholder="Stock qty" min="0" required style="padding:10px; border-radius:8px; border:1px solid #e6eefc;" />
                            <select id="editCategory" name="category" required style="padding:10px; border-radius:8px; border:1px solid #e6eefc;">
                                <option value="">Category</option>
                                <?php foreach ($valid_categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <textarea id="editCaption" name="caption" placeholder="Short caption" style="padding:10px; border-radius:8px; border:1px solid #e6eefc; min-height:60px;"></textarea>
                            <input type="file" name="image" accept="image/*" />
                            <div id="editCurrentImage" style="margin:10px 0;"></div>
                            <div style="display:flex; gap:10px;">
                                <button class="btn" type="submit">Update Product</button>
                                <button type="button" class="btn secondary" onclick="closeEditProduct()">Cancel</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Notification Modal -->
        <div id="editNotificationModal" style="display:none;">
            <div class="modal-overlay" id="notifOverlay">
                <div class="modal" role="dialog" aria-modal="true" aria-label="Edit notification">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3>Edit Notification</h3>
                        <button class="btn secondary" id="closeNotifModal">Close</button>
                    </div>
                    <form id="editForm" method="POST" action="admin_dashboard.php">
                        <input type="hidden" name="edit_notification" value="1">
                        <input type="hidden" id="notif_id" name="nid">
                        <label for="notif_message">Message:</label>
                        <textarea id="notif_message" name="nmessage" rows="4" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6eefc; margin-top:10px;"></textarea>
                        <div style="text-align:right; margin-top:10px;">
                            <button type="submit" class="btn">Save Changes</button>
                            <button type="button" class="btn secondary" id="cancelEdit">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Reply Feedback Modal -->
        <div id="replyFeedbackModal" style="display:none;">
            <div class="modal-overlay" id="replyOverlay">
                <div class="modal" role="dialog" aria-modal="true" aria-label="Reply to feedback">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3>Reply to Feedback</h3>
                        <button class="btn secondary" id="closeReplyModal">Close</button>
                    </div>
                    <form id="replyForm" method="POST" action="admin_dashboard.php">
                        <input type="hidden" name="reply_feedback" value="1">
                        <input type="hidden" id="feedback_id" name="feedback_id">
                        
                        <div style="margin-bottom:15px;">
                            <strong>To:</strong> 
                            <span id="replyUserName"></span> 
                            (<span id="replyUserEmail"></span>)
                        </div>
                        
                        <div style="background:#f8f9fa; padding:12px; border-radius:6px; margin-bottom:15px;">
                            <strong>Original Message:</strong>
                            <div id="originalMessage" style="margin-top:8px; font-style:italic;"></div>
                        </div>
                        
                        <label for="admin_reply">Your Reply:</label>
                        <textarea id="admin_reply" name="admin_reply" rows="6" required 
                                  placeholder="Type your response to the user..."
                                  style="width:100%; padding:12px; border-radius:8px; border:1px solid #e6eefc; margin-top:8px;"></textarea>
                        
                        <div style="text-align:right; margin-top:15px;">
                            <button type="submit" class="btn">Send Reply</button>
                            <button type="button" class="btn secondary" id="cancelReply">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Order Details Modal -->
        <div id="orderDetailsModal" class="modal-overlay" style="display:none;">
            <div class="modal" role="dialog" aria-modal="true" aria-label="Order details" style="max-width: 700px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3>Order Details</h3>
                    <button class="btn secondary" onclick="document.getElementById('orderDetailsModal').style.display='none'">Close</button>
                </div>
                <div id="orderDetailsContent" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Order details will be loaded here via AJAX -->
                </div>
            </div>
        </div>

        <!-- Edit User Role Modal -->
        <div id="editUserRoleModal" class="modal-overlay" style="display:none;">
            <div class="modal" role="dialog" aria-modal="true" aria-label="Edit user role">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3>Edit User Role</h3>
                    <button class="btn secondary" onclick="document.getElementById('editUserRoleModal').style.display='none'">Close</button>
                </div>
                <form id="editUserRoleForm" method="POST" action="admin_dashboard.php">
                    <input type="hidden" name="update_user_role" value="1">
                    <input type="hidden" id="user_id" name="user_id">
                    
                    <div style="margin-bottom:15px;">
                        <label for="user_role">Select Role:</label>
                        <select id="user_role" name="role" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #e6eefc; margin-top:8px;">
                            <?php foreach ($user_roles as $role): ?>
                                <option value="<?php echo $role; ?>"><?php echo ucfirst($role); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="text-align:right; margin-top:15px;">
                        <button type="submit" class="btn">Update Role</button>
                        <button type="button" class="btn secondary" onclick="document.getElementById('editUserRoleModal').style.display='none'">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Return Details Modal -->
        <div id="returnDetailsModal" class="modal-overlay" style="display:none;">
            <div class="modal" role="dialog" aria-modal="true" aria-label="Return details" style="max-width: 600px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3>Return Request Details</h3>
                    <button class="btn secondary" onclick="document.getElementById('returnDetailsModal').style.display='none'">Close</button>
                </div>
                <div id="returnDetailsContent" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Return details will be loaded here via AJAX -->
                </div>
            </div>
        </div>
    </div>

<script>
    // ------------------
    // Client-side JS
    // ------------------

    // Section navigation (sidebar)
    const menuLinks = document.querySelectorAll('.menu a[data-section]');
    const sections = {
        overview: document.getElementById('overviewSection'),
        products: document.getElementById('productsSection'),
        categories: document.getElementById('categoriesSection'),
        orders: document.getElementById('ordersSection'),
        deliveries: document.getElementById('deliveriesSection'),
        transactions: document.getElementById('transactionsSection'),
        'movement-history': document.getElementById('movementHistorySection'),
        users: document.getElementById('usersSection'),
        returns: document.getElementById('returnsSection'),
        reports: document.getElementById('reportsSection'),
        notifications: document.getElementById('notificationsSection'),
        feedback: document.getElementById('feedbackSection'),
    };

    function showSection(name) {
        Object.values(sections).forEach(s => { if (s) s.style.display = 'none'; });
        menuLinks.forEach(a => a.classList.remove('active'));
        if (sections[name]) sections[name].style.display = '';
        menuLinks.forEach(a => { if (a.dataset.section === name) a.classList.add('active'); });
        document.getElementById('mainWrap').scrollTop = 0;
        
        // If showing reports section and there's a print report to show
        if (name === 'reports' && window.location.search.includes('show_print=true')) {
            setTimeout(() => {
                document.getElementById('printableReport')?.scrollIntoView({behavior: 'smooth'});
            }, 100);
        }
    }

    menuLinks.forEach(a => {
        a.addEventListener('click', e => {
            e.preventDefault();
            const name = a.dataset.section;
            showSection(name);
            // Update URL without reloading
            history.pushState(null, '', `?section=${name}`);
        });
    });

    // Check URL for section parameter
    const urlParams = new URLSearchParams(window.location.search);
    const sectionParam = urlParams.get('section');
    if (sectionParam && sections[sectionParam]) {
        showSection(sectionParam);
    } else {
        showSection('overview');
    }

    // Search functionality
    document.getElementById('searchInput').addEventListener('input', function(e) {
        const q = e.target.value.toLowerCase().trim();
        
        // Search in products
        document.querySelectorAll('#productTable tr').forEach(r => {
            const txt = r.innerText.toLowerCase();
            r.style.display = txt.includes(q) ? '' : 'none';
        });
        
        // Search in pending deliveries
        document.querySelectorAll('#pendingTable tr').forEach(r => {
            const txt = r.innerText.toLowerCase();
            r.style.display = txt.includes(q) ? '' : 'none';
        });
        
        // Search in categories
        document.querySelectorAll('#categoriesTable tr').forEach(r => {
            const txt = r.innerText.toLowerCase();
            r.style.display = txt.includes(q) ? '' : 'none';
        });
        
        // Search in notifications
        document.querySelectorAll('#notificationsSection table tbody tr').forEach(r => {
            const txt = r.innerText.toLowerCase();
            r.style.display = txt.includes(q) ? '' : 'none';
        });
        
        // Search in feedback
        document.querySelectorAll('.feedback-item').forEach(item => {
            const txt = item.innerText.toLowerCase();
            item.style.display = txt.includes(q) ? '' : 'none';
        });
        
        // Search in movement history
        document.querySelectorAll('#movementTable tr').forEach(r => {
            const txt = r.innerText.toLowerCase();
            r.style.display = txt.includes(q) ? '' : 'none';
        });
        
        // Search in orders
        document.querySelectorAll('#ordersSection table tbody tr').forEach(r => {
            const txt = r.innerText.toLowerCase();
            r.style.display = txt.includes(q) ? '' : 'none';
        });
        
        // Search in users
        document.querySelectorAll('#usersSection table tbody tr').forEach(r => {
            const txt = r.innerText.toLowerCase();
            r.style.display = txt.includes(q) ? '' : 'none';
        });
        
        // Search in returns
        document.querySelectorAll('#returnsSection table tbody tr').forEach(r => {
            const txt = r.innerText.toLowerCase();
            r.style.display = txt.includes(q) ? '' : 'none';
        });
    });

    // Theme toggle
    const themeToggle = document.getElementById('themeToggle');
    function applyTheme() {
        const t = localStorage.getItem('campus_theme') || 'light';
        if (t === 'dark') { 
            document.body.classList.add('dark'); 
            themeToggle.innerHTML = '<i class="fa fa-sun"></i>'; 
            themeToggle.setAttribute('aria-pressed', 'true'); 
        }
        else { 
            document.body.classList.remove('dark'); 
            themeToggle.innerHTML = '<i class="fa fa-moon"></i>'; 
            themeToggle.setAttribute('aria-pressed', 'false'); 
        }
    }
    themeToggle.addEventListener('click', function() {
        const cur = document.body.classList.contains('dark') ? 'dark' : 'light';
        const next = cur === 'dark' ? 'light' : 'dark';
        localStorage.setItem('campus_theme', next);
        applyTheme();
    });
    applyTheme();

    // Close modals when clicking overlay
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                overlay.style.display = 'none';
            }
        });
    });

    // Add New scrolls
    document.getElementById('addNewBtn').addEventListener('click', function() {
        document.getElementById('add-section').scrollIntoView({behavior:'smooth', block:'center'});
    });

    // Clear Add form
    document.getElementById('clearAdd').addEventListener('click', function() {
        document.getElementById('addProductForm').reset();
    });

    // Edit Product
    function openEditProduct(id) {
        console.log('Fetching product with ID:', id);
        fetch('admin_dashboard.php?fetch_product=' + encodeURIComponent(id))
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status + ' ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.error) {
                    console.error('Fetch error:', data.error);
                    alert(data.error);
                    return;
                }
                // Populate form fields
                document.getElementById('editProductHiddenId').value = data.id;
                document.getElementById('editName').value = data.name || '';
                document.getElementById('editPrice').value = data.price || '';
                document.getElementById('editStock').value = data.stock || 0;
                document.getElementById('editCategory').value = data.category || '';
                document.getElementById('editCaption').value = data.caption || '';

                // Show current image
                if (data.image_path) {
                    document.getElementById('editCurrentImage').innerHTML =
                        '<img src="' + data.image_path + '" alt="Current image" style="width:100px; height:100px; object-fit:cover; border-radius:8px; margin-bottom:5px;">' +
                        '<small style="color:var(--muted);">New image will replace this</small>';
                } else {
                    document.getElementById('editCurrentImage').innerHTML =
                        '<div style="width:100px; height:100px; background:#eef3ff; border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--muted);">No image</div>';
                }

                document.getElementById('editProductModal').style.display = 'block';
            })
            .catch(err => {
                console.error('Error fetching product:', err);
                alert('Could not fetch product information. Please try again or check the console for details.');
            });
    }

    function closeEditProduct() {
        document.getElementById('editProductModal').style.display = 'none';
    }

    // Category Management Functions
    function openEditCategory(categoryName) {
        document.getElementById('categoryAction').value = 'edit';
        document.getElementById('categoryFormTitle').textContent = 'Edit Category';
        document.getElementById('categoryName').value = categoryName;
        document.getElementById('categoryId').value = categoryName;
        document.getElementById('categorySubmitBtn').textContent = 'Update Category';
        document.getElementById('cancelEditCategory').style.display = 'inline-block';
        
        document.getElementById('category-form-section').scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    function resetCategoryForm() {
        document.getElementById('categoryForm').reset();
        document.getElementById('categoryAction').value = 'add';
        document.getElementById('categoryFormTitle').textContent = 'Add Category';
        document.getElementById('categorySubmitBtn').textContent = 'Add Category';
        document.getElementById('cancelEditCategory').style.display = 'none';
        document.getElementById('categoryId').value = '';
    }

    // Event Listeners for Category Management
    document.getElementById('addCategoryBtn').addEventListener('click', function() {
        resetCategoryForm();
        document.getElementById('category-form-section').scrollIntoView({behavior: 'smooth', block: 'center'});
    });

    document.getElementById('clearCategory').addEventListener('click', resetCategoryForm);
    document.getElementById('cancelEditCategory').addEventListener('click', resetCategoryForm);

    // Print Report functionality
    document.getElementById('printReportBtn')?.addEventListener('click', function() {
        document.getElementById('reportForm').scrollIntoView({behavior: 'smooth', block: 'center'});
    });

    // Edit Notification
    const editNotificationModal = document.getElementById('editNotificationModal');
    const closeNotifModal = document.getElementById('closeNotifModal');
    const cancelEdit = document.getElementById('cancelEdit');
    const editBtns = document.querySelectorAll('.edit-btn');

    editBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-id');
            const message = btn.getAttribute('data-message');
            document.getElementById('notif_id').value = id;
            document.getElementById('notif_message').value = message;
            editNotificationModal.style.display = 'block';
        });
    });

    if (closeNotifModal) closeNotifModal.addEventListener('click', () => editNotificationModal.style.display = 'none');
    if (cancelEdit) cancelEdit.addEventListener('click', () => editNotificationModal.style.display = 'none');

    // Feedback Reply Functionality
    const replyFeedbackModal = document.getElementById('replyFeedbackModal');
    const closeReplyModal = document.getElementById('closeReplyModal');
    const cancelReply = document.getElementById('cancelReply');
    const replyBtns = document.querySelectorAll('.reply-feedback-btn');

    replyBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const feedbackId = btn.getAttribute('data-id');
            const userName = btn.getAttribute('data-name');
            const userEmail = btn.getAttribute('data-email');
            
            // Find the original message from the feedback item
            const feedbackItem = btn.closest('.feedback-item');
            const originalMessage = feedbackItem.querySelector('.feedback-message').innerText.replace('Message:\n', '').trim();
            
            // Populate the reply form
            document.getElementById('feedback_id').value = feedbackId;
            document.getElementById('replyUserName').textContent = userName;
            document.getElementById('replyUserEmail').textContent = userEmail;
            document.getElementById('originalMessage').textContent = originalMessage;
            document.getElementById('admin_reply').value = ''; // Clear previous reply
            
            // Show existing reply if editing
            const existingReply = feedbackItem.querySelector('.feedback-reply');
            if (existingReply) {
                const replyText = existingReply.innerText.replace(/Your Reply.*:\n/, '').trim();
                document.getElementById('admin_reply').value = replyText;
            }
            
            replyFeedbackModal.style.display = 'block';
        });
    });

    if (closeReplyModal) closeReplyModal.addEventListener('click', () => replyFeedbackModal.style.display = 'none');
    if (cancelReply) cancelReply.addEventListener('click', () => replyFeedbackModal.style.display = 'none');

    // Product Movement Form Validation
    const productSelect = document.getElementById('product_id');
    const movementTypeSelect = document.getElementById('movement_type');
    const quantityInput = document.getElementById('quantity');

    // Update quantity validation based on movement type and product stock
    function updateQuantityValidation() {
        const selectedProduct = productSelect.options[productSelect.selectedIndex];
        const movementType = movementTypeSelect.value;
        const currentStock = selectedProduct ? parseInt(selectedProduct.getAttribute('data-stock')) : 0;
        
        if (movementType && ['Sale', 'Gift', 'Damaged', 'Promotion'].includes(movementType)) {
            quantityInput.setAttribute('max', currentStock);
            if (quantityInput.value > currentStock) {
                quantityInput.value = currentStock;
            }
        } else {
            quantityInput.removeAttribute('max');
        }
    }

    if (productSelect) productSelect.addEventListener('change', updateQuantityValidation);
    if (movementTypeSelect) movementTypeSelect.addEventListener('change', updateQuantityValidation);

    // Clear movement form
    document.getElementById('clearMovement')?.addEventListener('click', function() {
        document.getElementById('movementForm').reset();
    });

    // Movement History Filtering
    document.getElementById('applyFiltersBtn')?.addEventListener('click', function() {
        const searchTerm = document.getElementById('movementSearch').value.toLowerCase();
        const typeFilter = document.getElementById('movementTypeFilter').value;
        const dateFrom = document.getElementById('movementDateFrom').value;
        const dateTo = document.getElementById('movementDateTo').value;
        
        document.querySelectorAll('#movementTable tr').forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length === 0) return;
            
            const rowDate = cells[0].textContent;
            const rowType = cells[2].querySelector('.movement-badge').textContent;
            const rowText = row.textContent.toLowerCase();
            
            let showRow = true;
            
            // Search term filter
            if (searchTerm && !rowText.includes(searchTerm)) {
                showRow = false;
            }
            
            // Type filter
            if (typeFilter && rowType !== typeFilter) {
                showRow = false;
            }
            
            // Date filter (basic implementation)
            if (dateFrom || dateTo) {
                // This is a simplified date filter - you might want to implement more robust date parsing
                const rowDateObj = new Date(rowDate);
                if (dateFrom && new Date(dateFrom) > rowDateObj) {
                    showRow = false;
                }
                if (dateTo && new Date(dateTo) < rowDateObj) {
                    showRow = false;
                }
            }
            
            row.style.display = showRow ? '' : 'none';
        });
    });

    // Clear movement filters
    document.getElementById('clearFiltersBtn')?.addEventListener('click', function() {
        document.getElementById('movementSearch').value = '';
        document.getElementById('movementTypeFilter').value = '';
        document.getElementById('movementDateFrom').value = '';
        document.getElementById('movementDateTo').value = '';
        
        // Show all rows
        document.querySelectorAll('#movementTable tr').forEach(row => {
            row.style.display = '';
        });
    });

    // Export movements to Excel (demo)
    document.getElementById('exportMovementsBtn')?.addEventListener('click', function() {
        alert('Export functionality would be implemented here. This would generate an Excel file with all movement data.');
    });

    // Order Management Tabs
    const orderTabs = document.querySelectorAll('#ordersSection .tab');
    const orderTabContents = document.querySelectorAll('#ordersSection .tab-content');
    
    orderTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const tabName = tab.getAttribute('data-tab');
            
            // Remove active class from all tabs and contents
            orderTabs.forEach(t => t.classList.remove('active'));
            orderTabContents.forEach(c => c.classList.remove('active'));
            
            // Add active class to clicked tab and corresponding content
            tab.classList.add('active');
            const tabContent = document.getElementById(`tab-${tabName}`);
            if (tabContent) tabContent.classList.add('active');
            
            // Filter orders based on tab
            filterOrdersByStatus(tabName);
        });
    });

    function filterOrdersByStatus(status) {
        const rows = document.querySelectorAll('#ordersSection table tbody tr');
        rows.forEach(row => {
            if (status === 'all') {
                row.style.display = '';
            } else {
                const statusCell = row.querySelector('.status-badge');
                if (statusCell) {
                    const rowStatus = statusCell.textContent.toLowerCase().replace(/-/g, ' ');
                    row.style.display = rowStatus === status ? '' : 'none';
                }
            }
        });
    }

    // Returns Management Tabs
    const returnTabs = document.querySelectorAll('#returnsSection .tab');
    const returnTabContents = document.querySelectorAll('#returnsSection .tab-content');
    
    returnTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const tabName = tab.getAttribute('data-tab');
            
            // Remove active class from all tabs and contents
            returnTabs.forEach(t => t.classList.remove('active'));
            returnTabContents.forEach(c => c.classList.remove('active'));
            
            // Add active class to clicked tab and corresponding content
            tab.classList.add('active');
            const tabContent = document.getElementById(`tab-${tabName}`);
            if (tabContent) tabContent.classList.add('active');
            
            // Filter returns based on tab
            filterReturnsByStatus(tabName);
        });
    });

    function filterReturnsByStatus(status) {
        const rows = document.querySelectorAll('#returnsSection table tbody tr');
        rows.forEach(row => {
            if (status === 'returns-all') {
                row.style.display = '';
            } else {
                const statusType = status.replace('returns-', '');
                const statusCell = row.querySelector('.status-badge');
                if (statusCell) {
                    const rowStatus = statusCell.textContent.toLowerCase();
                    row.style.display = rowStatus === statusType ? '' : 'none';
                }
            }
        });
    }

    // View Order Details - FIXED FUNCTION
    function viewOrderDetails(orderId) {
        console.log('Fetching order details for ID:', orderId);
        
        // Show loading message
        document.getElementById('orderDetailsContent').innerHTML = `
            <div style="padding: 40px; text-align: center;">
                <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid var(--primary); border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 20px;"></div>
                <p>Loading order details...</p>
            </div>
            <style>
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
        `;
        
        // Show modal
        document.getElementById('orderDetailsModal').style.display = 'flex';
        
        // Fetch order details via AJAX
        fetch(`admin_dashboard.php?get_order_details=${orderId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Order details response:', data);
                
                if (data.error) {
                    document.getElementById('orderDetailsContent').innerHTML = `
                        <div style="padding: 20px; text-align: center; color: var(--danger);">
                            <h4>Error Loading Order Details</h4>
                            <p>${data.error}</p>
                            <button class="btn secondary" onclick="document.getElementById('orderDetailsModal').style.display='none'">Close</button>
                        </div>
                    `;
                    return;
                }
                
                if (!data.success) {
                    document.getElementById('orderDetailsContent').innerHTML = `
                        <div style="padding: 20px; text-align: center; color: var(--danger);">
                            <h4>Order Not Found</h4>
                            <p>Order #${orderId} could not be found in the database.</p>
                            <button class="btn secondary" onclick="document.getElementById('orderDetailsModal').style.display='none'">Close</button>
                        </div>
                    `;
                    return;
                }
                
                const order = data.order;
                const items = data.items;
                
                // Format the order details
                const createdDate = order.created_at ? 
                    new Date(order.created_at).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    }) : 'Date not available';
                
                let itemsHtml = '';
                let totalAmount = 0;
                
                if (items && items.length > 0) {
                    items.forEach(item => {
                        const itemTotal = (item.quantity || 1) * (item.price || 0);
                        totalAmount += itemTotal;
                        
                        itemsHtml += `
                            <tr>
                                <td>${item.product_name || 'Unknown Product'}</td>
                                <td style="text-align: center;">${item.quantity || 1}</td>
                                <td style="text-align: right;">UGX ${parseFloat(item.price || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                <td style="text-align: right;">UGX ${itemTotal.toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                            </tr>
                        `;
                    });
                } else {
                    itemsHtml = `<tr><td colspan="4" style="text-align:center; padding:20px;">No items found for this order.</td></tr>`;
                }
                
                const statusClass = `status-${(order.status || 'pending').toLowerCase().replace(' ', '-')}`;
                const paymentMethod = order.payment_method || 'Cash';
                const paymentClass = `payment-${paymentMethod.toLowerCase().replace(' ', '-')}`;
                
                document.getElementById('orderDetailsContent').innerHTML = `
                    <div style="padding: 20px;">
                        <h4 style="margin-bottom: 15px; color: var(--primary);">Order #${order.id} Details</h4>
                        
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                            <h5 style="margin: 0 0 10px 0;">Order Information</h5>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div class="order-details-item">
                                    <span class="order-details-label">Order Date:</span>
                                    <span class="order-details-value">${createdDate}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Status:</span>
                                    <span class="order-details-value">
                                        <span class="status-badge ${statusClass}" style="padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                            ${order.status || 'Pending'}
                                        </span>
                                    </span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Total Amount:</span>
                                    <span class="order-details-value">UGX ${parseFloat(order.total_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Payment Method:</span>
                                    <span class="order-details-value">
                                        <span class="status-badge ${paymentClass}" style="padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                            ${paymentMethod}
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                            <h5 style="margin: 0 0 10px 0;">Customer Information</h5>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div class="order-details-item">
                                    <span class="order-details-label">Name:</span>
                                    <span class="order-details-value">${order.username || 'Guest'}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Email:</span>
                                    <span class="order-details-value">${order.email || 'N/A'}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Phone:</span>
                                    <span class="order-details-value">${order.phone || 'N/A'}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Shipping Address:</span>
                                    <span class="order-details-value">${order.shipping_address || order.address || 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                            <h5 style="margin: 0 0 10px 0;">Order Items</h5>
                            <table class="order-items-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th style="text-align: center;">Quantity</th>
                                        <th style="text-align: right;">Price</th>
                                        <th style="text-align: right;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml}
                                </tbody>
                                <tfoot>
                                    <tr class="order-total">
                                        <td colspan="3" style="text-align: right; padding: 12px;">Total:</td>
                                        <td style="text-align: right; padding: 12px;">UGX ${totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <div style="margin-top: 15px; text-align: center;">
                            <button class="btn" onclick="printOrderInvoice(${order.id})">Print Invoice</button>
                            <button class="btn secondary" onclick="document.getElementById('orderDetailsModal').style.display='none'">Close</button>
                        </div>
                    </div>
                `;
            })
            .catch(error => {
                console.error('Error fetching order details:', error);
                document.getElementById('orderDetailsContent').innerHTML = `
                    <div style="padding: 20px; text-align: center; color: var(--danger);">
                        <h4>Error Loading Order Details</h4>
                        <p>There was an error loading the order details. Please try again.</p>
                        <p><small>Error: ${error.message}</small></p>
                        <button class="btn secondary" onclick="document.getElementById('orderDetailsModal').style.display='none'">Close</button>
                    </div>
                `;
            });
    }

    // Print order invoice
    function printOrderInvoice(orderId) {
        window.open(`admin_dashboard.php?generate_invoice=${orderId}`, '_blank');
    }

    // Edit User Role
    function editUserRole(userId, currentRole) {
        document.getElementById('user_id').value = userId;
        document.getElementById('user_role').value = currentRole;
        document.getElementById('editUserRoleModal').style.display = 'flex';
    }

    // View Return Details - FIXED FUNCTION
    function viewReturnDetails(returnId) {
        console.log('Fetching return details for ID:', returnId);
        
        // Show loading message
        document.getElementById('returnDetailsContent').innerHTML = `
            <div style="padding: 40px; text-align: center;">
                <div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid var(--primary); border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 20px;"></div>
                <p>Loading return details...</p>
            </div>
        `;
        
        // Show modal
        document.getElementById('returnDetailsModal').style.display = 'flex';
        
        // Fetch return details via AJAX
        fetch(`admin_dashboard.php?get_return_details=${returnId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Return details response:', data);
                
                if (data.error) {
                    document.getElementById('returnDetailsContent').innerHTML = `
                        <div style="padding: 20px; text-align: center; color: var(--danger);">
                            <h4>Error Loading Return Details</h4>
                            <p>${data.error}</p>
                            <button class="btn secondary" onclick="document.getElementById('returnDetailsModal').style.display='none'">Close</button>
                        </div>
                    `;
                    return;
                }
                
                if (!data.success) {
                    document.getElementById('returnDetailsContent').innerHTML = `
                        <div style="padding: 20px; text-align: center; color: var(--danger);">
                            <h4>Return Request Not Found</h4>
                            <p>Return request #${returnId} could not be found in the database.</p>
                            <button class="btn secondary" onclick="document.getElementById('returnDetailsModal').style.display='none'">Close</button>
                        </div>
                    `;
                    return;
                }
                
                const returnData = data.return;
                const createdDate = returnData.created_at ? 
                    new Date(returnData.created_at).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    }) : 'Date not available';
                
                const statusClass = `return-${(returnData.status || 'pending').toLowerCase()}`;
                const refundAmount = returnData.refund_amount || (returnData.product_price || 0) * (returnData.quantity || 1);
                
                document.getElementById('returnDetailsContent').innerHTML = `
                    <div style="padding: 20px;">
                        <h4 style="margin-bottom: 15px; color: var(--primary);">Return Request #${returnData.id}</h4>
                        
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                            <h5 style="margin: 0 0 10px 0;">Return Information</h5>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div class="order-details-item">
                                    <span class="order-details-label">Return Date:</span>
                                    <span class="order-details-value">${createdDate}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Status:</span>
                                    <span class="order-details-value">
                                        <span class="status-badge ${statusClass}" style="padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                            ${returnData.status || 'Pending'}
                                        </span>
                                    </span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Order ID:</span>
                                    <span class="order-details-value">#${returnData.order_id || 'N/A'}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Product:</span>
                                    <span class="order-details-value">${returnData.product_name || 'Unknown Product'}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Quantity:</span>
                                    <span class="order-details-value">${returnData.quantity || 1}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Refund Amount:</span>
                                    <span class="order-details-value">UGX ${parseFloat(refundAmount).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                            <h5 style="margin: 0 0 10px 0;">Customer Information</h5>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div class="order-details-item">
                                    <span class="order-details-label">Name:</span>
                                    <span class="order-details-value">${returnData.username || 'Guest'}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Email:</span>
                                    <span class="order-details-value">${returnData.email || 'N/A'}</span>
                                </div>
                                <div class="order-details-item">
                                    <span class="order-details-label">Phone:</span>
                                    <span class="order-details-value">${returnData.phone || 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                            <h5 style="margin: 0 0 10px 0;">Return Reason</h5>
                            <p style="margin: 0; padding: 10px; background: white; border-radius: 5px; border-left: 3px solid var(--primary);">
                                ${returnData.reason || 'No reason provided.'}
                            </p>
                        </div>
                        
                        <div style="margin-top: 15px; text-align: center;">
                            <button class="btn secondary" onclick="document.getElementById('returnDetailsModal').style.display='none'">Close</button>
                        </div>
                    </div>
                `;
            })
            .catch(error => {
                console.error('Error fetching return details:', error);
                document.getElementById('returnDetailsContent').innerHTML = `
                    <div style="padding: 20px; text-align: center; color: var(--danger);">
                        <h4>Error Loading Return Details</h4>
                        <p>There was an error loading the return details. Please try again.</p>
                        <p><small>Error: ${error.message}</small></p>
                        <button class="btn secondary" onclick="document.getElementById('returnDetailsModal').style.display='none'">Close</button>
                    </div>
                `;
            });
    }

    // Refresh orders button
    document.getElementById('refreshOrdersBtn')?.addEventListener('click', function() {
        location.reload();
    });

    // Chart initialization
    document.addEventListener('DOMContentLoaded', function() {
        initializeCharts();
    });

    function initializeCharts() {
        // Sales charts
        const salesLabels = <?php echo $salesLabelsJson; ?>;
        const salesData = <?php echo $salesDataJson; ?>;
        const catLabels = <?php echo $catLabelsJson; ?>;
        const catData = <?php echo $catDataJson; ?>;
        const movementLabels = <?php echo $movementLabelsJson; ?>;
        const movementData = <?php echo $movementDataJson; ?>;

        // Main Sales Chart
        const salesCtx = document.getElementById('salesChart');
        if (salesCtx) {
            new Chart(salesCtx, {
                type: 'line', 
                data: { 
                    labels: salesLabels, 
                    datasets: [{ 
                        label: 'Revenue (UGX)', 
                        data: salesData, 
                        borderColor: '#0b63ff', 
                        backgroundColor: 'rgba(11,99,255,0.08)', 
                        tension: 0.25, 
                        fill: true, 
                        pointRadius: 3 
                    }] 
                },
                options: { 
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } }, 
                    scales: { 
                        y: { 
                            beginAtZero: true, 
                            ticks: { 
                                callback: function(value) {
                                    return 'UGX ' + Number(value).toLocaleString();
                                }
                            } 
                        } 
                    } 
                }
            });
        }

        // Sales Chart 2 (in reports section)
        const salesCtx2 = document.getElementById('salesChart2');
        if (salesCtx2) {
            new Chart(salesCtx2, {
                type: 'bar', 
                data: { 
                    labels: salesLabels, 
                    datasets: [{ 
                        label: 'Revenue (UGX)', 
                        data: salesData, 
                        backgroundColor: '#0b63ff' 
                    }] 
                },
                options: { 
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } }, 
                    scales: { 
                        y: { 
                            beginAtZero: true, 
                            ticks: { 
                                callback: function(value) {
                                    return 'UGX ' + Number(value).toLocaleString();
                                }
                            } 
                        } 
                    } 
                }
            });
        }

        // Category Chart
        const catCtx = document.getElementById('catChart');
        if (catCtx) {
            new Chart(catCtx, { 
                type: 'doughnut', 
                data: { 
                    labels: catLabels, 
                    datasets: [{ 
                        data: catData, 
                        backgroundColor: ['#60a5fa', '#93c5fd', '#fca5a5', '#fbbf24', '#34d399', '#c084fc'] 
                    }] 
                }, 
                options: { 
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } } 
                } 
            });
        }

        // Category Chart 2 (in reports section)
        const catCtx2 = document.getElementById('catChart2');
        if (catCtx2) { 
            new Chart(catCtx2, { 
                type: 'doughnut', 
                data: { 
                    labels: catLabels, 
                    datasets: [{ 
                        data: catData, 
                        backgroundColor: ['#60a5fa', '#93c5fd', '#fca5a5', '#fbbf24', '#34d399', '#c084fc'] 
                    }] 
                }, 
                options: { 
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } } 
                } 
            }); 
        }

        // Movement chart
        const movementCtx = document.getElementById('movementChart');
        if (movementCtx) {
            new Chart(movementCtx, {
                type: 'pie',
                data: {
                    labels: movementLabels,
                    datasets: [{
                        data: movementData,
                        backgroundColor: [
                            '#34d399', // Sold - green
                            '#fbbf24', // Gifts - yellow
                            '#ef4444', // Damaged - red
                            '#8b5cf6', // Promotions - purple
                            '#60a5fa', // Returns - blue
                            '#6b7280'  // Adjustments - gray
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    }
</script>

<?php
// close DB connection
$conn->close();
?>
</body>
</html>