<?php
// ---
// /public/webhook.php
// (VERSÃO FINAL COM CORREÇÃO DE LOOP/TIMEOUT DA META)
// ---

// (Debug)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../storage/PHP_FATAL_ERROR.log');

// 1. Incluir Bootstrap
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar Namespaces
use App\Models\Usuario;
use App\Models\Compra;
use App\Controllers\BotController;
use App\Services\WhatsAppService;

// 3. Logging
$logFilePath = __DIR__ . '/../storage/webhook_log.txt';
function localWriteToLog($message) { 
    global $logFilePath;
    writeToLog($logFilePath, $message, "WEBHOOK"); 
}

// ==========================================================
// PASSO A: VERIFICAÇÃO DO ENDPOINT (GET REQUEST)
// ==========================================================
if (isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
    // (A sua lógica de verificação que já funcionou)
    $verifyToken = $_ENV['WEBHOOK_VERIFY_TOKEN'] ?? getenv('WEBHOOK_VERIFY_TOKEN');
    $challenge = $_GET['hub_challenge'] ?? null;
    if ($challenge && $verifyToken && $_GET['hub_verify_token'] === $verifyToken) {
        http_response_code(200);
        echo $challenge;
        localWriteToLog("--- VERIFICAÇÃO DE WEBHOOK BEM SUCEDIDA ---");
        exit;
    } else {
        http_response_code(403);
        localWriteToLog("!!! FALHA NA VERIFICAÇÃO DO WEBHOOK !!!");
        exit;
    }
}

// ==========================================================
// PASSO B: PROCESSAMENTO DE MENSAGENS (POST REQUEST)
// ==========================================================

localWriteToLog("--- INÍCIO DA REQUISIÇÃO (POST) ---");

// 4. Capturar e Validar a Requisição
$jsonPayload = file_get_contents('php://input');
$data = json_decode($jsonPayload, true); 
localWriteToLog("Payload Recebido: " . $jsonPayload);

// 5. Extrair Dados da Mensagem (Estrutura da Meta API)
$messageData = $data['entry'][0]['changes'][0]['value']['messages'][0] ?? null;

if (!$messageData) {
    localWriteToLog("Ignorado: Payload sem dados de mensagem (Status de entrega, etc.).");
    http_response_code(200); // Diz OK para a Meta
    exit;
}

$whatsapp_id = $messageData['from'];
$message_type = $messageData['type'];

$message_body = null;
$contexto_extra = [];

if ($message_type === 'text') {
    $message_body = $messageData['text']['body'];
} elseif ($message_type === 'location') {
    $message_body = 'USER_SENT_LOCATION';
    $contexto_extra['location'] = [
        'latitude' => $messageData['location']['latitude'],
        'longitude' => $messageData['location']['longitude']
    ];
} else {
    localWriteToLog("Ignorado: Tipo '{$message_type}' não suportado.");
    http_response_code(200); // Diz OK para a Meta
    exit;
}

if (empty($message_body)) {
     localWriteToLog("Ignorado: Corpo da mensagem vazio.");
     http_response_code(200); // Diz OK para a Meta
     exit;
}

// --- !! INÍCIO DA CORREÇÃO DO LOOP !! ---

// 1. Envia a resposta HTTP 200 OK IMEDIATAMENTE.
// Diz à Meta: "Recebi, pode parar de reenviar."
http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Payload recebido e em processamento.']);

// 2. Se o PHP-FPM estiver a ser usado, esta função envia a resposta
// mas permite que o script continue a ser executado em background.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
// Se não estiver a usar FPM, o script continua e envia a resposta no final,
// mas a Meta pode reenviar se demorar muito.

// --- !! FIM DA CORREÇÃO DO LOOP !! ---


// 6. Lógica Principal (Agora executada em "background")
localWriteToLog("Processando: ID [{$whatsapp_id}] | Mensagem [{$message_body}]");

