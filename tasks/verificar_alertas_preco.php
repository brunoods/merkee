<?php
// ---
// /tasks/verificar_alertas_preco.php
// (VERSÃO CORRIGIDA COM $_ENV E LOG RENOMEADO)
// ---

// 1. Incluir Arquivo ÚNICO de Bootstrap
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar os "Namespaces"
use App\Models\Usuario;
use App\Models\Compra;
use App\Models\Estabelecimento;
use App\Models\HistoricoPreco;
use App\Services\WhatsAppService;
use App\Utils\StringUtils; 

// 3. (CORREÇÃO 1) Logging renomeado
$logFilePath = __DIR__ . '/../storage/cron_alertas_log.txt'; 
function localWriteToLog($message) { // <-- RENOMEADO
    global $logFilePath;
    writeToLog($logFilePath, $message, "CRON_ALERTAS"); // Chama a global
}

localWriteToLog("--- CRON ALERTA INICIADO ---"); // <-- CORRIGIDO

define('PERCENTUAL_QUEDA_ALERTA', 15.0); 
define('DIAS_BUSCA_PRECO_ATUAL', 7);     

try {
    $pdo = getDbConnection(); // (Já usa $_ENV, vem do bootstrap.php)
    
    // (CORREÇÃO 2) Esta classe agora usa $_ENV automaticamente
    $waService = new WhatsAppService(); 

    $usuarios = Usuario::findAll($pdo); 
    if (empty($usuarios)) {
        localWriteToLog("Nenhum usuário encontrado."); // <-- CORRIGIDO
        exit;
    }
    localWriteToLog("A verificar alertas para " . count($usuarios) . " usuários..."); // <-- CORRIGIDO

    foreach ($usuarios as $usuario) {
        
        if ($usuario->is_ativo === false) {
            localWriteToLog("A saltar Usuário #{$usuario->id}: Inativo."); // <-- CORRIGIDO
            continue;
        }
        
        if ($usuario->receber_alertas === false) {
            localWriteToLog("A saltar Usuário #{$usuario->id}: Alertas desativados."); // <-- CORRIGIDO
            continue; 
        }

        $ultimoLocal = Compra::findLastCompletedByUser($pdo, $usuario->id);
        if (!$ultimoLocal || empty($ultimoLocal['cidade'])) {
            localWriteToLog("A saltar Usuário #{$usuario->id}: Sem cidade definida."); // <-- CORRIGIDO
            continue; 
        }
        $cidadeUsuario = $ultimoLocal['cidade'];
        $nomeUsuario = $usuario->nome ? explode(' ', $usuario->nome)[0] : "Olá";
        localWriteToLog("A processar Usuário #{$usuario->id} ({$nomeUsuario}) na cidade: {$cidadeUsuario}..."); // <-- CORRIGIDO

        $produtosFavoritos = HistoricoPreco::findFavoriteProductNames($pdo, $usuario->id, 5);
        if (empty($produtosFavoritos)) {
            localWriteToLog("... Usuário #{$usuario->id} não tem produtos favoritos. A saltar."); // <-- CORRIGIDO
            continue;
        }

        foreach ($produtosFavoritos as $produto) {
            $nomeProduto = $produto['produto_nome'];
            $nomeNormalizado = $produto['produto_nome_normalizado'];

            $ultimoPrecoPago = HistoricoPreco::getUserLastPaidPrice($pdo, $usuario->id, $nomeNormalizado);
            if ($ultimoPrecoPago === null) continue;

            $precosAtuais = HistoricoPreco::findBestPricesInCity($pdo, $nomeNormalizado, $cidadeUsuario, DIAS_BUSCA_PRECO_ATUAL);
            if (empty($precosAtuais)) continue; 

            $precoMinimoAtual = (float)$precosAtuais[0]['preco_minimo'];
            $mercadoMaisBaratoNome = $precosAtuais[0]['estabelecimento_nome'];
            $estabelecimentoId = $precosAtuais[0]['estabelecimento_id'];
            
            if ($precosAtuais[0]['ultimo_local_id'] == $ultimoLocal['estabelecimento_id'] && abs($precoMinimoAtual - $ultimoPrecoPago) < 0.01) {
                continue;
            }

            $limiteParaAlerta = $ultimoPrecoPago * (1.0 - (PERCENTUAL_QUEDA_ALERTA / 100.0));
            
            if ($precoMinimoAtual < $limiteParaAlerta - 0.001) { 
                
                $checkStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM alertas_enviados 
                     WHERE usuario_id = ? 
                       AND produto_nome_normalizado = ? 
                       AND estabelecimento_id = ?
                       AND data_envio >= (NOW() - INTERVAL 7 DAY)"
                );
                $checkStmt->execute([$usuario->id, $nomeNormalizado, $estabelecimentoId]);
                if ($checkStmt->fetchColumn() > 0) {
                    localWriteToLog("... Alerta para '{$nomeProduto}' já enviado recentemente. A saltar."); // <-- CORRIGIDO
                    continue; // Já enviado
                }
                
                localWriteToLog("!!! ALERTA !!! Utilizador #{$usuario->id}: Preço do '{$nomeProduto}' caiu!"); // <-- CORRIGIDO
                $precoPagoFmt = number_format($ultimoPrecoPago, 2, ',', '.');
                $precoMinimoFmt = number_format($precoMinimoAtual, 2, ',', '.');
                $mensagem = "Ei, {$nomeUsuario}! 👋\n\nBoas notícias! 💰\n\nNotei que o produto *{$nomeProduto}* (que pagaste R$ {$precoPagoFmt} da última vez) está agora por **R$ {$precoMinimoFmt}** no *{$mercadoMaisBaratoNome}*.\n\nÉ uma boa altura para comprar! 📉";
                
                try {
                    $waService->sendMessage($usuario->whatsapp_id, $mensagem); 

                    $sqlInsert = "
                        INSERT INTO alertas_enviados 
                            (usuario_id, produto_nome_normalizado, estabelecimento_id, preco, data_envio)
                        VALUES (?, ?, ?, ?, NOW())
                    ";
                    $insertStmt = $pdo->prepare($sqlInsert);
                    $insertStmt->execute([ $usuario->id, $nomeNormalizado, $estabelecimentoId, $precoMinimoAtual ]);
                
                } catch (Exception $e) {
                     localWriteToLog( // <-- CORRIGIDO
                        "!!! FALHA AO ENVIAR ALERTA para utilizador #{$usuario->id}: " . $e->getMessage()
                    );
                }
            }
        }
    }

} catch (Exception $e) {
    localWriteToLog("!!! ERRO CRÍTICO NO CRON ALERTA !!!: " . $e->getMessage()); // <-- CORRIGIDO
}

localWriteToLog("--- CRON ALERTA FINALIZADO ---"); // <-- CORRIGIDO
?>