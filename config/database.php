<?php
/**
 * SIM BISOLPIN ACADEMY - Database PDO Singleton Connection
 */

declare(strict_types=1);

require_once __DIR__ . '/app.php';

class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection = null;

    private string $host;
    private string $dbName;
    private string $username;
    private string $password;
    private int $port;
    private string $charset = 'utf8mb4';

    private function __construct()
    {
        $this->host     = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $this->username = $_ENV['DB_USER'] ?? 'root';
        $this->password = $_ENV['DB_PASS'] ?? '';
        $this->port     = (int)($_ENV['DB_PORT'] ?? 3306);

        // Prioritas nama database: dari $_ENV, lalu db_bisolpin_sim, lalu sim_bisolpin_academy
        $candidates = array_unique(array_filter([
            $_ENV['DB_NAME'] ?? null,
            'db_bisolpin_sim',
            'sim_bisolpin_academy'
        ]));

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset} COLLATE utf8mb4_unicode_ci",
        ];

        $lastException = null;
        foreach ($candidates as $name) {
            try {
                $dsn = "mysql:host={$this->host};port={$this->port};dbname={$name};charset={$this->charset}";
                $this->connection = new PDO($dsn, $this->username, $this->password, $options);
                $this->dbName = $name;
                return;
            } catch (PDOException $e) {
                $lastException = $e;
            }
        }

        // Jika semua kandidat gagal, tampilkan pesan panduan
        $this->dbName = $candidates[0] ?? 'db_bisolpin_sim';
        $errorMessage = "Koneksi Database Gagal: " . ($lastException ? $lastException->getMessage() : 'Unknown error');
        error_log($errorMessage);
        
        die("<div style='font-family:sans-serif;padding:30px;max-width:650px;margin:50px auto;border:1px solid #f87171;border-radius:12px;background:#fef2f2;color:#991b1b;'>"
            . "<h2 style='margin-top:0;'>⚠️ Database Connection Error</h2>"
            . "<p>SIM Bisolpin tidak dapat terhubung ke database <code>{$this->dbName}</code> pada host <code>{$this->host}:{$this->port}</code>.</p>"
            . "<p><strong>Detail Teknis:</strong> " . htmlspecialchars($lastException ? $lastException->getMessage() : '') . "</p>"
            . "<hr style='border:none;border-top:1px solid #fca5a5;margin:15px 0;'>"
            . "<p><strong>Solusi:</strong></p>"
            . "<ol>"
            . "<li>Pastikan service MySQL / MariaDB (XAMPP / Laragon) sudah aktif (Running).</li>"
            . "<li>Import file <code>database/schema_and_seed.sql</code> ke dalam database <code>db_bisolpin_sim</code>.</li>"
            . "</ol>"
            . "</div>");
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    // Mencegah kloning dan unserialize
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}

/**
 * Global helper function to get PDO connection
 */
function get_db(): PDO
{
    return Database::getInstance()->getConnection();
}

