<?php
// ---
// /public/webhook.php
// (VERSÃO FINAL COMPLETA - COM LEITURA DE LOCALIZAÇÃO E LOG CORRIGIDO)
// ---

// (Linhas de debug para encontrar erros fatais)
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

// 3. Logging (Função local renomeada para não colidir com a global)
$logFilePath = __DIR__ . '/../storage/webhook_log.txt';
function localWriteToLog($message) { 
    global $logFilePath;
    // Chama a função GLOBAL (definida no bootstrap.php)
    writeToLog($logFilePath, $message, "WEBHOOK"); 
}
localWriteToLog("--- INÍCIO DA REQUISIÇÃO ---");

// 4. Capturar e Validar a Requisição
$jsonPayload = file_get_contents('php://input');
$data = json_decode($jsonPayload, true); 
if (!$data) {
    localWriteToLog("Erro: Nenhum payload JSON recebido.");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nenhum payload recebido']);
    exit;
}
localWriteToLog("Payload Recebido: " . $jsonPayload);

// 5. Extrair Dados da Mensagem (AGORA INCLUI LOCALIZAÇÃO)
$whatsapp_id = $data['sender']['id'] ?? $data['phone'] ?? null;
$user_name = $data['sender']['name'] ?? 'Visitante';

$message_body = null;
$contexto_extra = []; // (Para enviar a localização para o Bot)

if (isset($data['text']['message'])) {
    // É uma mensagem de texto
    $message_body = $data['text']['message'];
    
} elseif (isset($data['location'])) {
    // É uma mensagem de localização!
    $message_body = 'USER_SENT_LOCATION'; // Palavra-chave especial
    $contexto_extra['location'] = [
        'latitude' => $data['location']['latitude'],
        'longitude' => $data['location']['longitude']
    ];
    localWriteToLog("Recebida localização: Lat " . $data['location']['latitude'] . ", Lon " . $data['location']['longitude']);
    
} else {
    // Outro tipo (imagem, áudio, etc.) - Ignoramos
    localWriteToLog("Ignorado: Não é uma mensagem de texto ou localização.");
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Ignorado (não é texto/localização)']);
    exit;
}

if (!$whatsapp_id || !$message_body) {
    localWriteToLog("Ignorado: WhatsApp ID ou Corpo da Mensagem em falta.");
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Ignorado']);
    exit;
}
localWriteToLog("Processando: ID [{$whatsapp_id}] | Mensagem [{$message_body}]");


// 6. Lógica Principal (com try/catch de erros)
try {
    $pdo = getDbConnection(); // (Vem do bootstrap, já usa $_ENV)
    $waService = new WhatsAppService(); // (Vem dos Services, já usa $_ENV)

    // Passo 1: Encontrar ou criar o usuário
    $usuario = Usuario::findOrCreate($pdo, $whatsapp_id, $user_name);
    localWriteToLog("Usuário: ID #" . $usuario->id . " | Nome Confirmado: " . ($usuario->nome_confirmado ? 'SIM' : 'NÃO') . " | Ativo: " . ($usuario->is_ativo ? 'SIM' : 'NÃO'));


    // --- (LÓGICA DO "PORTÃO" DE ACESSO) ---

    // 2. O "PORTÃO" (Gate) - PARTE 1: Pedir o Nome
    if ($usuario->nome_confirmado == false && $usuario->conversa_estado == null) {
        
        $usuario->updateState($pdo, 'aguardando_nome_para_onboarding');
        $respostaDoBot = "Olá! 👋 Vi que é a tua primeira vez aqui.\n\nPara começarmos, como gostarias de ser chamado(a)?";
        
        localWriteToLog("Usuário #{$usuario->id} novo. A pedir o nome.");
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
    
    // (Permite que o fluxo de onboarding passe, mesmo se inativo)
    $is_valido = ($usuario->conversa_estado === 'aguardando_nome_para_onboarding' || $usuario->conversa_estado === 'aguardando_decisao_onboarding') ? true : $is_valido;

    if ($is_valido == false) {
        
        $respostaDoBot = "Olá, {$usuario->nome}! 🔒\n\nA tua subscrição do WalletlyBot {$motivo_bloqueio}.\n\nPara renovares ou saberes mais, contacta o administrador.";
        
        $checkLogStmt = $pdo->prepare("SELECT COUNT(*) FROM logs_bloqueio WHERE usuario_id = ? AND data_log = CURDATE()");
        $checkLogStmt->execute([$usuario->id]);
        $ja_enviado_hoje = $checkLogStmt->fetchColumn() > 0;

        if (!$ja_enviado_hoje) {
             localWriteToLog("Usuário #{$usuario->id} INATIVO/EXPIRADO ({$motivo_bloqueio}). A enviar mensagem de bloqueio.");
             $waService->sendMessage($whatsapp_id, $respostaDoBot); 
             $pdo->prepare("INSERT INTO logs_bloqueio (usuario_id, data_log) VALUES (?, CURDATE())")->execute([$usuario->id]);
        } else {
            localWriteToLog("Usuário #{$usuario->id} INATIVO/EXPIRADO. Ignorado silenciosamente (já notificado hoje).");
        }

        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Usuário inativo/expirado. Bloqueado.']);
        exit;
    }
    // --- (FIM DO "PORTÃO") ---


    // 4. Se passou do "portão":
    
    $compraAtiva = Compra::findActiveByUser($pdo, $usuario->id);
    if ($compraAtiva) {
        localWriteToLog("Usuário tem uma compra ativa (ID: " . $compraAtiva->id . ")");
    } else {
        localWriteToLog("Usuário não tem compra ativa.");
    }

    $bot = new BotController($pdo, $usuario, $compraAtiva);
    
    // (Passa o contexto_extra, que pode ter a localização)
    $respostaDoBot = $bot->processMessage($message_body, $contexto_extra); 
    
    localWriteToLog("Resposta do Bot: [ " . str_replace("\n", " ", $respostaDoBot) . " ]");
    
    $waService->sendMessage($whatsapp_id, $respostaDoBot); 

    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Mensagem processada e resposta enviada']);

} catch (Exception $e) { 
    // (Este bloco apanha erros de DB, API, Bot, etc.)
    localWriteToLog("!!! ERRO GERAL / CRÍTICO !!!: " . $e->getMessage() . " (Ficheiro: " . $e->getFile() . " Linha: " . $e->getLine() . ")");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno de servidor']);
}

localWriteToLog("--- FIM DA REQUISIÇÃO ---" . PHP_EOL);
?>