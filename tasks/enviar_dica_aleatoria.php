<?php
// ---
// /tasks/enviar_dica_aleatoria.php
// (VERSÃO CORRIGIDA COM NAMESPACE E LOG RENOMEADO)
// ---

// 1. Incluir Arquivo ÚNICO de Bootstrap
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar os "Namespaces"
use App\Models\Usuario;
use App\Services\WhatsAppService;

// 3. (CORREÇÃO 1) Logging renomeado
$logFilePath = __DIR__ . '/../storage/cron_dicas_log.txt'; 
function localWriteToLog($message) { // <-- RENOMEADO
    global $logFilePath;
    writeToLog($logFilePath, $message, "CRON_DICAS"); // Chama a global
}

localWriteToLog("--- CRON DICAS INICIADO ---"); // <-- CORRIGIDO

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
    $pdo = getDbConnection(); // (Já usa $_ENV)
    $waService = new WhatsAppService(); // (Já usa $_ENV)

    $dicaDoDia = $dicas[array_rand($dicas)];
    localWriteToLog("Dica do dia escolhida: " . $dicaDoDia); // <-- CORRIGIDO

    $usuarios = Usuario::findAll($pdo); 
    if (empty($usuarios)) {
        localWriteToLog("Nenhum usuário encontrado."); // <-- CORRIGIDO
        exit;
    }
    localWriteToLog("A enviar dica para " . count($usuarios) . " usuários..."); // <-- CORRIGIDO

    foreach ($usuarios as $usuario) {
        
        // 5. (CORREÇÃO 2) Não enviar para utilizadores inativos
        if ($usuario->is_ativo === false) {
            localWriteToLog("... A saltar Usuário #{$usuario->id}: Inativo."); // <-- CORRIGIDO
            continue;
        }

        // 6. Verifica se o utilizador quer receber dicas
        if ($usuario->receber_dicas === false) {
            localWriteToLog("... A saltar Usuário #{$usuario->id}: Dicas desativadas."); // <-- CORRIGIDO
            continue;
        }
        
        try {
            $waService->sendMessage($usuario->whatsapp_id, $dicaDoDia); 
        } catch (Exception $e) {
            localWriteToLog( // <-- CORRIGIDO
                "!!! FALHA AO ENVIAR DICA para utilizador #{$usuario->id}: " . $e->getMessage()
            );
        }
    }

} catch (Exception $e) {
    localWriteToLog("!!! ERRO CRÍTICO NO CRON DICAS !!!: " . $e->getMessage()); // <-- CORRIGIDO
}

localWriteToLog("--- CRON DICAS FINALIZADO ---"); // <-- CORRIGIDO
?>