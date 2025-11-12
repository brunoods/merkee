<?php
// ---
// /app/Models/HistoricoPreco.php
// (VERSÃO COMPLETA - COM ALERTAS #13 E SUGESTÕES #14)
// ---

namespace App\Models;

use PDO;
use DateTime; 

class HistoricoPreco {

    /**
     * Busca o último preço pago pelo usuário por um produto,
     * EXCLUINDO a compra atual.
     */
    public static function getUltimoRegistro(PDO $pdo, int $usuario_id, string $produtoNomeNormalizado, int $compraAtualId): ?array
    {
        $sql = "
            SELECT hp.preco_unitario, hp.data_compra, e.nome as estabelecimento_nome
            FROM historico_precos hp
            JOIN estabelecimentos e ON hp.estabelecimento_id = e.id
            WHERE hp.usuario_id = ? 
              AND hp.produto_nome_normalizado = ?
              AND hp.compra_id != ? 
            ORDER BY hp.data_compra DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id, $produtoNomeNormalizado, $compraAtualId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        // Se fetch() retornar false (nada encontrado), nós retornamos null
        return $result === false ? null : $result;
    }
    
    /**
     * Busca o histórico de preços para o gráfico
     */
    public static function getPriceTrend(PDO $pdo, int $usuario_id, string $produtoNomeNormalizado, int $compraAtualId): array
    {
        $sql = "
            SELECT hp.preco_unitario, hp.data_compra
            FROM historico_precos hp
            WHERE hp.usuario_id = ? 
              AND hp.produto_nome_normalizado = ?
              AND hp.compra_id != ? 
            ORDER BY hp.data_compra ASC
            LIMIT 10
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id, $produtoNomeNormalizado, $compraAtualId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * (Usado pelo comando 'pesquisar')
     * Encontra o preço mais baixo registado para um produto numa cidade
     */
    public static function findBestPricesInCity(PDO $pdo, string $produtoNomeNormalizado, string $cidade, int $dias = 30): array
    {
        $dataLimite = (new DateTime("-{$dias} days"))->format('Y-m-d');
        
        $sql = "
            SELECT 
                hp.estabelecimento_id,
                e.nome as estabelecimento_nome,
                MIN(hp.preco_unitario) as preco_minimo,
                MAX(hp.data_compra) as data_mais_recente,
                (SELECT c.id FROM compras c WHERE c.estabelecimento_id = hp.estabelecimento_id ORDER BY c.data_fim DESC LIMIT 1) as ultimo_local_id
            FROM historico_precos hp
            JOIN estabelecimentos e ON hp.estabelecimento_id = e.id
            WHERE hp.produto_nome_normalizado = ?
              AND e.cidade = ?
              AND hp.data_compra >= ?
            GROUP BY hp.estabelecimento_id, e.nome
            ORDER BY preco_minimo ASC
            LIMIT 3
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$produtoNomeNormalizado, $cidade, $dataLimite]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * (Usado pelo 'PurchaseStartHandler' - Lista Inteligente)
     * Encontra os preços para MÚLTIPLOS produtos de uma vez (otimizado).
     */
    public static function findPricesForListInCity(PDO $pdo, string $cidade, array $produtosNormalizados, int $dias = 30): array
    {
        if (empty($produtosNormalizados)) {
            return [];
        }

        $dataLimite = (new DateTime("-{$dias} days"))->format('Y-m-d');
        
        $placeholders = implode(',', array_fill(0, count($produtosNormalizados), '?'));
        
        $sql = "
            WITH RankedPrices AS (
                SELECT 
                    hp.produto_nome_normalizado,
                    hp.preco_unitario,
                    hp.data_compra,
                    e.nome as estabelecimento_nome,
                    ROW_NUMBER() OVER(
                        PARTITION BY hp.produto_nome_normalizado 
                        ORDER BY hp.preco_unitario ASC, hp.data_compra DESC
                    ) as rn
                FROM historico_precos hp
                JOIN estabelecimentos e ON hp.estabelecimento_id = e.id
                WHERE e.cidade = ?
                  AND hp.data_compra >= ?
                  AND hp.produto_nome_normalizado IN ($placeholders)
            )
            SELECT 
                produto_nome_normalizado,
                preco_unitario as preco_minimo,
                data_compra as data_mais_recente,
                estabelecimento_nome
            FROM RankedPrices
            WHERE rn = 1
        ";
        
        $params = array_merge([$cidade, $dataLimite], $produtosNormalizados);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * (Usado pelo CRON 'verificar_alertas_preco')
     * Encontra os N produtos mais comprados por um usuário.
     */
    public static function findFavoriteProductNames(PDO $pdo, int $usuario_id, int $limit = 5): array
    {
        $sql = "
            SELECT 
                produto_nome,
                produto_nome_normalizado, 
                COUNT(*) as contagem
            FROM historico_precos
            WHERE usuario_id = ?
            GROUP BY produto_nome, produto_nome_normalizado
            ORDER BY contagem DESC
            LIMIT ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * (Usado pelo CRON 'verificar_alertas_ preco')
     * Busca o último preço pago por UM usuário por UM produto.
     */
    public static function getUserLastPaidPrice(PDO $pdo, int $usuario_id, string $produtoNomeNormalizado): ?float
    {
        $sql = "
            SELECT preco_unitario
            FROM historico_precos
            WHERE usuario_id = ? 
              AND produto_nome_normalizado = ?
            ORDER BY data_compra DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id, $produtoNomeNormalizado]);
        $result = $stmt->fetch();
        return $result ? (float)$result['preco_unitario'] : null;
    }
    
    // --- (NOVO MÉTODO FEATURE #13) ---

    /**
     * FEATURE #13: Busca produtos onde o último preço pago pelo usuário
     * é significativamente maior que o melhor preço da cidade (simulando alerta).
     */
    public static function findHighPriceAlerts(PDO $pdo, int $usuario_id, int $thresholdPercent = 10, int $limit = 3): array
    {
        $sql = "
            WITH LastPrice AS (
                SELECT
                    hp.produto_nome,
                    hp.produto_nome_normalizado,
                    hp.preco_unitario AS user_last_price,
                    hp.data_compra,
                    ROW_NUMBER() OVER(
                        PARTITION BY hp.produto_nome_normalizado 
                        ORDER BY hp.data_compra DESC
                    ) as rn
                FROM historico_precos hp
                WHERE hp.usuario_id = :user_id
            ),
            BestCityPrice AS (
                SELECT
                    hp.produto_nome_normalizado,
                    MIN(hp.preco_unitario) AS best_price_city
                FROM historico_precos hp
                JOIN estabelecimentos e ON hp.estabelecimento_id = e.id
                WHERE hp.data_compra >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY hp.produto_nome_normalizado
            )
            SELECT
                LP.produto_nome,
                LP.user_last_price,
                BCP.best_price_city,
                (LP.user_last_price - BCP.best_price_city) AS diferenca
            FROM LastPrice LP
            JOIN BestCityPrice BCP ON LP.produto_nome_normalizado = BCP.produto_nome_normalizado
            WHERE LP.rn = 1 -- Apenas o último registo do utilizador
              AND (LP.user_last_price > BCP.best_price_city)
              AND ((LP.user_last_price - BCP.best_price_city) / BCP.best_price_city) * 100 > :threshold
            ORDER BY diferenca DESC
            LIMIT :limit
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $usuario_id, PDO::PARAM_INT);
        $stmt->bindValue(':threshold', $thresholdPercent, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- (NOVO MÉTODO FEATURE #14) ---

    /**
     * FEATURE #14: Encontra sugestões de mercado para os produtos mais comprados.
     */
    public static function findMarketSuggestions(PDO $pdo, int $usuario_id, int $dias = 60): array
    {
        // 1. Encontra os 3 produtos mais comprados
        $produtos = self::findFavoriteProductNames($pdo, $usuario_id, 3);
        $sugestoes = [];
        
        foreach ($produtos as $produto) {
            $nomeNormalizado = $produto['produto_nome_normalizado'];
            $dataLimite = (new DateTime("-{$dias} days"))->format('Y-m-d');

            // 2. Para cada produto, encontra o mercado com o menor preço médio recente
            $sql = "
                SELECT 
                    e.nome AS estabelecimento_nome,
                    AVG(hp.preco_unitario) AS preco_medio_mercado
                FROM historico_precos hp
                JOIN estabelecimentos e ON hp.estabelecimento_id = e.id
                WHERE hp.usuario_id = :user_id 
                  AND hp.produto_nome_normalizado = :nome_norm
                  AND hp.data_compra >= :data_limite
                GROUP BY e.id, e.nome
                ORDER BY preco_medio_mercado ASC
                LIMIT 1
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $usuario_id, PDO::PARAM_INT);
            $stmt->bindValue(':nome_norm', $nomeNormalizado, PDO::PARAM_STR);
            $stmt->bindValue(':data_limite', $dataLimite, PDO::PARAM_STR);
            $stmt->execute();
            $melhorMercado = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($melhorMercado) {
                $sugestoes[] = [
                    'produto' => $produto['produto_nome'],
                    'mercado_nome' => $melhorMercado['estabelecimento_nome'],
                    'preco_medio' => $melhorMercado['preco_medio_mercado']
                ];
            }
        }
        
        return $sugestoes;
    }
}
?>