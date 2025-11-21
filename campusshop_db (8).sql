-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 17, 2025 at 10:33 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `campusshop_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `abandoned_carts`
--

CREATE TABLE `abandoned_carts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `cart_data` text DEFAULT NULL,
  `total_items` int(11) DEFAULT 0,
  `total_value` decimal(10,2) DEFAULT 0.00,
  `recovered` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cart`
--

INSERT INTO `cart` (`id`, `user_id`, `product_id`, `session_id`, `quantity`) VALUES
(60, 2, 38, NULL, 2),
(62, 8, 30, NULL, 1),
(63, 8, 44, NULL, 1),
(69, 1, 27, NULL, 5),
(78, 10, 31, NULL, 1),
(93, 1, 44, NULL, 1),
(96, 1, 37, NULL, 1),
(97, 1, 49, NULL, 1),
(98, 1, 52, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `communication_log`
--

CREATE TABLE `communication_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` enum('email','chat','support_ticket','phone') NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `status` enum('Open','Closed','Pending') DEFAULT 'Open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `coupons`
--

CREATE TABLE `coupons` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `discount_type` enum('percentage','fixed') NOT NULL,
  `discount_value` decimal(10,2) NOT NULL,
  `minimum_order` decimal(10,2) DEFAULT 0.00,
  `usage_limit` int(11) DEFAULT NULL,
  `used_count` int(11) DEFAULT 0,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `favorites`
--

CREATE TABLE `favorites` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `favorites`
--

INSERT INTO `favorites` (`id`, `user_id`, `product_id`, `added_at`) VALUES
(22, 2, 39, '2025-11-07 08:55:53'),
(23, 2, 50, '2025-11-07 08:56:01'),
(24, 8, 44, '2025-11-11 08:55:30'),
(25, 1, 54, '2025-11-13 06:35:27'),
(26, 1, 27, '2025-11-13 06:35:40'),
(27, 1, 51, '2025-11-13 07:02:07'),
(28, 1, 44, '2025-11-14 10:36:27');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `status` enum('new','read','replied') DEFAULT 'new',
  `admin_reply` text DEFAULT NULL,
  `replied_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`id`, `user_id`, `name`, `email`, `message`, `created_at`, `status`, `admin_reply`, `replied_at`) VALUES
