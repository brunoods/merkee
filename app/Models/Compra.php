<?php
// ---
// /app/Models/Compra.php
// ---

// 1. (A CORREÇÃO) Adiciona o Namespace
namespace App\Models;

// 2. (A CORREÇÃO) Adiciona as dependências
use PDO;
use App\Utils\StringUtils;
use DateTime;

// 3. (A CORREÇÃO) Garante que o nome da classe está correto
class Compra {
    
    public int $id;
    public int $usuario_id;
    public int $estabelecimento_id;
    public string $status; // 'ativa', 'finalizada', 'cancelada'
    public string $data_inicio;
    public ?string $data_fim;
    public ?string $ultimo_item_em; // (Para o CRON de inatividade)

    private function __construct(array $data) {
        $this->id = (int)$data['id'];
        $this->usuario_id = (int)$data['usuario_id'];
        $this->estabelecimento_id = (int)$data['estabelecimento_id'];
        $this->status = $data['status'];
        $this->data_inicio = $data['data_inicio'];
        $this->data_fim = $data['data_fim'];
        $this->ultimo_item_em = $data['ultimo_item_em'];
    }

    /**
     * Helper para criar um objeto Compra a partir de dados do PDO.
     */
    private static function fromData(array $data): Compra
    {
        return new Compra($data);
    }
    
    /**
     * Encontra uma compra pelo seu ID.
     */
    public static function findById(PDO $pdo, int $id): ?Compra 
    {
        $stmt = $pdo->prepare("SELECT * FROM compras WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        return $data ? self::fromData($data) : null;
    }

    /**
     * Encontra a compra ativa de um usuário.
     */
    public static function findActiveByUser(PDO $pdo, int $usuario_id): ?Compra 
    {
        $stmt = $pdo->prepare("SELECT * FROM compras WHERE usuario_id = ? AND status = 'ativa'");
        $stmt->execute([$usuario_id]);
        $data = $stmt->fetch();
        return $data ? self::fromData($data) : null;
    }

    /**
     * Encontra a última compra FINALIZADA de um usuário (para histórico de local).
     */
    public static function findLastCompletedByUser(PDO $pdo, int $usuario_id): ?array
    {
        $sql = "
            SELECT c.id, c.estabelecimento_id, e.nome as estabelecimento_nome, e.cidade, e.estado 
            FROM compras c
            JOIN estabelecimentos e ON c.estabelecimento_id = e.id
            WHERE c.usuario_id = ? AND c.status = 'finalizada'
            ORDER BY c.data_fim DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC); // Retorna um array
    }


    /**
     * Cria uma nova compra 'ativa'.
     */
    public static function create(PDO $pdo, int $usuario_id, int $estabelecimento_id): Compra
    {
        $dataInicio = (new DateTime())->format('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare(
            "INSERT INTO compras (usuario_id, estabelecimento_id, status, data_inicio, ultimo_item_em) 
             VALUES (?, ?, 'ativa', ?, ?)"
        );
        $stmt->execute([$usuario_id, $estabelecimento_id, $dataInicio, $dataInicio]);
        $newId = (int)$pdo->lastInsertId();
        
        // Retorna o objeto Compra recém-criado
        return new Compra([
            'id' => $newId,
            'usuario_id' => $usuario_id,
            'estabelecimento_id' => $estabelecimento_id,
            'status' => 'ativa',
            'data_inicio' => $dataInicio,
            'data_fim' => null,
            'ultimo_item_em' => $dataInicio
        ]);
    }


    /**
     * ADICIONA um novo item a esta compra.
     * Também atualiza o 'ultimo_item_em' da compra.
     */
    public function addItem(PDO $pdo, string $nome, string $qtdDesc, int $quantidade, float $precoPago, ?float $precoNormal = null): int
    {
        // (Usa a classe StringUtils importada)
        $nomeNormalizado = StringUtils::normalize($nome);
        $emPromocao = ($precoNormal !== null && $precoNormal > $precoPago);
        $precoUnitario = $precoPago / $quantidade;

        $pdo->beginTransaction();
        try {
            // 1. Insere o item
            $stmt = $pdo->prepare(
                "INSERT INTO itens_compra 
                    (compra_id, produto_nome, produto_nome_normalizado, quantidade_desc, quantidade, preco, preco_normal, em_promocao) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $this->id, $nome, $nomeNormalizado, $qtdDesc, $quantidade, $precoPago, $precoNormal, $emPromocao
            ]);
            $itemId = (int)$pdo->lastInsertId();
            
            // 2. Atualiza o "timestamp" da compra (para o CRON de inatividade)
            $stmt = $pdo->prepare("UPDATE compras SET ultimo_item_em = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$this->id]);
            
            // 3. Atualiza o histórico de preços (tabela grande)
            $stmt = $pdo->prepare(
                "INSERT INTO historico_precos 
                    (usuario_id, estabelecimento_id, compra_id, produto_nome, produto_nome_normalizado, preco_unitario, data_compra)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $this->usuario_id, $this->estabelecimento_id, $this->id, $nome, $nomeNormalizado, $precoUnitario, $this->data_inicio
            ]);
            
            $pdo->commit();
            return $itemId;
            
        } catch (\Exception $e) {
            $pdo->rollBack();
            // Lança a exceção para o BotController/Webhook apanhar
            throw $e; 
        }
    }

    /**
     * Finaliza esta compra.
     * Retorna os totais e os itens para o relatório.
     */
    public function finalize(PDO $pdo): array
    {
        $dataFim = (new DateTime())->format('Y-m-d H:i:s');
        
        // 1. Atualiza o status da compra
        $stmt = $pdo->prepare("UPDATE compras SET status = 'finalizada', data_fim = ? WHERE id = ?");
        $stmt->execute([$dataFim, $this->id]);
        $this->status = 'finalizada';
        $this->data_fim = $dataFim;
        
        // 2. Busca todos os itens e o total
        $stmt = $pdo->prepare("
            SELECT *, (preco * quantidade) as preco_total
            FROM itens_compra 
            WHERE compra_id = ?
        ");
        $stmt->execute([$this->id]);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalGasto = 0;
        foreach ($itens as $item) {
            $totalGasto += (float)$item['preco_total'];
        }

        return [
            'total' => $totalGasto,
            'itens' => $itens
        ];
    }
    /**
     * Encontra TODAS as compras finalizadas de um usuário (para o Dashboard).
     */
    public static function findAllCompletedByUser(PDO $pdo, int $usuario_id): array
    {
        $sql = "
            SELECT c.id, c.data_fim, c.total_gasto, c.total_poupado, e.nome as estabelecimento_nome
            FROM compras c
            JOIN estabelecimentos e ON c.estabelecimento_id = e.id
            WHERE c.usuario_id = ? 
              AND c.status = 'finalizada'
            ORDER BY c.data_fim DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>