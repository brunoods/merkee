<?php
// ---
// /tasks/verificar_alertas_preco.php
// (VERSÃO COM BOOTSTRAP, NAMESPACE E FIX IS_ATIVO)
// ---

// 1. Includes
require_once __DIR__ . '/../config/bootstrap.php';

// 2. Usar os "Namespaces"
use App\Models\Usuario;
use App\Models\Compra;
use App\Models\HistoricoPreco;
use App\Services\WhatsAppService;

// 3. Logging
$logFilePath = __DIR__ . '/../storage/cron_alertas_log.txt'; 
function writeToLog($message) {
    global $logFilePath;
    writeToLog($logFilePath, $message, "CRON_ALERTAS"); // Chama a global
}

writeToLog("--- CRON ALERTA INICIADO ---");

define('PERCENTUAL_QUEDA_ALERTA', 15.0); 
define('DIAS_BUSCA_PRECO_ATUAL', 7);     

try {
    $pdo = getDbConnection();
    $waService = new WhatsAppService();

    $usuarios = Usuario::findAll($pdo); // (Agora contém 'is_ativo')
    if (empty($usuarios)) {
        writeToLog("Nenhum usuário encontrado.");
        exit;
    }
    writeToLog("A verificar alertas para " . count($usuarios) . " usuários...");

    foreach ($usuarios as $usuario) {
        
        // (NOVA VERIFICAÇÃO) Não envia para utilizadores inativos
        if ($usuario->is_ativo === false) {
            writeToLog("A saltar Usuário #{$usuario->id}: Inativo.");
            continue;
        }
        
        // (VERIFICAÇÃO ANTIGA)
        if ($usuario->receber_alertas === false) {
            writeToLog("A saltar Usuário #{$usuario->id}: Alertas desativados.");
            continue; // Salta para o próximo usuário
        }

        $ultimoLocal = Compra::findLastCompletedByUser($pdo, $usuario->id);
        if (!$ultimoLocal || empty($ultimoLocal['cidade'])) {
            writeToLog("A saltar Usuário #{$usuario->id}: Sem cidade definida.");
            continue; 
        }
        $cidadeUsuario = $ultimoLocal['cidade'];
        $nomeUsuario = $usuario->nome ? explode(' ', $usuario->nome)[0] : "Olá";
        writeToLog("A processar Usuário #{$usuario->id} ({$nomeUsuario}) na cidade: {$cidadeUsuario}...");

        $produtosFavoritos = HistoricoPreco::findFavoriteProductNames($pdo, $usuario->id, 5);
        if (empty($produtosFavoritos)) {
            writeToLog("... Usuário #{$usuario->id} não tem produtos favoritos. A saltar.");
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
            
            $estStmt = $pdo->prepare("SELECT id FROM estabelecimentos WHERE nome = ? AND cidade = ? LIMIT 1");
            $estStmt->execute([$mercadoMaisBaratoNome, $cidadeUsuario]);
            $estResult = $estStmt->fetch();
            if (!$estResult) continue;
            $estabelecimentoId = $estResult['id'];
            
            $limiteParaAlerta = $ultimoPrecoPago * (1.0 - (PERCENTUAL_QUEDA_ALERTA / 100.0));
            
            if ($precoMinimoAtual < $limiteParaAlerta - 0.001) { 
                
                $sqlCheck = "
                    SELECT id FROM alertas_enviados
                    WHERE usuario_id = ?
                      AND produto_nome_normalizado = ?
                      AND estabelecimento_id = ?
                      AND preco = ?
                ";
                $checkStmt = $pdo->prepare($sqlCheck);
                $checkStmt->execute([ $usuario->id, $nomeNormalizado, $estabelecimentoId, $precoMinimoAtual ]);

                if ($checkStmt->fetch()) {
                    writeToLog("... Alerta para '{$nomeProduto}' a R$ {$precoMinimoAtual} já enviado ao Usuário #{$usuario->id}. A saltar.");
                    continue; 
                }
                
                writeToLog("!!! ALERTA !!! Utilizador #{$usuario->id}: Preço do '{$nomeProduto}' caiu!");
                $precoPagoFmt = number_format($ultimoPrecoPago, 2, ',', '.');
                $precoMinimoFmt = number_format($precoMinimoAtual, 2, ',', '.');
                $mensagem = "Ei, {$nomeUsuario}! 👋\n\nBoas notícias! 💰\n\nNotei que o produto *{$nomeProduto}* (que pagaste R$ {$precoPagoFmt} da última vez) está agora por **R$ {$precoMinimoFmt}** no *{$mercadoMaisBaratoNome}*.\n\nÉ uma boa altura para comprar! 📉";
                
                try {
                    $waService->sendMessage($usuario->whatsapp_id, $mensagem); // (try/catch)

                    $sqlInsert = "
                        INSERT INTO alertas_enviados 
                            (usuario_id, produto_nome_normalizado, estabelecimento_id, preco)
                        VALUES (?, ?, ?, ?)
                    ";
                    $insertStmt = $pdo->prepare($sqlInsert);
                    $insertStmt->execute([ $usuario->id, $nomeNormalizado, $estabelecimentoId, $precoMinimoAtual ]);
                
                } catch (Exception $e) {
                     writeToLog(
                        "!!! FALHA AO ENVIAR ALERTA para utilizador #{$usuario->id}: " . $e->getMessage()
                    );
                }
            }
        }
    }

} catch (Exception $e) {
    writeToLog("!!! ERRO CRÍTICO NO CRON ALERTA !!!: " . $e->getMessage());
}

writeToLog("--- CRON ALERTA FINALIZADO ---");
?>