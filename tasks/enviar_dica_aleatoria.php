<?php
// ---
// /tasks/enviar_dica_aleatoria.php
// (VERSÃO COM BOOTSTRAP, NAMESPACE E FIX IS_ATIVO)
// ---

// 1. Includes
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar os "Namespaces"
use App\Models\Usuario;
use App\Services\WhatsAppService;

// 3. Logging
$logFilePath = __DIR__ . '/../storage/cron_dicas_log.txt'; 
function writeToLog($message) {
    global $logFilePath;
    writeToLog($logFilePath, $message, "CRON_DICAS"); // Chama a global
}

writeToLog("--- CRON DICAS INICIADO ---");

// 4. Lista de Dicas
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

    $usuarios = Usuario::findAll($pdo); // (Agora contém 'is_ativo')
    if (empty($usuarios)) {
        writeToLog("Nenhum usuário encontrado.");
        exit;
    }
    writeToLog("A enviar dica para " . count($usuarios) . " usuários...");

    foreach ($usuarios as $usuario) {
        
        // (NOVA VERIFICAÇÃO) Não envia para utilizadores inativos
        if ($usuario->is_ativo === false) {
            writeToLog("... A saltar Usuário #{$usuario->id}: Inativo.");
            continue;
        }

        // (VERIFICAÇÃO ANTIGA)
        if ($usuario->receber_dicas === false) {
            writeToLog("... A saltar Usuário #{$usuario->id}: Dicas desativadas.");
            continue;
        }
        
        try {
            $waService->sendMessage($usuario->whatsapp_id, $dicaDoDia); 
        } catch (Exception $e) {
            writeToLog(
                "!!! FALHA AO ENVIAR DICA para utilizador #{$usuario->id}: " . $e->getMessage()
            );
        }
    }

} catch (Exception $e) {
    writeToLog("!!! ERRO CRÍTICO NO CRON DICAS !!!: " . $e->getMessage());
}

writeToLog("--- CRON DICAS FINALIZADO ---");
?>