(2, 1, 'Tendo', 'ntendo4343@gmail.com', 'yeah', '2025-09-19 14:41:44', 'read', NULL, NULL),
(3, 1, 'Tendo', 'ntendo4343@gmail.com', 'wow', '2025-09-19 14:48:25', 'new', NULL, NULL),
(4, 1, 'Tendo', 'ntendo4343@gmail.com', 'wow', '2025-09-19 14:51:50', 'read', NULL, NULL),
(5, 1, 'Tendo', 'ntendo4343@gmail.com', 'yeah', '2025-09-19 14:54:49', 'read', NULL, NULL),
(6, 1, 'Tendo', 'ntendo4343@gmail.com', 'best work', '2025-11-04 14:37:37', 'replied', 'sure', '2025-11-04 15:55:14'),
(7, 1, 'Tendo', 'ntendo4343@gmail.com', 'why is my delivery not yet sent please', '2025-11-17 12:28:42', 'new', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `inventory_alerts`
--

CREATE TABLE `inventory_alerts` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `current_stock` int(11) NOT NULL,
  `threshold` int(11) NOT NULL,
  `alert_type` enum('low_stock','out_of_stock') NOT NULL,
  `status` enum('active','resolved') DEFAULT 'active',
  `notified_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `marketing_channels`
--

CREATE TABLE `marketing_channels` (
  `id` int(11) NOT NULL,
  `channel_name` varchar(100) NOT NULL,
  `source` varchar(100) DEFAULT NULL,
  `visits` int(11) DEFAULT 0,
  `orders` int(11) DEFAULT 0,
  `revenue` decimal(15,2) DEFAULT 0.00,
  `date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `newsletter_subscribers`
--

CREATE TABLE `newsletter_subscribers` (
  `id` int(11) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `subscribed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `message`, `created_at`) VALUES
(4, NULL, 'good notification well good', '2025-10-08 19:02:48'),
(6, NULL, 'hello dear customers', '2025-10-09 11:28:15'),
(12, NULL, 'New T-shirt skock', '2025-11-17 10:24:14'),
(16, 1, 'Your order #2 has been delivered successfully!', '2025-11-17 11:53:31'),
(17, 1, 'Your order #10 has been delivered successfully!', '2025-11-17 11:57:04'),
(18, 1, 'Your order #4 has been delivered successfully!', '2025-11-17 12:13:37');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `order_date` datetime NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'Unknown',
  `shipping_address` text DEFAULT NULL,
  `billing_address` text DEFAULT NULL,
  `shipping_method` varchar(100) DEFAULT NULL,
  `tracking_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('New','Pending Payment','Processing','Shipped','Delivered','Canceled','Refunded') DEFAULT 'New',
  `payment_status` enum('Pending','Completed','Failed','Refunded') DEFAULT 'Pending',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `order_number`, `user_id`, `order_date`, `total_amount`, `payment_method`, `shipping_address`, `billing_address`, `shipping_method`, `tracking_number`, `notes`, `status`, `payment_status`, `updated_at`, `created_at`) VALUES
(1, 'ORD000001', 1, '2025-11-14 08:58:15', 3000.00, 'Unknown', NULL, NULL, NULL, NULL, NULL, 'Shipped', 'Pending', '2025-11-17 08:52:19', '2025-11-17 08:48:54'),
(2, 'ORD000002', 1, '2025-11-14 10:07:38', 20000.00, 'Unknown', NULL, NULL, NULL, NULL, NULL, 'Delivered', 'Pending', '2025-11-17 08:53:31', '2025-11-17 08:48:54'),
(3, 'ORD000003', 1, '2025-11-14 10:46:21', 20500.00, 'Unknown', NULL, NULL, NULL, NULL, NULL, 'Refunded', 'Pending', '2025-11-17 08:54:29', '2025-11-17 08:48:54'),
(4, 'ORD000004', 1, '2025-11-14 11:37:01', 15000.00, 'Unknown', NULL, NULL, NULL, NULL, NULL, 'Delivered', 'Pending', '2025-11-17 09:13:37', '2025-11-17 08:48:54'),
(5, 'ORD000005', 1, '2025-11-14 11:39:36', 3000.00, 'Unknown', NULL, NULL, NULL, NULL, NULL, '', 'Pending', '2025-11-17 07:15:11', '2025-11-17 08:48:54'),
(6, 'ORD000006', 1, '2025-11-14 11:40:47', 8000.00, 'Unknown', NULL, NULL, NULL, NULL, NULL, '', 'Pending', '2025-11-17 07:15:11', '2025-11-17 08:48:54'),
(7, 'ORD000007', 1, '2025-11-14 12:30:23', 50000.00, 'Unknown', NULL, NULL, NULL, NULL, NULL, 'Canceled', 'Pending', '2025-11-17 08:23:16', '2025-11-17 08:48:54'),
(8, 'ORD000008', 1, '2025-11-17 06:37:10', 20000.00, 'Unknown', NULL, NULL, NULL, NULL, NULL, 'Processing', 'Pending', '2025-11-17 08:02:32', '2025-11-17 08:48:54'),
(9, 'ORD000009', 1, '2025-11-17 07:19:08', 120000.00, 'Unknown', NULL, NULL, NULL, NULL, NULL, 'Delivered', 'Pending', '2025-11-17 08:02:22', '2025-11-17 08:48:54'),
(10, NULL, 1, '2025-11-17 09:55:36', 90000.00, 'Unknown', NULL, NULL, NULL, NULL, NULL, 'Delivered', 'Pending', '2025-11-17 08:57:04', '2025-11-17 08:55:36');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name`, `quantity`, `price`, `subtotal`) VALUES
(1, 1, 31, 'Ball point pens', 1, 3000.00, 3000.00),
(2, 2, 41, 'Mens/Womens long sleeve T-Shirt', 1, 20000.00, 20000.00),
(3, 3, 42, 'Men\\\\\\\'s Golf Polo T-shirt', 1, 20500.00, 20500.00),
(4, 4, 38, 'On-the-Go Notebooks', 1, 15000.00, 15000.00),
(5, 5, 44, 'Retractable ball point pens', 1, 3000.00, 3000.00),
(6, 6, 39, 'Hard Cover Notebooks', 1, 8000.00, 8000.00),
(7, 7, 52, 'wooden wall clock', 1, 50000.00, 50000.00),
(8, 8, 62, 'Tote bags', 1, 20000.00, 20000.00),
(9, 9, 26, 'Hooded jumper', 6, 20000.00, 120000.00),
(10, 10, 63, 'Hand bag', 3, 30000.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `pending_deliveries`
--

CREATE TABLE `pending_deliveries` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `payment_method` enum('Mobile Money','Pay on Delivery') NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `status` enum('Pending','Completed') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cart_id` int(11) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) DEFAULT NULL,
  `paypal_order_id` varchar(255) DEFAULT NULL,
  `network_provider` varchar(50) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `product_image` varchar(255) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pending_deliveries`
--

INSERT INTO `pending_deliveries` (`id`, `order_id`, `user_id`, `username`, `email`, `phone`, `payment_method`, `amount`, `status`, `created_at`, `cart_id`, `location`, `stripe_payment_intent_id`, `paypal_order_id`, `network_provider`, `product_id`, `product_name`, `product_image`, `quantity`) VALUES
(38, NULL, 1, 'Tendo', NULL, '0755087665', 'Mobile Money', 25000.00, 'Completed', '2025-11-11 09:05:02', 45, 'hostel A', NULL, NULL, 'Airtel', 40, 'T-shirt 3', 'images/1762423764_t-shirt_3.png', 1),
(39, NULL, 1, 'Tendo', NULL, '0765777269', 'Mobile Money', 2000.00, 'Completed', '2025-11-11 09:28:57', 65, 'hostel A', NULL, NULL, 'MTN', 43, 'pen 1', 'images/1762424132_scott-gummerson-FGWpR-O9QlQ-unsplash.jpg', 1),
(43, NULL, 1, 'Tendo', NULL, '0755087665', 'Pay on Delivery', NULL, 'Completed', '2025-11-13 06:56:16', 74, 'hostel A', NULL, NULL, '', 58, 'T-shirt', 'images/1763016894_1763013515_Gemini_Generated_Image_meerilmeerilmeer.png', 1),
(44, NULL, 1, 'Tendo', NULL, '0755087665', 'Mobile Money', 510000.00, 'Completed', '2025-11-13 07:03:34', 75, 'hostel A', NULL, NULL, 'Airtel', 51, 'classic  hand watch', 'images/1763011064_Gemini_Generated_Image_ytfqzgytfqzgytfq__2_.png', 6),
(45, NULL, 10, 'David', NULL, '0755087665', 'Pay on Delivery', NULL, 'Completed', '2025-11-13 08:27:49', 79, 'hostel A', NULL, NULL, '', 43, 'metallic fountain pens', 'images/1762424132_scott-gummerson-FGWpR-O9QlQ-unsplash.jpg', 1),
(46, NULL, 10, 'David', NULL, '0755087665', 'Pay on Delivery', NULL, 'Completed', '2025-11-13 08:31:13', 80, 'hostel A', NULL, NULL, '', 43, 'metallic fountain pens', 'images/1762424132_scott-gummerson-FGWpR-O9QlQ-unsplash.jpg', 4),
(47, NULL, 1, 'Tendo', NULL, '0755087665', 'Mobile Money', 25000.00, 'Completed', '2025-11-14 07:52:38', 82, 'hostel A', NULL, NULL, 'Airtel', 64, 'Pass bag', 'images/1763102831_bag.png', 1),
(48, NULL, 1, 'Tendo', NULL, '0755087665', 'Mobile Money', 3000.00, 'Completed', '2025-11-14 07:58:15', 77, 'hostel A', NULL, NULL, 'MTN', 31, 'Ball point pens', 'images/1756883749_anna-evans-eM51ZBCLtYk-unsplash.jpg', 1),
(49, NULL, 1, 'Tendo', NULL, '0755087665', 'Pay on Delivery', NULL, 'Completed', '2025-11-14 09:07:39', 72, 'hostel A', NULL, NULL, NULL, 41, 'Mens/Womens long sleeve T-Shirt', 'images/1757425778_faith-yarn-Wr0TpKqf26s-unsplash.jpg', 1),
(50, NULL, 1, 'Tendo', NULL, '0765777269', 'Mobile Money', 20500.00, 'Completed', '2025-11-14 09:46:21', 71, 'hostel A', NULL, NULL, 'Airtel', 42, 'Men\\\\\\\'s Golf Polo T-shirt', 'images/1763012479_Gemini_Generated_Image_fwvxudfwvxudfwvx__1_.png', 1),
(51, NULL, 1, 'Tendo', NULL, '0765777269', 'Pay on Delivery', NULL, 'Completed', '2025-11-14 10:37:01', 85, 'hostel A', NULL, NULL, NULL, 38, 'On-the-Go Notebooks', 'images/1757425589_asterisk-kwon-q_gjDWf9ths-unsplash.jpg', 1),
(52, NULL, 1, 'Tendo', NULL, '0765777269', 'Pay on Delivery', NULL, 'Completed', '2025-11-14 10:39:36', 84, 'Room 19', NULL, NULL, NULL, 44, 'Retractable ball point pens', 'images/1763011120_Gemini_Generated_Image_ytfqzgytfqzgytfq.png', 1),
(53, NULL, 1, 'Tendo', NULL, '0755087665', 'Mobile Money', 8000.00, 'Completed', '2025-11-14 10:40:47', 87, 'Room 19', NULL, NULL, 'Airtel', 39, 'Hard Cover Notebooks', 'images/1757425682_designecologist-gh1IgGFnhSk-unsplash.jpg', 1),
(54, NULL, 1, 'Tendo', NULL, '0755087665', 'Mobile Money', 50000.00, 'Completed', '2025-11-14 11:30:24', 88, 'Room 19', NULL, NULL, 'Airtel', 52, 'wooden wall clock', 'images/1763012985_Gemini_Generated_Image_kiuzigkiuzigkiuz.png', 1),
(55, NULL, 1, 'Tendo', NULL, '0765777269', 'Mobile Money', 20000.00, 'Completed', '2025-11-17 05:37:10', 91, 'hostel A', NULL, NULL, 'Airtel', 62, 'Tote bags', 'images/1763102686_bag2.png', 1),
(56, NULL, 1, 'Tendo', NULL, '0755087665', 'Mobile Money', 120000.00, 'Completed', '2025-11-17 06:19:08', 94, 'hostel A', NULL, NULL, 'Airtel', 26, 'Hooded jumper', 'images/1763013200_Gemini_Generated_Image_x1u0pcx1u0pcx1u0.png', 6),
(57, NULL, 1, 'Tendo', NULL, '0755087665', 'Mobile Money', 90000.00, 'Completed', '2025-11-17 08:55:36', 95, 'hostel A', NULL, NULL, 'Airtel', 63, 'Hand bag', 'images/1763102772_bag5.png', 3);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `weight` decimal(8,2) DEFAULT 0.00,
  `dimensions` varchar(50) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `category` enum('Bags','Branded Jumpers','Bottles','Pens','Note Books','Wall Clocks','T-Shirts') DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `sku`, `name`, `price`, `weight`, `dimensions`, `image_path`, `caption`, `category`, `stock`, `is_active`) VALUES
(26, 'PROD000026', 'Hooded jumper', 20000.00, 0.00, NULL, 'images/1763013200_Gemini_Generated_Image_x1u0pcx1u0pcx1u0.png', 'Cozy Bugema University jumper made from soft, high-quality fabric. Show your campus pride in comfort and style perfect for any season.', 'Branded Jumpers', 20, 1),
(27, 'PROD000027', 'Sweater vest', 20000.00, 0.00, NULL, 'images/1763011623_Gemini_Generated_Image_kut6jxkut6jxkut6__1_.png', 'Cozy Bugema University jumper made from soft, high-quality fabric. Show your campus pride in comfort and style perfect for any season.', 'Branded Jumpers', 10, 1),
(28, 'PROD000028', 'Crew-neck pullover', 15000.00, 0.00, NULL, 'images/1763014976_Gemini_Generated_Image_92j83s92j83s92j8.png', 'Cozy Bugema University jumper made from soft, high-quality fabric. Show your campus pride in comfort and style perfect for any season.', 'Branded Jumpers', 9, 1),
(29, 'PROD000029', 'Wall Clock', 50000.00, 0.00, NULL, 'images/1763012955_Gemini_Generated_Image_mxwuc3mxwuc3mxwu.png', 'Stylish decorative wall clock with a silent quartz mechanism. Combines functionality with elegance for any wall space.', 'Wall Clocks', 10, 1),
(30, 'PROD000030', 'Clock', 15000.00, 0.00, NULL, 'images/1763011000_Gemini_Generated_Image_ytfqzgytfqzgytfq__1_.png', 'Stylish decorative wall clock with a silent quartz mechanism. Combines functionality with elegance for any wall space.', 'Wall Clocks', 10, 1),
(31, 'PROD000031', 'Ball point pens', 3000.00, 0.00, NULL, 'images/1756883749_anna-evans-eM51ZBCLtYk-unsplash.jpg', 'Smooth-writing Bugema University pen with a sleek design and comfortable grip. Perfect for students, staff, and everyday office use.', 'Pens', 9, 1),
(32, 'PROD000032', 'Metallic Water Bottle', 10000.00, 0.00, NULL, 'images/1762423731_bottle_1.png', 'Eco-friendly Bugema University water bottle designed to keep your drinks hot or cold. Stylish, reusable, and perfect for daily hydration.', 'Bottles', 10, 1),
(33, 'PROD000033', 'Womens VPolo T-Shirt', 15000.00, 0.00, NULL, 'images/1763012594_Gemini_Generated_Image_kut6jxkut6jxkut6.png', 'Classic Bugema University T-shirt with a bold logo print. Lightweight, breathable, and ideal for students, alumni, and supporters.', 'T-Shirts', 10, 1),
(34, 'PROD000034', 'Mens Polo T-shirt', 15000.00, 0.00, NULL, 'images/1763013515_Gemini_Generated_Image_meerilmeerilmeer.png', 'Classic Bugema University T-shirt with a bold logo print. Lightweight, breathable, and ideal for students, alumni, and supporters.', 'T-Shirts', 10, 1),
(37, 'PROD000037', 'Side Spiral Notebooks', 20000.00, 0.00, NULL, 'images/1763011824_Gemini_Generated_Image_wdmzmewdmzmewdmz.png', 'Durable Bugema University notebook with ruled pages and a sturdy cover — perfect for class notes, meetings, or personal journaling. (150 pages)', 'Note Books', 10, 1),
(38, 'PROD000038', 'On-the-Go Notebooks', 15000.00, 0.00, NULL, 'images/1757425589_asterisk-kwon-q_gjDWf9ths-unsplash.jpg', 'Durable Bugema University notebook with ruled pages and a sturdy cover — perfect for class notes, meetings, or personal journaling. (150 pages)', 'Note Books', 8, 1),
(39, 'PROD000039', 'Hard Cover Notebooks', 8000.00, 0.00, NULL, 'images/1757425682_designecologist-gh1IgGFnhSk-unsplash.jpg', 'Durable Bugema University notebook with ruled pages and a sturdy cover — perfect for class notes, meetings, or personal journaling. (150 pages)', 'Note Books', 8, 1),
(40, 'PROD000040', 'Mens/Womens V-neck T-shirt', 25000.00, 0.00, NULL, 'images/1762423764_t-shirt_3.png', 'Classic Bugema University T-shirt with a bold logo print. Lightweight, breathable, and ideal for students, alumni, and supporters.', 'T-Shirts', 10, 1),
(41, 'PROD000041', 'Mens/Womens long sleeve T-Shirt', 20000.00, 0.00, NULL, 'images/1757425778_faith-yarn-Wr0TpKqf26s-unsplash.jpg', 'Classic Bugema University T-shirt with a bold logo print. Lightweight, breathable, and ideal for students, alumni, and supporters.', 'T-Shirts', 9, 1),
(42, 'PROD000042', 'Men\\\\\\\'s Golf Polo T-shirt', 20500.00, 0.00, NULL, 'images/1763012479_Gemini_Generated_Image_fwvxudfwvxudfwvx__1_.png', 'Classic Bugema University T-shirt with a bold logo print. Lightweight, breathable, and ideal for students, alumni, and supporters.', 'T-Shirts', 8, 1),
(43, 'PROD000043', 'metallic fountain pens', 2000.00, 0.00, NULL, 'images/1762424132_scott-gummerson-FGWpR-O9QlQ-unsplash.jpg', 'Smooth-writing Bugema University pen with a sleek design and comfortable grip. Perfect for students, staff, and everyday office use.', 'Pens', 4, 1),
(44, 'PROD000044', 'Retractable ball point pens', 3000.00, 0.00, NULL, 'images/1763011120_Gemini_Generated_Image_ytfqzgytfqzgytfq.png', 'Smooth-writing Bugema University pen with a sleek design and comfortable grip. Perfect for students, staff, and everyday office use.', 'Pens', 8, 1),
(45, 'PROD000045', 'Retractable metallic ball pens', 1000.00, 0.00, NULL, 'images/1763013343_Gemini_Generated_Image_psvbpupsvbpupsvb.png', 'Smooth-writing Bugema University pen with a sleek design and comfortable grip. Perfect for students, staff, and everyday office use.', 'Pens', 10, 1),
(46, 'PROD000046', 'Retractable wooden ball point pens', 2000.00, 0.00, NULL, 'images/1763013044_Gemini_Generated_Image_y0htdqy0htdqy0ht.png', 'Smooth-writing Bugema University pen with a sleek design and comfortable grip. Perfect for students, staff, and everyday office use.', 'Pens', 10, 1),
(47, 'PROD000047', 'Plastic water bottle	', 8500.00, 0.00, NULL, 'images/1763012507_Gemini_Generated_Image_4rimi74rimi74rim.png', 'Eco-friendly Bugema University water bottle designed to keep your drinks hot or cold. Stylish, reusable, and perfect for daily hydration.', 'Bottles', 5, 1),
(48, 'PROD000048', 'Metallic water bottle', 20000.00, 0.00, NULL, 'images/1763012560_Gemini_Generated_Image_uu2ql9uu2ql9uu2q.png', 'Eco-friendly Bugema University water bottle designed to keep your drinks hot or cold. Stylish, reusable, and perfect for daily hydration.', 'Bottles', 10, 1),
(49, 'PROD000049', 'Plastic water bottle', 9500.00, 0.00, NULL, 'images/1763013010_Gemini_Generated_Image_7e2kx47e2kx47e2k.png', 'Eco-friendly Bugema University water bottle designed to keep your drinks hot or cold. Stylish, reusable, and perfect for daily hydration.', 'Bottles', 10, 1),
(50, 'PROD000050', 'Stylish decorative wall clock', 50000.00, 0.00, NULL, 'images/1763011476_1763011000_Gemini_Generated_Image_ytfqzgytfqzgytfq__1_.png', 'Stylish decorative wall clock with a silent quartz mechanism. Combines functionality with elegance for any wall space.', 'Wall Clocks', 10, 1),
(51, 'PROD000051', 'classic  hand watch', 85000.00, 0.00, NULL, 'images/1763011064_Gemini_Generated_Image_ytfqzgytfqzgytfq__2_.png', 'Classic wooden wall clock with smooth sweeping hands and vintage charm. Adds warmth and style to any living or office space.', 'Wall Clocks', 4, 1),
(52, 'PROD000052', 'wooden wall clock', 50000.00, 0.00, NULL, 'images/1763012985_Gemini_Generated_Image_kiuzigkiuzigkiuz.png', 'Classic wooden wall clock with smooth sweeping hands and vintage charm. Adds warmth and style to any living or office space.', 'Wall Clocks', 11, 1),
(54, 'PROD000054', 'Pass bag', 25000.00, 0.00, NULL, 'images/1763119619_bag.png', 'Small Flexible bag', 'Bags', 8, 1),
(58, 'PROD000058', 'T-shirt', 20000.00, 0.00, NULL, 'images/1763016894_1763013515_Gemini_Generated_Image_meerilmeerilmeer.png', 'wear your pride', 'T-Shirts', 10, 1),
(62, 'PROD000062', 'Tote bags', 20000.00, 0.00, NULL, 'images/1763102686_bag2.png', 'Tote bags that can carry alot', 'Bags', 3, 1),
(63, 'PROD000063', 'Hand bag', 30000.00, 0.00, NULL, 'images/1763102772_bag5.png', 'Ladies Hand Bags', 'Bags', 4, 1),
(64, 'PROD000064', 'Pass bag', 25000.00, 0.00, NULL, 'images/1763102831_bag.png', 'small hand bag', 'Bags', 12, 1),
(65, 'PROD000065', 'Waist bag', 35000.00, 0.00, NULL, 'images/1763102912_bag4.png', 'waist bag', 'Bags', 6, 1),
(66, 'PROD000066', 'Laptop bag', 45000.00, 0.00, NULL, 'images/1763103360_bag3.png', 'Laptop bags', 'Bags', 13, 1),
(67, 'PROD000067', 'IT club Bugema University Collar T-shirt', 30000.00, 0.00, NULL, 'images/1763359062_IT_club_T-shirt.png', 'IT club T-shirt', 'T-Shirts', 10, 1);

-- --------------------------------------------------------

--
-- Table structure for table `product_analytics`
--

CREATE TABLE `product_analytics` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `views` int(11) DEFAULT 0,
  `add_to_cart` int(11) DEFAULT 0,
  `purchases` int(11) DEFAULT 0,
  `conversion_rate` decimal(5,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_movements`
