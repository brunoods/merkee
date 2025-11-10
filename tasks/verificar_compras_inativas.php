<?php
// ---
// /tasks/verificar_compras_inativas.php
// (VERSÃO CORRIGIDA COM NAMESPACE E LOG RENOMEADO)
// ---

// 1. Incluir Arquivo ÚNICO de Bootstrap
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar os "Namespaces"
use App\Models\Usuario;
use App\Models\Compra;
use App\Models\Estabelecimento;
use App\Services\WhatsAppService;

// 3. (CORREÇÃO 1) Logging renomeado
$logFilePath = __DIR__ . '/../storage/cron_log.txt';
function localWriteToLog($message) { // <-- RENOMEADO
    global $logFilePath;
    writeToLog($logFilePath, $message, "CRON_JOB"); // Chama a global
}

localWriteToLog("--- CRON JOB INICIADO: Verificar compras inivas ---"); // <-- CORRIGIDO

// 4. Definir o tempo de inatividade
define('MINUTOS_INATIVIDADE', 10);

try {
    $pdo = getDbConnection(); // (Já usa $_ENV)
    
    // 5. Encontra compras ativas E inativas
    $sql = "
        SELECT * FROM compras 
        WHERE status = 'ativa' 
          AND ultimo_item_em < (NOW() - INTERVAL " . MINUTOS_INATIVIDADE . " MINUTE)
    ";
    
    $comprasInativas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (empty($comprasInativas)) {
        localWriteToLog("Nenhuma compra inativa encontrada. A sair."); // <-- CORRIGIDO
        exit; // Termina o script
    }

    localWriteToLog("Encontradas " . count($comprasInativas) . " compras inativas. A processar..."); // <-- CORRIGIDO
    
    // (CORREÇÃO 2) Esta classe agora usa $_ENV automaticamente
    $waService = new WhatsAppService(); 

    // 6. Envia mensagem para cada utilizador
    foreach ($comprasInativas as $compraData) {
        $compra = Compra::findById($pdo, $compraData['id']);
        $usuario = Usuario::findById($pdo, $compra->usuario_id);
        $est = Estabelecimento::findById($pdo, $compra->estabelecimento_id);
        
        if (!$compra || !$usuario || !$est) {
            localWriteToLog("A ignorar compra #{$compraData['id']}: dados inconsistentes (utilizador ou estab. apagado)."); // <-- CORRIGIDO
            continue;
        }
        
        // (Não precisamos verificar is_ativo, pois se a compra está 'ativa', o utilizador deve poder responder)

        $nomeEst = $est ? $est->nome : "um local desconhecido";
        $nomeUsuario = $usuario->nome ? $usuario->nome : "Olá";

        if ($usuario->conversa_estado) {
            localWriteToLog("A ignorar utilizador #{$usuario->id} (compra #{$compra->id}) porque já está num estado de conversa: {$usuario->conversa_estado}"); // <-- CORRIGIDO
            continue; 
        }

        // 7. Envia a mensagem proativa (com try/catch)
        $mensagem = "Olá {$nomeUsuario}! 👋\n\nNotei que não regista um item novo há alguns minutos.\n\nPosso finalizar esta compra no *{$nomeEst}*? (sim / nao)";
        
        try {
            $sucesso = $waService->sendMessage($usuario->whatsapp_id, $mensagem); 

            if ($sucesso) {
                // Coloca o utilizador no estado de espera
                $usuario->updateState(
                    $pdo, 
                    'aguardando_confirmacao_finalizacao',
                    ['compra_id' => $compra->id] 
                );
                localWriteToLog("Mensagem enviada para utilizador #{$usuario->id} (compra #{$compra->id})"); // <-- CORRIGIDO
            }
        } catch (Exception $e) {
            localWriteToLog( // <-- CORRIGIDO
                "!!! FALHA AO ENVIAR MENSAGEM para utilizador #{$usuario->id} (compra #{$compra->id}): " . $e->getMessage()
            );
        }
    }

} catch (Exception $e) {
    localWriteToLog("!!! ERRO CRÍTICO NO CRON JOB !!!: " . $e->getMessage()); // <-- CORRIGIDO
}

localWriteToLog("--- CRON JOB FINALIZADO ---"); // <-- CORRIGIDO
?>