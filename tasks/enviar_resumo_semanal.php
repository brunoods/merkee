<?php
// ---
// /tasks/enviar_resumo_semanal.php
// (VERSÃO CORRIGIDA COM NAMESPACE E LOG RENOMEADO)
// ---

// 1. Incluir Arquivo ÚNICO de Bootstrap
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar os "Namespaces"
use App\Models\Usuario;
use App\Services\WhatsAppService;

// 3. (CORREÇÃO 1) Logging renomeado
$logFilePath = __DIR__ . '/../storage/cron_resumo_log.txt'; 
function localWriteToLog($message) { // <-- RENOMEADO
    global $logFilePath;
    writeToLog($logFilePath, $message, "CRON_RESUMO"); // Chama a global
}

localWriteToLog("--- CRON RESUMO SEMANAL INICIADO ---"); // <-- CORRIGIDO

try {
    $pdo = getDbConnection(); // (Já usa $_ENV)
    $waService = new WhatsAppService(); // (Já usa $_ENV)

    $usuarios = Usuario::findAll($pdo);
    if (empty($usuarios)) {
        localWriteToLog("Nenhum usuário encontrado."); // <-- CORRIGIDO
        exit;
    }

    localWriteToLog("A verificar resumos para " . count($usuarios) . " usuários..."); // <-- CORRIGIDO

    foreach ($usuarios as $usuario) {
        
        // 4. (CORREÇÃO 2) Não enviar para utilizadores inativos
        if ($usuario->is_ativo === false) {
            localWriteToLog("A saltar Usuário #{$usuario->id}: Inativo."); // <-- CORRIGIDO
            continue;
        }

        // 5. Verifica se o utilizador quer receber alertas/resumos
        if ($usuario->receber_alertas === false) { 
            localWriteToLog("A saltar Usuário #{$usuario->id}: Alertas (e resumos) desativados."); // <-- CORRIGIDO
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
                localWriteToLog("... Mensagem de resumo enviada para Usuário #{$usuario->id} (Poupou R$ {$poupadoFmt})"); // <-- CORRIGIDO
            } catch (Exception $e) {
                 localWriteToLog( // <-- CORRIGIDO
                    "!!! FALHA AO ENVIAR RESUMO para utilizador #{$usuario->id}: " . $e->getMessage()
                );
            }

        } else {
            localWriteToLog("... Usuário #{$usuario->id} sem poupanças registadas esta semana. A saltar."); // <-- CORRIGIDO
        }
    }

} catch (Exception $e) {
    localWriteToLog("!!! ERRO CRÍTICO NO CRON RESUMO !!!: " . $e->getMessage()); // <-- CORRIGIDO
}

localWriteToLog("--- CRON RESUMO FINALIZADO ---"); // <-- CORRIGIDO
?>