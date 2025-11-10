<?php
// ---
// /app/Services/CompraReportService.php
// (VERSÃO COM NAMESPACE)
// Responsável por GERAR O TEXTO do relatório de finalização de compra.
// Esta lógica é partilhada entre o BotController e o CronFinalizeHandler.
// ---

// 1. Define o Namespace
namespace App\Services;

// 2. Importa as dependências
use PDO;
use App\Models\Compra;
use App\Models\Estabelecimento;
use App\Models\HistoricoPreco;
use App\Utils\StringUtils;
use DateTime; // (Classe global do PHP)

/**
 * Classe de Serviço
 * Centraliza a lógica de geração de relatórios de compra.
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
        // Este método 'finalize' ATUALIZA o status e retorna os dados
        $resumo = $compra->finalize($pdo);
        $totalGasto = $resumo['total']; 
        $itens = $resumo['itens']; // Itens desta compra
        
        $estabelecimento = Estabelecimento::findById($pdo, $compra->estabelecimento_id);
        $nomeLocal = $estabelecimento ? "$estabelecimento->nome ($estabelecimento->cidade/$estabelecimento->estado)" : "Local desconhecido";
        
        // (Usa a classe DateTime global importada com 'use')
        $dataCompra = (new DateTime($compra->data_inicio))->format('d/m/Y');


        // 2. Monta o cabeçalho
        $resposta = "Compra finalizada! 🛍️\n\n";
        $resposta .= "Resumo da tua compra em *{$nomeLocal}* no dia {$dataCompra}:\n\n";
        $resposta .= "Total Gasto: *R$ " . number_format($totalGasto, 2, ',', '.') . "*\n";
        
        $totalPoupado = 0;
        $totalPromocoes = 0;

        // 3. Itera sobre os itens para calcular poupanças
        foreach ($itens as $item) {
            if ($item['em_promocao'] && $item['preco_normal'] > $item['preco']) {
                $totalPromocoes++;
                $totalPoupado += ((float)$item['preco_normal'] - (float)$item['preco']) * (int)$item['quantidade'];
            }
        }

        if ($totalPoupado > 0.01) {
            $resposta .= "Promoções: *{$totalPromocoes}* itens\n";
            $resposta .= "Total Poupado: *R$ " . number_format($totalPoupado, 2, ',', '.') . "* 🤑\n";
        }
        
        $resposta .= "\n--- *Detalhes e Comparações* ---\n";

        // 4. Itera sobre os itens para comparar preços
        foreach ($itens as $item) {
            $nomeProduto = $item['produto_nome'];
            // (Usa a classe StringUtils importada com 'use')
            $nomeNormalizado = StringUtils::normalize($nomeProduto);
            $precoPagoUnit = (float)$item['preco']; // Preço unitário pago
            $quantidade = (int)$item['quantidade'];
            $precoPagoFmt = number_format($precoPagoUnit, 2, ',', '.');
            
            $resposta .= "\n*{$nomeProduto}* ({$quantidade}un)";
            $resposta .= "\n  Pagaste: *R$ {$precoPagoFmt}* (unid.)";

            // Busca o último preço pago ANTES desta compra
            // (Usa a classe HistoricoPreco importada com 'use')
            $historico = HistoricoPreco::getUltimoRegistro(
                $pdo, 
                $compra->usuario_id, 
                $nomeNormalizado, 
                $compra->id // ID da compra atual para excluir
            );

            if ($historico) {
                $ultimoPrecoUnit = (float)$historico['preco_unitario'];
                $ultimoPrecoFmt = number_format($ultimoPrecoUnit, 2, ',', '.');
                $localUltimaCompra = $historico['estabelecimento_nome'] ?? 'outra loja';
                $dataUltimaCompra = (new DateTime($historico['data_compra']))->format('d/m');
                
                $diff = $precoPagoUnit - $ultimoPrecoUnit;

                if (abs($diff) < 0.01) { // (Considera igual se a diferença for < 1 cêntimo)
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