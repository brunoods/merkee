<?php
// ---
// /tasks/enviar_dica_aleatoria.php
// (VERSÃO 2.0 - AGORA OBEDECE ÀS CONFIGURAÇÕES)
// ---

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_keys.php';
require_once __DIR__ . '/../app/Models/Usuario.php';
require_once __DIR__ . '/../app/Services/WhatsAppService.php';

$logFilePath = __DIR__ . '/../storage/cron_dicas_log.txt'; 
function writeToLog($message) {
    global $logFilePath;
    $logEntry = "[" . date('Y-m-d H:i:s') . "] (CRON_DICAS) " . $message . PHP_EOL;
    file_put_contents($logFilePath, $logEntry, FILE_APPEND);
}

writeToLog("--- CRON DICAS INICIADO ---");

$dicas = [
    "Sabias que? 💡 Comprar frutas e vegetais da época pode poupar-te até 30% na feira!",
    "Dica Rápida: 🛒 Tenta nunca ir ao supermercado com fome. Vais acabar a comprar mais do que precisas!",
    "Fica de olho! 🧐 Muitos produtos 'tamanho família' não são, na verdade, mais baratos. Compara sempre o preço por kg/litro!",
    "Já usaste o comando `pesquisar`? Envia-me *pesquisar <produto>* antes de saíres de casa para ver onde ele está mais barato! 🕵️‍♂️",
    "Planeamento é tudo! 📝 Tira 10 minutos no fim de semana para planear as refeições e faz uma lista. Ajuda a evitar compras por impulso.",
    "Olha para baixo! 🔽 Muitas vezes, as marcas mais caras e com maior margem de lucro estão ao nível dos olhos. Os produtos mais baratos podem estar nas prateleiras de baixo."
];

try {
    $pdo = getDbConnection();
    $waService = new WhatsAppService();

    $dicaDoDia = $dicas[array_rand($dicas)];
    writeToLog("Dica do dia escolhida: " . $dicaDoDia);

    $usuarios = Usuario::findAll($pdo); 
    if (empty($usuarios)) {
        writeToLog("Nenhum usuário encontrado.");
        exit;
    }
    writeToLog("A enviar dica para " . count($usuarios) . " usuários...");

    foreach ($usuarios as $usuario) {
        
        // --- (INÍCIO DA CORREÇÃO) ---
        // 1. Verifica se este usuário QUER receber esta dica
        if ($usuario->receber_dicas === false) {
            writeToLog("... A saltar Usuário #{$usuario->id}: Dicas desativadas.");
            continue; // Salta para o próximo usuário
        }
        // --- (FIM DA CORREÇÃO) ---
        
        $waService->sendMessage($usuario->whatsapp_id, $dicaDoDia); 
    }

} catch (Exception $e) {
    writeToLog("!!! ERRO CRÍTICO NO CRON DICAS !!!: " . $e->getMessage());
}

writeToLog("--- CRON DICAS FINALIZADO ---");
?>