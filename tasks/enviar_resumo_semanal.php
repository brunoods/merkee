<?php
// ---
// /tasks/enviar_resumo_semanal.php
// (VERSÃO COM BOOTSTRAP, NAMESPACE E FIX IS_ATIVO)
// ---

// 1. (A CORREÇÃO) Incluir Arquivo ÚNICO de Bootstrap
require_once __DIR__ . '/../config/bootstrap.php';

// 2. (A CORREÇÃO) Usar os "Namespaces"
use App\Models\Usuario;
use App\Services\WhatsAppService;

// 3. (A CORREÇÃO) Logging
$logFilePath = __DIR__ . '/../storage/cron_resumo_log.txt'; 
function writeToLog($message) {
    global $logFilePath;
    writeToLog($logFilePath, $message, "CRON_RESUMO"); // Chama a global
}

writeToLog("--- CRON RESUMO SEMANAL INICIADO ---");

try {
    $pdo = getDbConnection();
    $waService = new WhatsAppService();

    // (Usa a classe Usuario importada)
    $usuarios = Usuario::findAll($pdo); // (Agora contém 'is_ativo')
    if (empty($usuarios)) {
        writeToLog("Nenhum usuário encontrado.");
        exit;
    }

    writeToLog("A verificar resumos para " . count($usuarios) . " usuários...");

    foreach ($usuarios as $usuario) {
        
        // 4. (A CORREÇÃO) Não enviar para usuários inativos
        if ($usuario->is_ativo === false) {
            writeToLog("A saltar Usuário #{$usuario->id}: Inativo.");
            continue;
        }

        // (VERIFICAÇÃO ANTIGA - manter)
        if ($usuario->receber_alertas === false) { // (Usa a config de 'alertas')
            writeToLog("A saltar Usuário #{$usuario->id}: Alertas (e resumos) desativados.");
            continue; 
        }
        
        $nomeUsuario = $usuario->nome ? explode(' ', $usuario->nome)[0] : "Olá"; 

        // Query SQL (idêntica)
        $sql = "
            SELECT SUM((i.preco_normal - i.preco) * i.quantidade) as total_poupado
            FROM itens_compra i
            JOIN compras c ON i.compra_id = c.id
            WHERE c.usuario_id = ?
              AND c.status = 'finalizada'
              AND i.em_promocao = 1 
              AND i.preco_normal > i.preco 
              AND c.data_fim >= (NOW() - INTERVAL 7 DAY)
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario->id]);
        $resultado = $stmt->fetch();

        $totalPoupado = (float)($resultado['total_poupado'] ?? 0.0);

        if ($totalPoupado > 0.1) { 
            
            $poupadoFmt = number_format($totalPoupado, 2, ',', '.');
            $mensagem = "Olá, {$nomeUsuario}! 👋\n\nSó a passar para te dar os parabéns! 🥳\n\nNos últimos 7 dias, ao registares as tuas promoções comigo, poupaste um total de **R$ {$poupadoFmt}**! 💰\n\nContinua assim! 📈";
            
            try {
                $waService->sendMessage($usuario->whatsapp_id, $mensagem); 
                writeToLog("... Mensagem de resumo enviada para Usuário #{$usuario->id} (Poupou R$ {$poupadoFmt})");
            } catch (Exception $e) {
                 writeToLog(
                    "!!! FALHA AO ENVIAR RESUMO para utilizador #{$usuario->id}: " . $e->getMessage()
                );
            }

        } else {
            writeToLog("... Usuário #{$usuario->id} sem poupanças registadas esta semana. A saltar.");
        }
    }

} catch (Exception $e) {
    writeToLog("!!! ERRO CRÍTICO NO CRON RESUMO !!!: " . $e->getMessage());
}

writeToLog("--- CRON RESUMO FINALIZADO ---");
?>