<?php
// ---
// /public/logout.php
// (v9 Aurora Glass)
// ---

session_start();

// Destrói todas as variáveis da sessão
$_SESSION = [];

// Apaga o cookie de sessão
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finalmente, destrói a sessão
session_destroy();

// Redireciona para o login (ou uma página de "saída")
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A Terminar Sessão</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');
        :root {
            --cor-fundo: #1a1b26;
            --cor-fundo-card: rgba(42, 45, 62, 0.7); /* Glassmorphism */
            --cor-texto-principal: #e0e0e0;
            --cor-texto-secundaria: #9a9bb5;
            --cor-principal: #7a5cff;
            --cor-borda: #3b3e55;
        }
        body { 
            font-family: "Inter", system-ui, sans-serif; 
            background: radial-gradient(circle at 10% 20%, rgba(122, 92, 255, 0.1), transparent 30%),
                        radial-gradient(circle at 90% 80%, rgba(0, 240, 181, 0.08), transparent 30%),
                        var(--cor-fundo); 
            color: var(--cor-texto-principal); 
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .message-box {
            background: var(--cor-fundo-card);
            backdrop-filter: blur(10px);
            border: 1px solid var(--cor-borda);
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.7);
            text-align: center;
            border-top: 5px solid var(--cor-principal);
            max-width: 400px;
            width: 90%;
        }
        h1 {
            color: var(--cor-principal);
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        p {
            color: var(--cor-texto-secundaria);
            font-size: 16px;
        }
    </style>
    <meta http-equiv='refresh' content='2;url=dashboard.php'>
</head>
<body>
    <div class="message-box">
        <h1>👋 Sessão Encerrada</h1>
        <p>A sua sessão foi terminada com segurança.</p>
        <p>A redirecionar em 2 segundos...</p>
    </div>
</body>
</html>