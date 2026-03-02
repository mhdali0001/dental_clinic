<?php
// إعدادات قاعدة البيانات
define('DB_HOST', 'localhost');
define('DB_NAME', 'dental_clinic');
define('DB_USER', 'sali'); // غير اسم المستخدم حسب إعدادات الخادم
define('DB_PASS', 'sali'); // غير كلمة المرور حسب إعدادات الخادم
define('DB_CHARSET', 'utf8mb4');

class Database {
    private $pdo;
    private static $instance = null;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('فشل في الاتصال بقاعدة البيانات: ' . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    // دالة لتنفيذ استعلام SELECT
    public function select($query, $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('خطأ في استعلام SELECT: ' . $e->getMessage());
            return false;
        }
    }

    // دالة لتنفيذ استعلام SELECT لصف واحد
    public function selectOne($query, $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log('خطأ في استعلام SELECT: ' . $e->getMessage());
            return false;
        }
    }

    // دالة لتنفيذ استعلامات INSERT, UPDATE, DELETE
    public function execute($query, $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log('خطأ في تنفيذ الاستعلام: ' . $e->getMessage());
            return false;
        }
    }

    // دالة للحصول على آخر ID مدرج
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    // دالة لبدء transaction
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    // دالة لتأكيد transaction
    public function commit() {
        return $this->pdo->commit();
    }

    // دالة لإلغاء transaction
    public function rollback() {
        return $this->pdo->rollback();
    }
}

// دالة مساعدة للحصول على instance قاعدة البيانات
function getDB() {
    return Database::getInstance();
}
?>