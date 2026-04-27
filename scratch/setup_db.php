<?php
require 'db.php';

// Create attendance table
$sql = "CREATE TABLE IF NOT EXISTS `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `check_in` datetime DEFAULT NULL,
  `check_out` datetime DEFAULT NULL,
  `check_in_selfie` varchar(255) DEFAULT NULL,
  `check_out_selfie` varchar(255) DEFAULT NULL,
  `check_in_lat` decimal(10,8) DEFAULT NULL,
  `check_in_lng` decimal(11,8) DEFAULT NULL,
  `check_out_lat` decimal(10,8) DEFAULT NULL,
  `check_out_lng` decimal(11,8) DEFAULT NULL,
  `working_hours` decimal(5,2) DEFAULT NULL,
  `status` enum('Present','Half Day','Absent') DEFAULT 'Absent',
  `salary_deduction` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($conn->query($sql)) {
    echo "Table 'attendance' created successfully.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

// Add setting
$sql = "INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('checkin_max_time', '10:00') 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);";
if ($conn->query($sql)) {
    echo "Setting 'checkin_max_time' added/updated.\n";
} else {
    echo "Error adding setting: " . $conn->error . "\n";
}
?>
