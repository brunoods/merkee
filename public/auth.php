<?php
// ---
// /public/auth.php
// O "Porteiro" do Link Mágico (Dark Theme & Responsive Error)
// ---

// 1. Incluir o Bootstrap (para acesso ao DB e Models)
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar o Model de Usuário
use App\Models\Usuario;

session_start();

$token = $_GET['token'] ?? null;

// --- Função de Saída de Erro UX (Dark Theme) ---
function displayError(string $message) {
    // Paleta de cores Dark Theme
    $corFundo = '#121212';
    $corCard = '#2d2d2d';
    $corAlerta = '#ff6b6b';
    $corTextoPrincipal = '#f0f0f0';

    echo '<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso Negado</title>
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            background: ' . $corFundo . '; 
            color: ' . $corTextoPrincipal . '; 
            text-align: center; 
            padding: 50px; 
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        } 
        .box { 
            background: ' . $corCard . '; 
            border: 2px solid ' . $corAlerta . '; 
            color: ' . $corAlerta . '; 
            padding: 30px; 
            border-radius: 10px; 
            max-width: 450px; 
            width: 90%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.5); 
        } 
        h2 { 
            margin-top: 0; 
            font-size: 24px; 
            color: ' . $corAlerta . ';
        } 
        p { 
            font-size: 16px; 
            color: ' . $corTextoPrincipal . ';
        }
        @media (max-width: 600px) {
            body { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="box">
        <h2>❌ Acesso Negado</h2>
        <p>' . htmlspecialchars($message) . '</p>
        <p>Por favor, vá para o WhatsApp e envie o comando *login* para obter um novo link.</p>
    </div>
</body>
</html>';
    exit;
}
// --- FIM Função de Saída de Erro UX ---


if (empty($token)) {
    displayError("Token de acesso não fornecido ou link inválido.");
}

try {
    $pdo = getDbConnection();
    
    // 3. Tenta encontrar o usuário com este token
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
        displayError("O link de login é inválido ou já expirou (válido por 10 minutos).");
    }
    
} catch (Exception $e) {
    displayError("Erro crítico no servidor. Por favor, tente mais tarde.");
}
?>