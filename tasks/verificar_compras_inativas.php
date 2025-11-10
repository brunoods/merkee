<?php
// ---
// /tasks/verificar_compras_inativas.php
// (VERSÃO COM BOOTSTRAP E NAMESPACE)
// ---

// 1. Incluir Arquivo ÚNICO de Bootstrap
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar os "Namespaces"
use App\Models\Usuario;
use App\Models\Compra;
use App\Models\Estabelecimento;
use App\Services\WhatsAppService;

// 3. Logging
$logFilePath = __DIR__ . '/../storage/cron_log.txt';
function writeToLog($message) {
    global $logFilePath;
    writeToLog($logFilePath, $message, "CRON_JOB"); // Chama a global
}

writeToLog("--- CRON JOB INICIADO: Verificar compras inivas ---");

// 4. Definir o tempo de inatividade
define('MINUTOS_INATIVIDADE', 10);

try {
    $pdo = getDbConnection();
    
    // 5. Encontra compras ativas E inativas
    $sql = "
        SELECT * FROM compras 
        WHERE status = 'ativa' 
          AND ultimo_item_em < (NOW() - INTERVAL " . MINUTOS_INATIVIDADE . " MINUTE)
    ";
    
    $comprasInativas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (empty($comprasInativas)) {
        writeToLog("Nenhuma compra inativa encontrada. A sair.");
        exit; // Termina o script
    }

    writeToLog("Encontradas " . count($comprasInativas) . " compras inativas. A processar...");
    $waService = new WhatsAppService();

    // 6. Envia mensagem para cada utilizador
    foreach ($comprasInativas as $compraData) {
        $compra = Compra::findById($pdo, $compraData['id']);
        $usuario = Usuario::findById($pdo, $compra->usuario_id);
        $est = Estabelecimento::findById($pdo, $compra->estabelecimento_id);
        
        // (Pequena salvaguarda caso algo seja apagado)
        if (!$compra || !$usuario || !$est) {
            writeToLog("A ignorar compra #{$compraData['id']}: dados inconsistentes (utilizador ou estab. apagado).");
            continue;
        }

        $nomeEst = $est ? $est->nome : "um local desconhecido";
        $nomeUsuario = $usuario->nome ? $usuario->nome : "Olá";

        if ($usuario->conversa_estado) {
            writeToLog("A ignorar utilizador #{$usuario->id} (compra #{$compra->id}) porque já está num estado de conversa: {$usuario->conversa_estado}");
            continue; 
        }

        // 7. Envia a mensagem proativa (com try/catch)
        $mensagem = "Olá {$nomeUsuario}! 👋\n\nNotei que não regista um item novo há alguns minutos.\n\nPosso finalizar esta compra no *{$nomeEst}*? (sim / nao)";
        
        try {
            $sucesso = $waService->sendMessage($usuario->whatsapp_id, $mensagem); // (Retorna true ou lança exceção)

            if ($sucesso) {
                // Coloca o utilizador no estado de espera
                $usuario->updateState(
                    $pdo, 
                    'aguardando_confirmacao_finalizacao',
                    ['compra_id' => $compra->id] 
                );
                writeToLog("Mensagem enviada para utilizador #{$usuario->id} (compra #{$compra->id})");
            }
        } catch (Exception $e) {
            // (NOVO) Captura a exceção do WhatsAppService
            writeToLog(
                "!!! FALHA AO ENVIAR MENSAGEM para utilizador #{$usuario->id} (compra #{$compra->id}): " . $e->getMessage()
            );
        }
    }

} catch (Exception $e) {
    writeToLog("!!! ERRO CRÍTICO NO CRON JOB !!!: " . $e->getMessage());
}

writeToLog("--- CRON JOB FINALIZADO ---");
?>