--

CREATE TABLE `product_movements` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `movement_type` enum('Sale','Gift','Return','Damaged','Promotion','Adjustment') NOT NULL,
  `quantity` int(11) NOT NULL,
  `issued_by` varchar(100) NOT NULL,
  `received_by` varchar(100) DEFAULT '',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_movements`
--

INSERT INTO `product_movements` (`id`, `product_id`, `movement_type`, `quantity`, `issued_by`, `received_by`, `remarks`, `created_at`) VALUES
(1, 66, 'Damaged', 0, 'Ntendo', 'marketing', 'said laptop bags', '2025-11-14 10:10:12'),
(2, 65, 'Damaged', 0, 'Ntendo', 'marketing', 'damaged', '2025-11-14 10:11:24'),
(3, 62, 'Sale', 0, 'Ntendo', 'marketing', 'gifts', '2025-11-14 10:12:13'),
(4, 62, 'Gift', 1, 'Ntendo', 'marketing', 'gifts', '2025-11-14 10:28:14'),
(5, 63, 'Sale', 3, 'Ntendo', 'marketing', 'sold', '2025-11-14 10:28:54'),
(6, 58, 'Promotion', 2, 'Ntendo', 'marketing', 'promotions', '2025-11-14 10:29:39'),
(7, 47, 'Gift', 5, 'Ntendo', 'marketing', 'Water bottles', '2025-11-14 10:30:33'),
(8, 62, 'Damaged', 3, 'Ntendo', 'marketing', 'damage', '2025-11-14 10:31:52'),
(9, 42, 'Sale', 1, 'Ntendo', 'Tendo', 'Online order completed', '2025-11-14 10:32:16'),
(10, 52, 'Return', 3, 'Ntendo', 'marketing', 'returns', '2025-11-14 10:32:45'),
(11, 38, 'Sale', 1, 'Ntendo', 'Tendo', 'Online order completed', '2025-11-14 10:38:36'),
(12, 44, 'Sale', 1, 'Ntendo', 'Tendo', 'Online order completed', '2025-11-14 10:39:59'),
(13, 39, 'Sale', 1, 'Ntendo', 'Tendo', 'Online order completed', '2025-11-14 10:41:17'),
(14, 52, 'Sale', 1, 'Ntendo', 'Tendo', 'Online order completed', '2025-11-14 11:30:56'),
(15, 65, 'Gift', 5, 'Ntendo', 'marketing', 'given as gifts', '2025-11-14 11:32:38'),
(16, 66, 'Gift', 4, 'Ntendo', 'marketing', 'gifts given to freshers', '2025-11-14 11:38:51'),
(17, 66, 'Gift', 3, 'Ntendo', 'Department of IT', 'given out as gifts', '2025-11-17 05:27:01'),
(18, 62, 'Sale', 1, 'Ntendo', 'Tendo', 'Online order completed', '2025-11-17 05:41:36'),
(19, 26, 'Sale', 6, 'Ntendo', 'Tendo', 'Online order completed', '2025-11-17 06:21:23'),
(20, 63, 'Sale', 3, 'Ntendo', 'Tendo', 'Online order completed', '2025-11-17 08:57:17');

-- --------------------------------------------------------

--
-- Table structure for table `product_reviews`
--

CREATE TABLE `product_reviews` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `rating` int(11) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

CREATE TABLE `returns` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('Pending','Approved','Rejected','Completed') DEFAULT 'Pending',
  `refund_amount` decimal(10,2) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_analytics`
