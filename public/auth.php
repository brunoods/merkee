<?php
// ---
// /public/auth.php
// O "Porteiro" do Link Mágico
// ---

// 1. Incluir o Bootstrap (para acesso ao DB e Models)
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar o Model de Usuário
use App\Models\Usuario;

session_start();

$token = $_GET['token'] ?? null;

if (empty($token)) {
    die("Erro: Link inválido ou token não fornecido.");
}

try {
    $pdo = getDbConnection();
    
    // 3. Tenta encontrar o usuário com este token (usando o método que criámos)
    $usuario = Usuario::findByLoginToken($pdo, $token);
    
    if ($usuario) {
        // --- SUCESSO! ---
        
        // 4. Regista o utilizador na sessão
        $_SESSION['user_id'] = $usuario->id;
        $_SESSION['user_nome'] = $usuario->nome;
        
        // 5. Redireciona para o painel principal
        header("Location: dashboard.php");
        exit;
        
    } else {
        // --- FALHA ---
        die("Link inválido ou expirado.  expired. Por favor, pede um novo link no WhatsApp enviando o comando *login*.");
    }
    
} catch (Exception $e) {
    die("Erro crítico no servidor. Por favor, tenta mais tarde.");
}
?>