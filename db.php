<?php

// --- 1. CONFIGURE YOUR MYSQL CONNECTION DETAILS ---
$dbHost = 'localhost';       // Or 'localhost'
$dbName = 'tbot_db';      // The name of the database to create and use
$dbUser = 'ahmed';            // Your MySQL username
$dbPass = 'djc06531362';   // Your MySQL password (change this!)
$charset = 'utf8mb4';

/**
 * Sets up the MySQL database, creates tables, and populates with sample data.
 */
function setupDatabase(string $host, string $name, string $user, string $pass, string $charset): void
{
    // --- 2. Create the Database if it doesn't exist ---
    try {
        // Connect to MySQL server (without specifying a database)
        $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Use backticks for the database name to avoid conflicts with reserved keywords
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name`");
        echo "Database '{$name}' created or already exists." . PHP_EOL;

    } catch (PDOException $e) {
        die("DB ERROR: Could not create database. " . $e->getMessage() . PHP_EOL);
    }

    // --- 3. Connect to the specific database and create tables ---
    try {
        $dsn = "mysql:host=$host;dbname=$name;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        $pdo = new PDO($dsn, $user, $pass, $options);
        echo "Successfully connected to '{$name}'." . PHP_EOL;

        // --- 4. Define and Execute the Schema (MySQL specific) ---
        $schemaSql = "
            CREATE TABLE IF NOT EXISTS `User` (
                `Chat_ID` BIGINT PRIMARY KEY,
                `Status` VARCHAR(50)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `Menu` (
                `menu_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `Type` VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `Button` (
                `button_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `Name` VARCHAR(255) NOT NULL,
                `goes_to_menu_id` INT UNSIGNED,
                FOREIGN KEY (`goes_to_menu_id`) REFERENCES `Menu`(`menu_id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `Material` (
                `Msg_ID` BIGINT PRIMARY KEY,
                `button_id` INT UNSIGNED,
                FOREIGN KEY (`button_id`) REFERENCES `Button`(`button_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `MenuButtonBuild` (
                `menu_id` INT UNSIGNED,
                `button_id` INT UNSIGNED,
                PRIMARY KEY (`menu_id`, `button_id`),
                FOREIGN KEY (`menu_id`) REFERENCES `Menu`(`menu_id`) ON DELETE CASCADE,
                FOREIGN KEY (`button_id`) REFERENCES `Button`(`button_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";

        echo "Creating table schema..." . PHP_EOL;
        $pdo->exec($schemaSql);
        echo "Schema created successfully." . PHP_EOL;

        // --- 5. Insert Sample Data ---
        echo "Inserting sample data..." . PHP_EOL;
        $pdo->beginTransaction();

        $stmtMenu = $pdo->prepare("INSERT IGNORE INTO Menu (menu_id, Type) VALUES (?, ?)");
        $stmtButton = $pdo->prepare("INSERT IGNORE INTO Button (button_id, Name, goes_to_menu_id) VALUES (?, ?, ?)");
        $stmtMaterial = $pdo->prepare("INSERT IGNORE INTO Material (Msg_ID, button_id) VALUES (?, ?)");
        $stmtBuild = $pdo->prepare("INSERT IGNORE INTO MenuButtonBuild (menu_id, button_id) VALUES (?, ?)");
        
        $stmtMenu->execute([1, 'main_menu']);
        $stmtMenu->execute([2, 'help_menu']);

        $stmtButton->execute([100, 'Show Products', null]);
        $stmtButton->execute([101, 'Help', 2]);
        $stmtButton->execute([102, 'Contact Us', null]);
        $stmtButton->execute([201, 'FAQ', null]);
        $stmtButton->execute([202, 'Go Back', 1]);

        $stmtMaterial->execute([1001, 100]);
        $stmtMaterial->execute([1002, 102]);
        $stmtMaterial->execute([1003, 201]);

        $stmtBuild->execute([1, 100]);
        $stmtBuild->execute([1, 101]);
        $stmtBuild->execute([1, 102]);
        $stmtBuild->execute([2, 201]);
        $stmtBuild->execute([2, 202]);

        $pdo->commit();
        echo "Sample data inserted." . PHP_EOL;

        // --- 6. Query and Print Data (Example) ---
        echo PHP_EOL . "--- Query Example ---" . PHP_EOL;
        echo "Fetching all buttons for the 'main_menu' (ID=1):" . PHP_EOL;
        
        $query = "
            SELECT b.Name, b.button_id
            FROM Button b
            JOIN MenuButtonBuild mbb ON b.button_id = mbb.button_id
            WHERE mbb.menu_id = 1;
        ";
        $stmt = $pdo->query($query);
        
        while ($row = $stmt->fetch()) {
            echo " - Button Name: {$row['Name']} (ID: {$row['button_id']})" . PHP_EOL;
        }

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die("DB ERROR: " . $e->getMessage() . PHP_EOL);
    } finally {
        $pdo = null;
        echo PHP_EOL . "Database setup complete. Connection closed." . PHP_EOL;
    }
}

// --- Run the main setup function ---
setupDatabase($dbHost, $dbName, $dbUser, $dbPass, $charset);