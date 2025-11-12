<?php
// ---
// /public/webhook_mercadopago.php
// (VERSÃO CORRIGIDA para o SDK dx-php v2)
// ---

// 1. Inicialização
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Importar Classes do SDK
// --- (INÍCIO DA CORREÇÃO) ---
use MercadoPago\MercadoPagoConfig; // Substitui 'use MercadoPago\SDK;'
use MercadoPago\Client\Preapproval\PreapprovalClient;
use MercadoPago\Exceptions\MPException; // (Mantém-se igual)
// --- (FIM DA CORREÇÃO) ---

// 3. Definir o ficheiro de log
$logFile = __DIR__ . '/../storage/webhook_log.txt';
$logPrefix = "MERCADOPAGO";

// 4. Começar o Log
writeToLog($logFile, "Webhook recebido.", $logPrefix);

// 5. Ler os dados da notificação
$jsonInput = file_get_contents('php://input');
$notificationData = json_decode($jsonInput, true);
writeToLog($logFile, "Dados recebidos: " . $jsonInput, $logPrefix);

// HTTP 200 OK - Responde ao Mercado Pago IMEDIATAMENTE.
http_response_code(200);
ob_start();
echo "OK";
header('Connection: close');
header('Content-Length: ' . ob_get_length());
ob_end_flush();
flush();
// --- O script continua a executar em segundo plano ---

try {
    // 6. Verificar se é uma notificação de Assinatura (Preapproval)
    if (!isset($notificationData['topic']) || $notificationData['topic'] !== 'preapproval' || !isset($notificationData['id'])) {
        writeToLog($logFile, "Não é um tópico de 'preapproval' ou falta ID. Ignorando.", $logPrefix);
        exit; 
    }

    $preapprovalId = $notificationData['id'];
    writeToLog($logFile, "Processando ID de Preapproval: $preapprovalId", $logPrefix);

    // 7. Configurar SDK para buscar os detalhes
    $accessToken = $_ENV['MP_ACCESS_TOKEN'] ?? getenv('MP_ACCESS_TOKEN');
    if (empty($accessToken)) {
        throw new Exception("MP_ACCESS_TOKEN não está definido no .env");
    }
    // --- (INÍCIO DA CORREÇÃO) ---
    MercadoPagoConfig::setAccessToken($accessToken); // Substitui 'SDK::setAccessToken(...)'
    // --- (FIM DA CORREÇÃO) ---

    // 8. Buscar os detalhes da assinatura no Mercado Pago
    $client = new PreapprovalClient();
    $preapproval = $client->get($preapprovalId);

    if (!$preapproval) {
        throw new Exception("Não foi possível encontrar a preapproval com ID: $preapprovalId");
    }

    writeToLog($logFile, "Detalhes da Assinatura: " . json_encode($preapproval), $logPrefix);

    // 9. Extrair os dados importantes
    $userId = (int)$preapproval->external_reference; 
    $status = $preapproval->status;                 

    if ($userId <= 0) {
        throw new Exception("External Reference (ID do Utilizador) inválida.");
    }

    // 10. Atualizar a Base de Dados
    $pdo = getDbConnection(); 

    if ($status === 'authorized') {
        $novaDataExpiracao = (new DateTime('+30 days'))->format('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare(
            "UPDATE usuarios 
             SET is_ativo = 1, data_expiracao = ? 
             WHERE id = ?"
        );
        $stmt->execute([$novaDataExpiracao, $userId]);

        writeToLog($logFile, "SUCESSO: Utilizador ID $userId atualizado. Ativo até $novaDataExpiracao.", $logPrefix);
    
    } else {
        if ($status === 'cancelled' || $status === 'paused') {
            $stmt = $pdo->prepare(
                "UPDATE usuarios SET is_ativo = 0 WHERE id = ?"
            );
            $stmt->execute([$userId]);
            
            writeToLog($logFile, "AVISO: Utilizador ID $userId desativado. Status: $status.", $logPrefix);
        } else {
             writeToLog($logFile, "INFO: Status recebido '$status' para User ID $userId. Nenhuma ação de DB tomada.", $logPrefix);
        }
    }

} catch (Exception $e) {
    // 11. Lidar com erros
    writeToLog($logFile, "ERRO CRÍTICO no Webhook: " . $e->getMessage(), $logPrefix);
    http_response_code(500); 
}