<?php
// ---
// /app/Models/HistoricoPreco.php
// ---

// 1. (A CORREÇÃO)
namespace App\Models;

// 2. (A CORREÇÃO)
use PDO;
use DateTime; // (Esta classe usa DateTime)

// 3. (A CORREÇÃO)
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
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Busca o histórico de preços para o gráfico (não implementado no bot, mas útil para o futuro)
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
     * nos últimos X dias.
     */
    public static function findBestPricesInCity(PDO $pdo, string $produtoNomeNormalizado, string $cidade, int $dias = 30): array
    {
        // (Usa a classe DateTime importada)
        $dataLimite = (new DateTime("-{$dias} days"))->format('Y-m-d');
        
        $sql = "
            SELECT 
                hp.estabelecimento_id,
                e.nome as estabelecimento_nome,
                MIN(hp.preco_unitario) as preco_minimo,
                MAX(hp.data_compra) as data_mais_recente
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
        
        // Cria os placeholders (?) para a cláusula IN
        $placeholders = implode(',', array_fill(0, count($produtosNormalizados), '?'));
        
        // (Query complexa que busca o menor preço para cada produto)
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
     * (Usado pelo CRON 'verificar_alertas_preco')
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
}
?>