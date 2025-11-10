<?php
// ---
// /public/webhook.php
// (VERSÃO COM BOOTSTRAP E NAMESPACE)
// ---

// 1. Incluir Arquivo ÚNICO de Bootstrap
// (Carrega .env, autoloader, getDbConnection() e writeToLog())
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar os "Namespaces" do Autoloader
use App\Models\Usuario;
use App\Models\Compra;
use App\Controllers\BotController;
use App\Services\WhatsAppService;

// 3. Logging (Define o ficheiro e o prefixo para este script)
$logFilePath = __DIR__ . '/../storage/webhook_log.txt';
function writeToLog($message) { // Função local para conveniência
    global $logFilePath;
    writeToLog($logFilePath, $message, "WEBHOOK"); // Chama a global
}
writeToLog("--- INÍCIO DA REQUISIÇÃO ---");

// 4. Capturar e Validar a Requisição (sem mudança)
$jsonPayload = file_get_contents('php://input');
$data = json_decode($jsonPayload, true); 
if (!$data) {
    writeToLog("Erro: Nenhum payload JSON recebido.");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Nenhum payload recebido']);
    exit;
}
writeToLog("Payload Recebido: " . $jsonPayload);

// 5. Extrair Dados da Mensagem (sem mudança)
$whatsapp_id = $data['sender']['id'] ?? $data['phone'] ?? null;
$message_body = $data['text']['message'] ?? $data['message']['body'] ?? null;
$user_name = $data['sender']['name'] ?? 'Visitante';
if (!$whatsapp_id || !$message_body) {
    writeToLog("Ignorado: Não é uma mensagem de texto de usuário válida.");
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Ignorado']);
    exit;
}
writeToLog("Processando: ID [{$whatsapp_id}] | Mensagem [{$message_body}]");


// 6. Lógica Principal (Agora apanha exceções do Bot/Serviços)
try {
    $pdo = getDbConnection();
    $waService = new WhatsAppService();

    // Passo 1: Encontrar ou criar o usuário
    $usuario = Usuario::findOrCreate($pdo, $whatsapp_id, $user_name);
    writeToLog("Usuário: ID #" . $usuario->id . " | Nome Confirmado: " . ($usuario->nome_confirmado ? 'SIM' : 'NÃO') . " | Ativo: " . ($usuario->is_ativo ? 'SIM' : 'NÃO'));


    // --- (LÓGICA DO "PORTÃO" - sem mudança) ---

    // 2. O "PORTÃO" (Gate) - PARTE 1: Pedir o Nome
    if ($usuario->nome_confirmado == false && $usuario->conversa_estado == null) {
        
        $usuario->updateState($pdo, 'aguardando_nome_para_onboarding');
        $respostaDoBot = "Olá! 👋 Vi que é a tua primeira vez aqui.\n\nPara começarmos, como gostarias de ser chamado(a)?";
        
        writeToLog("Usuário #{$usuario->id} novo. A pedir o nome.");
        $waService->sendMessage($whatsapp_id, $respostaDoBot); // (Lançará exceção se falhar)
        
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
             writeToLog("Usuário #{$usuario->id} INATIVO/EXPIRADO ({$motivo_bloqueio}). A enviar mensagem de bloqueio.");
             $waService->sendMessage($whatsapp_id, $respostaDoBot); // (Lançará exceção se falhar)
             $pdo->prepare("INSERT INTO logs_bloqueio (usuario_id, data_log) VALUES (?, CURDATE())")->execute([$usuario->id]);
        } else {
            writeToLog("Usuário #{$usuario->id} INATIVO/EXPIRADO. Ignorado silenciosamente (já notificado hoje).");
        }

        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Usuário inativo/expirado. Bloqueado.']);
        exit;
    }
    // --- (FIM DA ATUALIZAÇÃO DO "PORTÃO") ---


    // 4. Se passou do "portão":
    
    $compraAtiva = Compra::findActiveByUser($pdo, $usuario->id);
    if ($compraAtiva) {
        writeToLog("Usuário tem uma compra ativa (ID: " . $compraAtiva->id . ")");
    } else {
        writeToLog("Usuário não tem compra ativa.");
    }

    $bot = new BotController($pdo, $usuario, $compraAtiva);
    $respostaDoBot = $bot->processMessage($message_body); // (Lançará exceção de DB se falhar)
    writeToLog("Resposta do Bot: [ " . str_replace("\n", " ", $respostaDoBot) . " ]");
    
    $waService->sendMessage($whatsapp_id, $respostaDoBot); // (Lançará exceção de API se falhar)

    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Mensagem processada e resposta enviada']);

} catch (Exception $e) { 
    // (Este bloco agora apanha erros de DB, API, Bot, etc.)
    writeToLog("!!! ERRO GERAL / CRÍTICO !!!: " . $e->getMessage() . " (Ficheiro: " . $e->getFile() . " Linha: " . $e->getLine() . ")");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Erro interno de servidor']);
}

writeToLog("--- FIM DA REQUISIÇÃO ---" . PHP_EOL);
?>