<?php
// ---
// /public/auth.php
// O "Porteiro" do Link Mágico (v10 - COM LÓGICA DE REDIRECIONAMENTO)
// ---

// 1. Incluir o Bootstrap (para acesso ao DB e Models)
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar o Model de Usuário
use App\Models\Usuario;

session_start();

$token = $_GET['token'] ?? null;

// --- Função de Saída de Erro UX (v9 Aurora Glass) ---
// (Esta função continua 100% igual)
function displayError(string $message) {
    // Paleta de cores v9 Aurora Glass
    $corFundo = '#1a1b26';
    $gradienteFundo = 'radial-gradient(circle at 10% 20%, rgba(122, 92, 255, 0.1), transparent 30%), radial-gradient(circle at 90% 80%, rgba(0, 240, 181, 0.08), transparent 30%)';
    $corCard = 'rgba(42, 45, 62, 0.7)'; // Glassmorphism
    $corAlerta = '#ff5c7a'; // Rosa/Vermelho
    $corTextoPrincipal = '#e0e0e0';
    $corBorda = '#3b3e55';

    echo '<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso Negado</title>
    <style>
        @import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap\');
        body { 
            font-family: "Inter", system-ui, sans-serif; 
            background: ' . $gradienteFundo . ', ' . $corFundo . '; 
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
            backdrop-filter: blur(10px);
            border: 1px solid ' . $corBorda . '; 
            border-top: 4px solid ' . $corAlerta . ';
            color: ' . $corAlerta . '; 
            padding: 30px; 
            border-radius: 10px; 
            max-width: 450px; 
            width: 90%;
            box-shadow: 0 8px 30px rgba(0,0,0,0.7); 
        } 
        h2 { 
            margin-top: 0; 
            font-size: 24px; 
            color: ' . $corAlerta . ';
            font-weight: 600;
        } 
        p { 
            font-size: 16px; 
            color: ' . $corTextoPrincipal . ';
            line-height: 1.6;
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
    // (O findByLoginToken já apaga o token, o que é ótimo para segurança)
    $usuario = Usuario::findByLoginToken($pdo, $token);
    
    if ($usuario) {
        // --- SUCESSO! ---
        
        // 4. Regista o utilizador na sessão (SEMPRE)
        $_SESSION['user_id'] = $usuario->id;
        $_SESSION['user_nome'] = $usuario->nome;
        
        // --- (INÍCIO DA CORREÇÃO - LÓGICA DE REDIRECIONAMENTO) ---

        // 5. Verifica o estado do trial/assinatura
        $expiraEm = $usuario->data_expiracao ? new \DateTime($usuario->data_expiracao) : null;
        $agora = new \DateTime();
        
        $teveTrial = ($expiraEm !== null);
        $trialExpirado = ($teveTrial && $expiraEm < $agora);

        // Se o trial expirou (e não está ativo), envia para a página de assinar.
        if ($trialExpirado) {
            header("Location: assinar.php");
            exit;
        }
        
        // Em TODOS os outros casos (utilizador novo ou utilizador ativo),
        // envia para o painel principal (dashboard).
        header("Location: dashboard.php");
        exit;
        
        // --- (FIM DA CORREÇÃO) ---
        
    } else {
        // --- FALHA ---
        displayError("O link de login é inválido ou já expirou (válido por 10 minutos).");
    }
    
} catch (Exception $e) {
    // (Opcional: podemos logar $e->getMessage() aqui se quisermos)
    displayError("Erro crítico no servidor. Por favor, tente mais tarde.");
}
?>