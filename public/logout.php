<?php
// ---
// /public/logout.php
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
echo "A terminar a sessão... <meta http-equiv='refresh' content='2;url=dashboard.php'>";
?>