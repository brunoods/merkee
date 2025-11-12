<?php
// ---
// /public/webhook.php
// (VERSÃO FINAL COM LÓGICA "FREEMIUM" E CORREÇÃO DE REVOGAÇÃO)
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

// --- !! Resposta Rápida para a API (evita loops) !! ---
http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Payload recebido e em processamento.']);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
// --- !! FIM DA RESPOSTA RÁPIDA !! ---


// 6. Lógica Principal (Agora executada em "background")
localWriteToLog("Processando: ID [{$whatsapp_id}] | Mensagem [{$message_body}]");

try {
    $pdo = getDbConnection(); 
    $waService = new WhatsAppService(); 

    // Carrega o utilizador
    $usuario = Usuario::findOrCreate($pdo, $whatsapp_id, 'Visitante');
    localWriteToLog("Usuário: ID #" . $usuario->id . " | Nome Confirmado: " . ($usuario->nome_confirmado ? 'SIM' : 'NÃO') . " | Ativo: " . ($usuario->is_ativo ? 'SIM' : 'NÃO') . " | Expira em: " . ($usuario->data_expiracao ?? 'N/A'));
    
    // (O "Portão" de Onboarding)
    if ($usuario->nome_confirmado == false && $usuario->conversa_estado == null) {
        $usuario->updateState($pdo, 'aguardando_nome_para_onboarding');
        $respostaDoBot = "Olá! 👋 Vi que é a tua primeira vez aqui.\n\nPara começarmos, como gostarias de ser chamado(a)?";
        localWriteToLog("Usuário #{$usuario->id} novo. A pedir o nome.");
        $waService->sendMessage($whatsapp_id, $respostaDoBot); 
        exit; // Termina o script de background
    }
    
    // --- (INÍCIO DA CORREÇÃO DO PORTÃO "FREEMIUM" v2) ---

    // (O "Portão" de Subscrição - LÓGICA "FREEMIUM" CORRIGIDA E MAIS RIGOROSA)
    $hoje = new DateTime();
    $data_exp = $usuario->data_expiracao ? new DateTime($usuario->data_expiracao) : null;
    $is_valido = false;
    $motivo_bloqueio = "";

    // 1. Está em onboarding? (Prioridade máxima)
    if ($usuario->conversa_estado === 'aguardando_nome_para_onboarding' || $usuario->conversa_estado === 'aguardando_decisao_onboarding') {
        $is_valido = true;
        localWriteToLog("Usuário #{$usuario->id} está em onboarding. Acesso permitido.");
    
    // 2. É um utilizador novo? (nunca teve trial/assinatura E ESTÁ ATIVO)
    // NOTA: O findOrCreate define 'is_ativo' como FALSE.
    // Temos de assumir que o "freemium" significa 'data_expiracao' é nula, e ignorar o 'is_ativo' SÓ neste caso.
    } elseif ($data_exp === null) {
        // Se a data de expiração é NULA, é um novo utilizador.
        // A tua regra de negócio é: "novo usuario ... pode usar o bot normal"
        // Então, permitimos o acesso.
        $is_valido = true;
        localWriteToLog("Usuário #{$usuario->id} é novo (sem data expiração). Acesso permitido (Freemium).");

    // 3. Já teve trial/assinatura (data_expiracao NÃO é nula). Está ativo E a data é válida?
    } elseif ($usuario->is_ativo && $data_exp >= $hoje) {
        $is_valido = true; // Assinatura/Trial ativo
        localWriteToLog("Usuário #{$usuario->id} está ativo (Assinatura/Trial válido). Acesso permitido.");
    
    // 4. Se chegou aqui, está inválido (expirado OU revogado)
    } else {
        $is_valido = false;
        // Calcula o motivo do bloqueio para o log
        if ($data_exp < $hoje) {
            $motivo_bloqueio = "expirou em " . $data_exp->format('d/m/Y H:i');
        } else {
            // Este é o teu caso de teste: (data_exp > hoje) MAS (is_ativo = 0)
            $motivo_bloqueio = "foi revogado (is_ativo=0)";
        }
        localWriteToLog("Usuário #{$usuario->id} INATIVO/EXPIRADO ({$motivo_bloqueio}). Acesso NEGADO.");
    }


    if ($is_valido == false) {
        // (Lógica de enviar mensagem de bloqueio, se não enviado hoje)
        $checkLogStmt = $pdo->prepare("SELECT COUNT(*) FROM logs_bloqueio WHERE usuario_id = ? AND data_log = CURDATE()");
        $checkLogStmt->execute([$usuario->id]);
        
        if ($checkLogStmt->fetchColumn() == 0) {
             // Esta é a mensagem de bloqueio correta
             $respostaDoBot = "O seu período de teste (ou assinatura) terminou. ⏳\n\nPara continuar a usar o bot, precisas de ativar a tua assinatura.\n\nEnvia *login* para acederes ao teu painel e subscreveres.";
             
             localWriteToLog("A enviar mensagem de bloqueio para Usuário #{$usuario->id}.");
             
             $waService->sendMessage($whatsapp_id, $respostaDoBot); 
             // Regista que já enviámos a mensagem hoje
             $pdo->prepare("INSERT INTO logs_bloqueio (usuario_id, data_log) VALUES (?, CURDATE())")->execute([$usuario->id]);
        } else {
            localWriteToLog("Usuário #{$usuario->id} INATIVO/EXPIRADO. Mensagem de bloqueio já enviada hoje. Ignorando.");
        }
        exit; // Termina o script de background
    }

    // --- (FIM DA CORREÇÃO DO PORTÃO "FREEMIUM" v2) ---

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