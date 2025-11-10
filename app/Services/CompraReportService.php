<?php
// ---
// /app/Services/CompraReportService.php
// (NOVO FICHEIRO)
// ---

// Modelos e Serviços necessários para gerar o relatório
require_once __DIR__ . '/../Models/Compra.php';
require_once __DIR__ . '/../Models/Estabelecimento.php';
require_once __DIR__ . '/../Models/HistoricoPreco.php';
require_once __DIR__ . '/../Utils/StringUtils.php';

/**
 * Responsável por gerar o TEXTO do relatório de finalização de compra.
 * Esta lógica é partilhada entre o BotController e o CronFinalizeHandler.
 */
class CompraReportService {

    /**
     * Finaliza uma compra e gera o texto do relatório detalhado.
     *
     * @param PDO $pdo A conexão com a base de dados
     * @param Compra $compra O objeto da compra a finalizar
     * @return string O relatório completo para enviar ao usuário
     */
    public static function gerarResumoFinalizacao(PDO $pdo, Compra $compra): string
    {
        // 1. Finaliza a compra (obtém os totais e itens)
        // (Esta é a lógica que estava no início de 'finalizarCompra')
        $resumo = $compra->finalize($pdo);
        $totalGasto = $resumo['total']; 
        $itens = $resumo['itens']; 
        $estabelecimento = Estabelecimento::findById($pdo, $compra->estabelecimento_id);
        $nomeLocal = $estabelecimento ? "$estabelecimento->nome ($estabelecimento->cidade/$estabelecimento->estado)" : "Local desconhecido";

        // 2. Monta o cabeçalho
        $resposta = "Compra finalizada! 🛍️\n\n";
        $resposta .= "📍 *Local:* $nomeLocal\n";
        $resposta .= "💰 *Total gasto:* R$ " . number_format($totalGasto, 2, ',', '.');
        $resposta .= "\n--- *Itens Registrados* ---\n";

        if (empty($itens)) {
             $resposta .= "(Nenhum item registrado)";
             return $resposta;
        }

        // 3. Agrupa os itens
        $itensAgrupados = [];
        foreach ($itens as $item) {
            $key = $item['produto_nome_normalizado'] . '_' . $item['preco']; 
            if (!isset($itensAgrupados[$key])) {
                $itensAgrupados[$key] = [
                    'nome' => $item['produto_nome'], 
                    'nome_normalizado' => $item['produto_nome_normalizado'], 
                    'qtd_desc' => $item['quantidade_desc'], 
                    'preco_unitario' => (float)$item['preco'], 
                    'preco_normal_unitario' => $item['preco_normal'] ? (float)$item['preco_normal'] : 0.0,
                    'em_promocao' => (bool)$item['em_promocao'],
                    'contagem_total' => 0 
                ];
            }
            $itensAgrupados[$key]['contagem_total'] += (int)$item['quantidade'];
        }

        // 4. Monta a linha de cada item do resumo (COM LÓGICA DE TENDÊNCIA)
        foreach ($itensAgrupados as $item) {
            $nomeProdutoOriginal = $item['nome']; 
            $nomeProdutoNormalizado = StringUtils::normalize($item['nome_normalizado']); 
            $precoUnitario = $item['preco_unitario'];
            $precoNormalUnitario = $item['preco_normal_unitario'];
            $emPromocao = $item['em_promocao'];
            $contagemTotal = $item['contagem_total'];
            $precoUnitarioFmt = number_format($precoUnitario, 2, ',', '.');
            $contagemStr = $contagemTotal > 1 ? " (x{$contagemTotal})" : "";
            $nomeLimpo = trim(preg_replace('/^(\d+ ?[xX*uUuNn]?) ?/','', $nomeProdutoOriginal));
            
            $linhaItem = "• {$nomeLimpo} ({$item['qtd_desc']}) - R$ {$precoUnitarioFmt}{$contagemStr}"; 
            
            if ($emPromocao && $precoNormalUnitario > $precoUnitario) {
                $precoNormalFmt = number_format($precoNormalUnitario, 2, ',', '.');
                $linhaItem .= " (💰 *Promo!* De R$ {$precoNormalFmt})";
            }

            // Compara com o último preço pago
            $ultimoRegistro = HistoricoPreco::getUltimoRegistro(
                $pdo, $compra->usuario_id, $nomeProdutoNormalizado, $compra->id
            );
            if ($ultimoRegistro !== null) {
                $ultimoPrecoUnitario = (float)$ultimoRegistro['preco']; 
                $diferenca = $precoUnitario - $ultimoPrecoUnitario; 
                if (abs($diferenca) > 0.001) { 
                    $localCompraAntiga = $ultimoRegistro['estabelecimento_id'] == $compra->estabelecimento_id 
                        ? "aqui mesmo" 
                        : "no *{$ultimoRegistro['estabelecimento_nome']}*";
                    if ($diferenca > 0) {
                        $linhaItem .= " (🔺 *R$ " . number_format($diferenca, 2, ',', '.') . " mais caro* que {$localCompraAntiga})";
                    } elseif ($diferenca < 0) {
                        $linhaItem .= " (✨ *R$ " . number_format(abs($diferenca), 2, ',', '.') . " mais barato* que {$localCompraAntiga})";
                    }
                }
            }

            // Mostra a tendência de preços
            $trendPrecos = HistoricoPreco::getPriceTrend(
                $pdo, $compra->usuario_id, $nomeProdutoNormalizado, $compra->id
            );
            $trendPrecos[] = $precoUnitario; 
            if (count($trendPrecos) > 1) { 
                $linhaTrend = "  (Últimos preços: ";
                $trendFormatada = [];
                foreach ($trendPrecos as $p) {
                    $trendFormatada[] = "R$ " . number_format((float)$p, 2, ',', '.');
                }
                $ultimoIndex = count($trendFormatada) - 1;
                $trendFormatada[$ultimoIndex] = "*" . $trendFormatada[$ultimoIndex] . "*";
                $linhaTrend .= implode(' → ', $trendFormatada) . ")";
                
                if (count($trendPrecos) >= 3) {
                    $primeiroPreco = (float)$trendPrecos[0];
                    $ultimoPreco = (float)$trendPrecos[$ultimoIndex];
                    if ($ultimoPreco > $primeiroPreco + 0.001) { 
                        $linhaTrend .= " 📈";
                    } elseif ($ultimoPreco < $primeiroPreco - 0.001) { 
                        $linhaTrend .= " 📉";
                    }
                }
                $linhaItem .= "\n" . $linhaTrend;
            }
            $resposta .= $linhaItem . "\n";
        }
        
        return $resposta;
    }
}
?>