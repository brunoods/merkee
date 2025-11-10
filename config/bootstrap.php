<?php
// ---
// /config/bootstrap.php
// O único ficheiro de inicialização do projeto.
// ---

// 1. Carrega o Autoloader do Composer
// (Ele carrega as nossas classes 'App\' e a biblioteca 'Dotenv')
require_once __DIR__ . '/../vendor/autoload.php';

// 2. Carrega as variáveis de ambiente (segredos) do .env
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..'); // Aponta para a pasta raiz
    $dotenv->load();
} catch (\Dotenv\Exception\InvalidPathException $e) {
    die("Erro: Não foi possível carregar o ficheiro .env. " . $e->getMessage());
}

// 3. Função Global de Conexão (Modificada para ler do getenv())
$pdo = null;
function getDbConnection(): PDO
{
    global $pdo;
    if ($pdo === null) {
        
        // --- (A CORREÇÃO ESTÁ AQUI) ---
        // Lemos do $_ENV primeiro para contornar o cache do servidor
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
        $db   = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
        $user = $_ENV['DB_USER'] ?? getenv('DB_USER');
        $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS');
        // --- (FIM DA CORREÇÃO) ---
        
        $charset = 'utf8mb4';

        if (empty($host) || empty($db) || empty($user)) {
             throw new \PDOException("Erro Crítico: Configurações da base de dados (DB_HOST, DB_NAME, DB_USER) não definidas ou não lidas do .env");
        }

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            // Lança a exceção para que o script principal (admin.php) a possa apanhar
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }
    return $pdo;
}

// 4. Função de Log Centralizada (Melhoria 3)
// (Note que o $logFilePath é definido por quem a chama)
function writeToLog(string $logFilePath, string $message, string $logPrefix) {
    if (empty($logFilePath)) return;
    $logEntry = "[" . date('Y-m-d H:i:s') . "] ($logPrefix) " . $message . PHP_EOL;
    file_put_contents($logFilePath, $logEntry, FILE_APPEND);
}
