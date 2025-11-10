<?php
// ---
// /public/logout.php
// (Dark Theme & Responsive)
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
        :root {
            --cor-fundo: #121212;
            --cor-fundo-card: #1f1f1f;
            --cor-texto-principal: #f0f0f0;
            --cor-texto-secundaria: #a0a0a0;
            --cor-principal: #0a9396;
        }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            background: var(--cor-fundo); 
            color: var(--cor-texto-principal); 
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .message-box {
            background: var(--cor-fundo-card);
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
            text-align: center;
            border-top: 5px solid var(--cor-principal);
            max-width: 400px;
            width: 90%;
        }
        h1 {
            color: var(--cor-principal);
            font-size: 28px;
            margin-bottom: 10px;
        }
        p {
            color: var(--cor-texto-secundaria);
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