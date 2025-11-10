<?php
// ---
// /public/webhook.php
// (VERSÃO COM CORREÇÃO DO LOG)
// ---

// (Podes manter as tuas linhas de debug no topo, se quiseres)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../storage/PHP_FATAL_ERROR.log');

// 1. Incluir Arquivo ÚNICO de Bootstrap
// (Carrega .env, autoloader, getDbConnection() e a função global writeToLog())
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar os "Namespaces" do Autoloader
use App\Models\Usuario;
use App\Models\Compra;
use App\Controllers\BotController;
use App\Services\WhatsAppService;

// 3. Logging (Define o ficheiro e o prefixo para este script)
$logFilePath = __DIR__ . '/../storage/webhook_log.txt';

// --- (A CORREÇÃO ESTÁ AQUI) ---
// Renomeamos a função local para não colidir com a global
function localWriteToLog($message) { // Função local para conveniência
    global $logFilePath;
    // Chama a função GLOBAL (definida no bootstrap.php)
    writeToLog($logFilePath, $message, "WEBHOOK"); 
}
// --- (FIM DA CORREÇÃO) ---

// Agora, usamos a nova função local
localWriteToLog("--- INÍCIO DA REQUISIÇÃO ---");

// 4. Capturar e Validar a Requisição (sem mudança)
$jsonPayload = file_get_contents('php://input');
$data = json_decode($jsonPayload, true); 
if (!$data) {
    localWriteToLog("Erro: Nenhum payload JSON recebido."); // <-- CORRIGIDO
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nenhum payload recebido']);
    exit;
}
localWriteToLog("Payload Recebido: " . $jsonPayload); // <-- CORRIGIDO

// 5. Extrair Dados da Mensagem (sem mudança)
$whatsapp_id = $data['sender']['id'] ?? $data['phone'] ?? null;
$message_body = $data['text']['message'] ?? $data['message']['body'] ?? null;
$user_name = $data['sender']['name'] ?? 'Visitante';
if (!$whatsapp_id || !$message_body) {
    localWriteToLog("Ignorado: Não é uma mensagem de texto de usuário válida."); // <-- CORRIGIDO
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Ignorado']);
    exit;
}
localWriteToLog("Processando: ID [{$whatsapp_id}] | Mensagem [{$message_body}]"); // <-- CORRIGIDO


// 6. Lógica Principal
try {
    $pdo = getDbConnection();
    
    // (Corrigido para usar $_ENV para contornar o cache do servidor)
    $waService = new WhatsAppService();

    // Passo 1: Encontrar ou criar o usuário
    $usuario = Usuario::findOrCreate($pdo, $whatsapp_id, $user_name);
    localWriteToLog("Usuário: ID #" . $usuario->id . " | Nome Confirmado: " . ($usuario->nome_confirmado ? 'SIM' : 'NÃO') . " | Ativo: " . ($usuario->is_ativo ? 'SIM' : 'NÃO')); // <-- CORRIGIDO


    // --- (LÓGICA DO "PORTÃO" - sem mudança) ---

    // 2. O "PORTÃO" (Gate) - PARTE 1: Pedir o Nome
    if ($usuario->nome_confirmado == false && $usuario->conversa_estado == null) {
        
        $usuario->updateState($pdo, 'aguardando_nome_para_onboarding');
        $respostaDoBot = "Olá! 👋 Vi que é a tua primeira vez aqui.\n\nPara começarmos, como gostarias de ser chamado(a)?";
        
        localWriteToLog("Usuário #{$usuario->id} novo. A pedir o nome."); // <-- CORRIGIDO
        $waService->sendMessage($whatsapp_id, $respostaDoBot); 
        
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Novo usuário. A aguardar nome.']);
        exit;
    }


    // 3. O "PORTÃO" (Gate) - PARTE 2: Verificar Subscrição
    $hoje = new DateTime();
    $data_exp = $usuario->data_expiracao ? new DateTime($usuario->data_expiracao) : null;
    $is_valido = false;
    $motivo_bloqueio = "não está ativo";

    if ($usuario->is_ativo && $data_exp && $data_exp >= $hoje) {
        $is_valido = true;
    } elseif ($usuario->is_ativo && $data_exp && $data_exp < $hoje) {
        $motivo_bloqueio = "expirou em " . $data_exp->format('d/m/Y');
    } elseif (!$usuario->is_ativo) {
        $motivo_bloqueio = "está revogado ou pendente de ativação";
    }
    
    $is_valido = ($usuario->conversa_estado === 'aguardando_nome_para_onboarding') ? true : $is_valido;

    if ($is_valido == false) {
        
        $respostaDoBot = "Olá, {$usuario->nome}! 🔒\n\nA tua subscrição do Merkee {$motivo_bloqueio}.\n\nPara renovares ou saberes mais, contacta o administrador.";
        
        $checkLogStmt = $pdo->prepare("SELECT COUNT(*) FROM logs_bloqueio WHERE usuario_id = ? AND data_log = CURDATE()");
        $checkLogStmt->execute([$usuario->id]);
        $ja_enviado_hoje = $checkLogStmt->fetchColumn() > 0;

        if (!$ja_enviado_hoje) {
             localWriteToLog("Usuário #{$usuario->id} INATIVO/EXPIRADO ({$motivo_bloqueio}). A enviar mensagem de bloqueio."); // <-- CORRIGIDO
             $waService->sendMessage($whatsapp_id, $respostaDoBot); 
             $pdo->prepare("INSERT INTO logs_bloqueio (usuario_id, data_log) VALUES (?, CURDATE())")->execute([$usuario->id]);
        } else {
            localWriteToLog("Usuário #{$usuario->id} INATIVO/EXPIRADO. Ignorado silenciosamente (já notificado hoje)."); // <-- CORRIGIDO
        }

        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Usuário inativo/expirado. Bloqueado.']);
        exit;
    }
    // --- (FIM DA ATUALIZAÇÃO DO "PORTÃO") ---


    // 4. Se passou do "portão":
    
    $compraAtiva = Compra::findActiveByUser($pdo, $usuario->id);
    if ($compraAtiva) {
        localWriteToLog("Usuário tem uma compra ativa (ID: " . $compraAtiva->id . ")"); // <-- CORRIGIDO
    } else {
        localWriteToLog("Usuário não tem compra ativa."); // <-- CORRIGIDO
    }

    $bot = new BotController($pdo, $usuario, $compraAtiva);
    $respostaDoBot = $bot->processMessage($message_body); 
    localWriteToLog("Resposta do Bot: [ " . str_replace("\n", " ", $respostaDoBot) . " ]"); // <-- CORRIGIDO
    
    $waService->sendMessage($whatsapp_id, $respostaDoBot); 

    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Mensagem processada e resposta enviada']);

} catch (Exception $e) { 
    localWriteToLog("!!! ERRO GERAL / CRÍTICO !!!: " . $e->getMessage() . " (Ficheiro: " . $e->getFile() . " Linha: " . $e->getLine() . ")"); // <-- CORRIGIDO
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno de servidor']);
}

localWriteToLog("--- FIM DA REQUISIÇÃO ---" . PHP_EOL); // <-- CORRIGIDO
?>