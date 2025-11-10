<?php
// ---
// /app/Services/CompraReportService.php
// (VERSÃO ATUALIZADA - AGORA GUARDA OS TOTAIS NA DB)
// ---

namespace App\Services;

use PDO;
use App\Models\Compra;
use App\Models\Estabelecimento;
use App\Models\HistoricoPreco;
use App\Utils\StringUtils;
use DateTime; 

class CompraReportService {

    /**
     * Finaliza uma compra, CALCULA E GUARDA os totais,
     * e gera o texto do relatório detalhado.
     */
    public static function gerarResumoFinalizacao(PDO $pdo, Compra $compra): string
    {
        // 1. Finaliza a compra (obtém os totais e itens)
        // (Este método 'finalize' apenas muda o status e retorna os itens)
        $resumo = $compra->finalize($pdo);
        $itens = $resumo['itens']; 
        
        $estabelecimento = Estabelecimento::findById($pdo, $compra->estabelecimento_id);
        $nomeLocal = $estabelecimento ? "$estabelecimento->nome ($estabelecimento->cidade/$estabelecimento->estado)" : "Local desconhecido";
        $dataCompra = (new DateTime($compra->data_inicio))->format('d/m/Y');

        // 2. Monta o cabeçalho
        $resposta = "Compra finalizada! 🛍️\n\n";
        $resposta .= "Resumo da tua compra em *{$nomeLocal}* no dia {$dataCompra}:\n\n";
        
        $totalGasto = 0;
        $totalPoupado = 0;
        $totalPromocoes = 0;

        // 3. Itera sobre os itens para calcular totais
        foreach ($itens as $item) {
            $precoItem = (float)$item['preco'];
            $quantidadeItem = (int)$item['quantidade'];
            $totalGasto += ($precoItem * $quantidadeItem); // (Calcula o total gasto)
            
            if ($item['em_promocao'] && $item['preco_normal'] > $precoItem) {
                $totalPromocoes++;
                $totalPoupado += ((float)$item['preco_normal'] - $precoItem) * $quantidadeItem;
            }
        }

        // --- (INÍCIO DA NOVA LÓGICA) ---
        // 4. Guarda os totais na tabela 'compras'
        try {
            $stmt = $pdo->prepare(
                "UPDATE compras SET total_gasto = ?, total_poupado = ? WHERE id = ?"
            );
            $stmt->execute([$totalGasto, $totalPoupado, $compra->id]);
        } catch (\Exception $e) {
            // (Não faz nada se falhar, para não quebrar o bot, mas podemos logar no futuro)
        }
        // --- (FIM DA NOVA LÓGICA) ---


        // 5. Continua a montar a resposta para o WhatsApp
        $resposta .= "Total Gasto: *R$ " . number_format($totalGasto, 2, ',', '.') . "*\n";

        if ($totalPoupado > 0.01) {
            $resposta .= "Promoções: *{$totalPromocoes}* itens\n";
            $resposta .= "Total Poupado: *R$ " . number_format($totalPoupado, 2, ',', '.') . "* 🤑\n";
        }
        
        $resposta .= "\n--- *Detalhes e Comparações* ---\n";

        // 6. Itera sobre os itens para comparar preços (lógica antiga)
        foreach ($itens as $item) {
            $nomeProduto = $item['produto_nome'];
            $nomeNormalizado = StringUtils::normalize($nomeProduto);
            $precoPagoUnit = (float)$item['preco'];
            $quantidade = (int)$item['quantidade'];
            $precoPagoFmt = number_format($precoPagoUnit, 2, ',', '.');
            
            $resposta .= "\n*{$nomeProduto}* ({$quantidade}un)";
            $resposta .= "\n  Pagaste: *R$ {$precoPagoFmt}* (unid.)";

            $historico = HistoricoPreco::getUltimoRegistro(
                $pdo, 
                $compra->usuario_id, 
                $nomeNormalizado, 
                $compra->id
            );

            if ($historico) {
                $ultimoPrecoUnit = (float)$historico['preco_unitario'];
                $ultimoPrecoFmt = number_format($ultimoPrecoUnit, 2, ',', '.');
                $localUltimaCompra = $historico['estabelecimento_nome'] ?? 'outra loja';
                $dataUltimaCompra = (new DateTime($historico['data_compra']))->format('d/m');
                
                $diff = $precoPagoUnit - $ultimoPrecoUnit;

                if (abs($diff) < 0.01) {
                    $resposta .= "\n  Histórico: (Manteve 😐) R$ {$ultimoPrecoFmt} em {$localUltimaCompra}";
                } elseif ($diff > 0) {
                    $resposta .= "\n  Histórico: (Subiu 📈) R$ {$ultimoPrecoFmt} em {$localUltimaCompra} ({$dataUltimaCompra})";
                } else {
                    $resposta .= "\n  Histórico: (Baixou 📉) R$ {$ultimoPrecoFmt} em {$localUltimaCompra} ({$dataUltimaCompra})";
                }
            } else {
                $resposta .= "\n  Histórico: (Primeiro registo! 🥇)";
            }
        }

        return $resposta;
    }
}
?>