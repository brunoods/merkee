<?php
// ---
// /app/Models/HistoricoPreco.php
// (VERSÃO ATUALIZADA COM OTIMIZAÇÃO DE LISTA)
// ---

class HistoricoPreco {

    public static function getUltimoRegistro(PDO $pdo, int $usuario_id, string $produtoNomeNormalizado, int $compraAtualId): ?array
    {
        $sql = "
            SELECT 
                i.preco,
                c.estabelecimento_id,
                e.nome AS estabelecimento_nome
            FROM itens_compra i
            JOIN compras c ON i.compra_id = c.id
            JOIN estabelecimentos e ON c.estabelecimento_id = e.id
            WHERE c.usuario_id = ?
              AND i.produto_nome_normalizado = ?
              AND c.id != ?
              AND c.status = 'finalizada'
            ORDER BY c.data_inicio DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id, $produtoNomeNormalizado, $compraAtualId]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC); 
        return $resultado !== false ? $resultado : null;
    }

    public static function getPriceTrend(PDO $pdo, int $usuario_id, string $produtoNomeNormalizado, int $compraAtualId): array
    {
        $sql = "
            SELECT i.preco
            FROM itens_compra i
            JOIN compras c ON i.compra_id = c.id
            WHERE c.usuario_id = ?
              AND i.produto_nome_normalizado = ?
              AND c.id != ?
              AND c.status = 'finalizada'
            ORDER BY c.data_inicio DESC
            LIMIT 4 
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id, $produtoNomeNormalizado, $compraAtualId]);
        $precos = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        return array_reverse($precos);
    }

    public static function findBestPricesInCity(PDO $pdo, string $produtoNomeNormalizado, string $cidade, int $dias = 30): array
    {
        $date = new DateTime();
        $date->modify("-{$dias} days");
        $dataCorte = $date->format('Y-m-d H:i:s'); 
        $sql = "
            SELECT 
                e.nome AS estabelecimento_nome,
                MIN(i.preco) AS preco_minimo, 
                AVG(i.preco) AS preco_medio,  
                COUNT(i.id) AS total_registos 
            FROM itens_compra i
            JOIN compras c ON i.compra_id = c.id
            JOIN estabelecimentos e ON c.estabelecimento_id = e.id
            WHERE 
                i.produto_nome_normalizado = ?
              AND e.cidade = ?
              AND c.status = 'finalizada'
              AND c.data_fim >= ?
            GROUP BY 
                e.id, e.nome 
            ORDER BY 
                preco_minimo ASC, preco_medio ASC 
            LIMIT 5
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $produtoNomeNormalizado, PDO::PARAM_STR);
        $stmt->bindValue(2, $cidade, PDO::PARAM_STR);
        $stmt->bindValue(3, $dataCorte, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * (NOVO!) Encontra os preços para MÚLTIPLOS produtos de uma vez (otimizado).
     *
     * @param PDO $pdo
     * @param string $cidade
     * @param array $produtosNormalizados (ex: ['arroz 5kg', 'cafe pilao 500g'])
     * @param int $dias
     * @return array (ex: [ ['produto_nome_normalizado' => 'arroz 5kg', 'estabelecimento_nome' => 'Mercado A', 'preco_minimo' => 20.50, 'est_id' => 1] ])
     */
    public static function findPricesForListInCity(PDO $pdo, string $cidade, array $produtosNormalizados, int $dias = 30): array
    {
        if (empty($produtosNormalizados)) {
            return [];
        }

        $date = new DateTime();
        $date->modify("-{$dias} days");
        $dataCorte = $date->format('Y-m-d H:i:s');

        // 1. Criar os placeholders (?) para a cláusula IN
        // Para ['arroz', 'feijao'], isto cria "?,?"
        $placeholders = implode(',', array_fill(0, count($produtosNormalizados), '?'));

        $sql = "
            SELECT
                i.produto_nome_normalizado,
                e.id AS est_id,
                e.nome AS estabelecimento_nome,
                MIN(i.preco) AS preco_minimo
            FROM itens_compra i
            JOIN compras c ON i.compra_id = c.id
            JOIN estabelecimentos e ON c.estabelecimento_id = e.id
            WHERE 
                e.cidade = ?
              AND c.status = 'finalizada'
              AND c.data_fim >= ?
              AND i.produto_nome_normalizado IN ({$placeholders})
            GROUP BY
                e.id, e.nome, i.produto_nome_normalizado
        ";
        
        // 2. Preparar os parâmetros para o execute()
        // Os parâmetros têm de estar na ordem correta:
        // 1º a cidade, 2º a data, e DEPOIS a lista de produtos
        $params = [$cidade, $dataCorte];
        foreach ($produtosNormalizados as $produto) {
            $params[] = $produto;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * (CORRIGIDO) Encontra os N produtos mais comprados por um usuário.
     */
    public static function findFavoriteProductNames(PDO $pdo, int $usuario_id, int $limit = 5): array
    {
        // --- (INÍCIO DA CORREÇÃO) ---
        // Alterado de "LIMIT :limite" para "LIMIT ?"
        $sql = "
            SELECT 
                i.produto_nome, 
                i.produto_nome_normalizado, 
                COUNT(i.id) as contagem
            FROM itens_compra i
            JOIN compras c ON i.compra_id = c.id
            WHERE c.usuario_id = ?
              AND c.status = 'finalizada'
            GROUP BY i.produto_nome_normalizado, i.produto_nome
            ORDER BY contagem DESC
            LIMIT ? 
        ";
        $stmt = $pdo->prepare($sql);
        
        // Alterado bindValue(":limite") para bindValue(2)
        $stmt->bindValue(1, $usuario_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT); 
        // --- (FIM DA CORREÇÃO) ---
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca o último preço pago por UM usuário por UM produto.
     */
    public static function getUserLastPaidPrice(PDO $pdo, int $usuario_id, string $produtoNomeNormalizado): ?float
    {
        $sql = "
            SELECT i.preco
            FROM itens_compra i
            JOIN compras c ON i.compra_id = c.id
            WHERE c.usuario_id = ?
              AND i.produto_nome_normalizado = ?
              AND c.status = 'finalizada'
            ORDER BY c.data_fim DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id, $produtoNomeNormalizado]);
        $resultado = $stmt->fetchColumn();
        
        return $resultado !== false ? (float)$resultado : null;
    }
}
?>