--

CREATE TABLE `sales_analytics` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `total_revenue` decimal(15,2) DEFAULT 0.00,
  `total_orders` int(11) DEFAULT 0,
  `total_customers` int(11) DEFAULT 0,
  `new_customers` int(11) DEFAULT 0,
  `returning_customers` int(11) DEFAULT 0,
  `average_order_value` decimal(10,2) DEFAULT 0.00,
  `conversion_rate` decimal(5,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shipping_methods`
--

CREATE TABLE `shipping_methods` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `cost` decimal(10,2) NOT NULL,
  `free_shipping_threshold` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tax_rates`
--

CREATE TABLE `tax_rates` (
  `id` int(11) NOT NULL,
  `country` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `rate` decimal(5,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','manager','staff','student','lecturer','customer') DEFAULT 'customer',
  `email` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `email`, `first_name`, `last_name`, `phone`, `address`, `profile_picture`, `reset_token`, `reset_expires`, `created_at`, `updated_at`) VALUES
(1, 'Tendo', '$2y$10$.J8ZXCZ5.GXiG9fWrBjC8u0eBSKBeXIrHWLZ5/4/umDfPXO8Oy7Xy', 'student', 'ntendo4343@gmail.com', NULL, NULL, NULL, NULL, 'Uploads/690dbc72395d9.jpeg', '18ae5dc6acbe6fcd7c64c4c3e232b8f86052ea2d9af2e4edbca7edabd0e79ebc63508c4d207a5a09330b56e4013a0cd79716', '2025-10-14 18:27:29', '2025-11-17 07:15:09', '2025-11-17 07:15:09'),
(2, 'Ntendo', '$2y$10$GbXPRbSAW3aWl08OJNAPDOtXr.IgJHMkc1bdPgCXMqgrSLtGi9iGG', 'admin', 'tendo@gmail.com', NULL, NULL, NULL, NULL, 'Uploads/690db2366a05f.jpeg', NULL, NULL, '2025-11-17 07:15:09', '2025-11-17 08:13:58'),
(4, 'Tendo Jackline', '$2y$10$gXvRCoAko64VPlmqBGAfZOyuVUWxtdVLD9/XSTLplcG7qMP3oBKua', 'lecturer', 'ntendo2018@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-11-17 07:15:09', '2025-11-17 07:15:09'),
(5, 'Paul', '$2y$10$3IaJOA9d7v2/iFGqpZyO6.nLmtzxmaaiBoKDpeFLfEzyW5fCgKWza', 'admin', 'tendojackline79@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-11-17 07:15:09', '2025-11-17 07:15:09'),
(8, 'Rhonitah', '$2y$10$m3VsYV.tNGgczLroRpr.Ce19tSR2kYB2CyjGk/yOAUwWbVFM5vtRu', 'student', 'krhonitah@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-11-17 07:15:09', '2025-11-17 07:15:09'),
(10, 'David', '$2y$10$u0.jJI3bopLbx2jJCDXYH.nAiKQLWM.Fk.9S3l47tFCfkU371wW7m', 'lecturer', 'davidk01@gmail.com', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-11-17 07:15:09', '2025-11-17 07:15:09');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `abandoned_carts`
--
ALTER TABLE `abandoned_carts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_user_product` (`user_id`,`product_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `communication_log`
--
ALTER TABLE `communication_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `coupons`
--
ALTER TABLE `coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `favorites`
--
ALTER TABLE `favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_favorite` (`user_id`,`product_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `inventory_alerts`
--
ALTER TABLE `inventory_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `marketing_channels`
--
ALTER TABLE `marketing_channels`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `newsletter_subscribers`
--
ALTER TABLE `newsletter_subscribers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `pending_deliveries`
--
ALTER TABLE `pending_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`);

--
-- Indexes for table `product_analytics`
--
ALTER TABLE `product_analytics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_product_date` (`product_id`,`date`);

--
-- Indexes for table `product_movements`
--
ALTER TABLE `product_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `product_reviews`
--
ALTER TABLE `product_reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `returns`
--
ALTER TABLE `returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `sales_analytics`
--
ALTER TABLE `sales_analytics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_date` (`date`);

--
-- Indexes for table `shipping_methods`
--
ALTER TABLE `shipping_methods`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tax_rates`
--
ALTER TABLE `tax_rates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `abandoned_carts`
--
ALTER TABLE `abandoned_carts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `communication_log`
--
ALTER TABLE `communication_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `coupons`
--
ALTER TABLE `coupons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `favorites`
--
ALTER TABLE `favorites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `inventory_alerts`
--
ALTER TABLE `inventory_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `marketing_channels`
--
ALTER TABLE `marketing_channels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `newsletter_subscribers`
--
ALTER TABLE `newsletter_subscribers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `pending_deliveries`
--
ALTER TABLE `pending_deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `product_analytics`
--
ALTER TABLE `product_analytics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_movements`
--
ALTER TABLE `product_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `product_reviews`
--
ALTER TABLE `product_reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_analytics`
--
ALTER TABLE `sales_analytics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipping_methods`
--
ALTER TABLE `shipping_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tax_rates`
--
ALTER TABLE `tax_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `abandoned_carts`
--
ALTER TABLE `abandoned_carts`
  ADD CONSTRAINT `abandoned_carts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `communication_log`
--
ALTER TABLE `communication_log`
  ADD CONSTRAINT `communication_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `favorites`
--
ALTER TABLE `favorites`
  ADD CONSTRAINT `favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `favorites_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `feedback_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory_alerts`
--
ALTER TABLE `inventory_alerts`
  ADD CONSTRAINT `inventory_alerts_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pending_deliveries`
--
ALTER TABLE `pending_deliveries`
  ADD CONSTRAINT `pending_deliveries_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `pending_deliveries_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pending_deliveries_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_analytics`
--
ALTER TABLE `product_analytics`
  ADD CONSTRAINT `product_analytics_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_movements`
--
ALTER TABLE `product_movements`
  ADD CONSTRAINT `product_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_reviews`
--
ALTER TABLE `product_reviews`
  ADD CONSTRAINT `product_reviews_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `product_reviews_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `returns`
--
ALTER TABLE `returns`
  ADD CONSTRAINT `returns_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `returns_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `returns_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
