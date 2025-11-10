<?php
// ---
// /app/Models/Compra.php
// AGORA COM LÓGICA PARA "INÍCIO RÁPIDO" E PESQUISA DE PREÇO
// ---

require_once __DIR__ . '/../Utils/StringUtils.php'; 

class Compra {
    
    public int $id;
    public int $usuario_id;
    public int $estabelecimento_id;
    public string $data_inicio;
    public ?string $data_fim;
    public ?string $total_gasto;
    public string $status;
    public ?string $ultimo_item_em; 

    private function __construct(array $data) {
        $this->id = (int)$data['id'];
        $this->usuario_id = (int)$data['usuario_id'];
        $this->estabelecimento_id = (int)$data['estabelecimento_id'];
        $this->data_inicio = $data['data_inicio'];
        $this->data_fim = $data['data_fim'];
        $this->total_gasto = $data['total_gasto'];
        $this->status = $data['status'];
        $this->ultimo_item_em = $data['ultimo_item_em']; 
    }

    /**
     * Encontra uma compra pelo seu ID.
     */
    public static function findById(PDO $pdo, int $id): ?Compra 
    {
        $stmt = $pdo->prepare("SELECT * FROM compras WHERE id = ?");
        $stmt->execute([$id]);
        $compraData = $stmt->fetch();
        return $compraData ? new Compra($compraData) : null;
    }

    /**
     * Encontra a compra ativa de um usuário.
     */
    public static function findActiveByUser(PDO $pdo, int $usuario_id): ?Compra 
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM compras 
             WHERE usuario_id = ? AND status = 'ativa' 
             LIMIT 1"
        );
        $stmt->execute([$usuario_id]);
        $compraData = $stmt->fetch();
        return $compraData ? new Compra($compraData) : null;
    }

    /**
     * Encontra a última compra FINALIZADA de um usuário.
     * (AGORA RETORNA A CIDADE E ESTADO)
     *
     * @param PDO $pdo
     * @param int $usuario_id
     * @return array|null Retorna dados do estabecimento, cidade e estado
     */
    public static function findLastCompletedByUser(PDO $pdo, int $usuario_id): ?array
    {
        $sql = "
            SELECT 
                c.estabelecimento_id,
                e.nome AS estabelecimento_nome,
                e.cidade,
                e.estado
            FROM compras c
            JOIN estabelecimentos e ON c.estabelecimento_id = e.id
            WHERE c.usuario_id = ?
              AND c.status = 'finalizada'
            ORDER BY c.data_fim DESC
            LIMIT 1
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }


    /**
     * Cria uma nova compra 'ativa'.
     */
    public static function create(PDO $pdo, int $usuario_id, int $estabelecimento_id): Compra
    {
        $stmt = $pdo->prepare(
            "INSERT INTO compras (usuario_id, estabelecimento_id, status, ultimo_item_em) 
             VALUES (?, ?, 'ativa', CURRENT_TIMESTAMP)"
        );
        $stmt->execute([$usuario_id, $estabelecimento_id]);
        
        $newId = (int)$pdo->lastInsertId();
        return self::findById($pdo, $newId);
    }


    /**
     * ADICIONA um novo item.
     */
    public function addItem(PDO $pdo, string $nome, string $qtdDesc, int $quantidade, float $precoPago, ?float $precoNormal = null): int
    {
        $nomeNormalizado = StringUtils::normalize($nome);
        $emPromocao = ($precoNormal !== null && $precoNormal > $precoPago);

        $stmt = $pdo->prepare(
            "INSERT INTO itens_compra (compra_id, produto_nome, produto_nome_normalizado, 
             quantidade_desc, quantidade, preco, preco_normal, em_promocao)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        $stmt->execute([
            $this->id, $nome, $nomeNormalizado, $qtdDesc, $quantidade, 
            $precoPago, $precoNormal, (int)$emPromocao 
        ]);
        $newItemId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "UPDATE compras SET ultimo_item_em = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $stmt->execute([$this->id]);
        
        return $newItemId;
    }

    /**
     * Finaliza esta compra.
     */
    public function finalize(PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT produto_nome, produto_nome_normalizado, quantidade_desc, 
             quantidade, preco, preco_normal, em_promocao 
             FROM itens_compra 
             WHERE compra_id = ?"
        );
        $stmt->execute([$this->id]);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalGasto = 0.0;
        foreach ($itens as $item) {
            $totalGasto += (float)$item['preco'] * (int)$item['quantidade'];
        }

        $stmt = $pdo->prepare(
            "UPDATE compras 
             SET status = 'finalizada', 
                 data_fim = CURRENT_TIMESTAMP, 
                 total_gasto = ?
             WHERE id = ?"
        );
        $stmt->execute([$totalGasto, $this->id]);

        return [
            'total' => $totalGasto,
            'itens' => $itens
        ];
    }
}