try {
    $pdo = getDbConnection(); 
    $waService = new WhatsAppService(); 

    // (Toda a sua lógica de "Portão de Acesso" (Onboarding/Subscrição)
    // permanece a mesma que antes)
    
    // ... (Lógica do "Portão" aqui: findOrCreate, verificar nome_confirmado, verificar is_ativo)...
    $usuario = Usuario::findOrCreate($pdo, $whatsapp_id, 'Visitante');
    localWriteToLog("Usuário: ID #" . $usuario->id . " | Nome Confirmado: " . ($usuario->nome_confirmado ? 'SIM' : 'NÃO') . " | Ativo: " . ($usuario->is_ativo ? 'SIM' : 'NÃO'));
    
    // (O "Portão" de Onboarding)
    if ($usuario->nome_confirmado == false && $usuario->conversa_estado == null) {
        $usuario->updateState($pdo, 'aguardando_nome_para_onboarding');
        $respostaDoBot = "Olá! 👋 Vi que é a tua primeira vez aqui.\n\nPara começarmos, como gostarias de ser chamado(a)?";
        localWriteToLog("Usuário #{$usuario->id} novo. A pedir o nome.");
        $waService->sendMessage($whatsapp_id, $respostaDoBot); 
        exit; // Termina o script de background
    }
    
    // (O "Portão" de Subscrição)
    $hoje = new DateTime();
    $data_exp = $usuario->data_expiracao ? new DateTime($usuario->data_expiracao) : null;
    $is_valido = false;
    $motivo_bloqueio = "não está ativo";

    if ($usuario->is_ativo && $data_exp && $data_exp >= $hoje) $is_valido = true;
    elseif ($usuario->is_ativo && $data_exp && $data_exp < $hoje) $motivo_bloqueio = "expirou em " . $data_exp->format('d/m/Y');
    elseif (!$usuario->is_ativo) $motivo_bloqueio = "está revogado ou pendente de ativação";
    
    $is_valido = ($usuario->conversa_estado === 'aguardando_nome_para_onboarding' || $usuario->conversa_estado === 'aguardando_decisao_onboarding') ? true : $is_valido;

    if ($is_valido == false) {
        // (Lógica de enviar mensagem de bloqueio, se não enviado hoje)
        $checkLogStmt = $pdo->prepare("SELECT COUNT(*) FROM logs_bloqueio WHERE usuario_id = ? AND data_log = CURDATE()");
        $checkLogStmt->execute([$usuario->id]);
        if ($checkLogStmt->fetchColumn() == 0) {
             $respostaDoBot = "Olá, {$usuario->nome}! 🔒\n\nA tua subscrição do Merkee {$motivo_bloqueio}.\n\nContacta o administrador.";
             localWriteToLog("Usuário #{$usuario->id} INATIVO/EXPIRADO ({$motivo_bloqueio}). A enviar mensagem de bloqueio.");
             $waService->sendMessage($whatsapp_id, $respostaDoBot); 
             $pdo->prepare("INSERT INTO logs_bloqueio (usuario_id, data_log) VALUES (?, CURDATE())")->execute([$usuario->id]);
        }
        exit; // Termina o script de background
    }

    // Se passou do "portão":
    $compraAtiva = Compra::findActiveByUser($pdo, $usuario->id);
    
    $bot = new BotController($pdo, $usuario, $compraAtiva);
    $respostaDoBot = $bot->processMessage($message_body, $contexto_extra); 
    
    localWriteToLog("Resposta do Bot: [ " . str_replace("\n", " ", $respostaDoBot) . " ]");
    
    $waService->sendMessage($whatsapp_id, $respostaDoBot); 

} catch (Exception $e) { 
    // Se falhar, apenas logamos. Não podemos enviar 500 pois já enviámos 200.
    localWriteToLog("!!! ERRO GERAL (Pós-Resposta) !!!: " . $e->getMessage() . " (Ficheiro: " . $e->getFile() . " Linha: " . $e->getLine() . ")");
}

localWriteToLog("--- FIM DA REQUISIÇÃO (Processamento em Background) ---" . PHP_EOL);
?>