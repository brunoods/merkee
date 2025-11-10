<?php
// ---
// /app/Models/Compra.php
// (VERSÃO ATUALIZADA - CORREÇÃO LÓGICA DE PREÇO)
// ---

namespace App\Models;

use PDO;
use App\Utils\StringUtils;
use DateTime;

class Compra {
    
    // (Propriedades idênticas...)
    public int $id;
    public int $usuario_id;
    public int $estabelecimento_id;
    public string $status; 
    public string $data_inicio;
    public ?string $data_fim;
    public ?string $ultimo_item_em;
    public ?float $total_gasto;
    public ?float $total_poupado;

    // (Construtor e fromData idênticos...)
    private function __construct(array $data) {
        $this->id = (int)$data['id'];
        $this->usuario_id = (int)$data['usuario_id'];
        $this->estabelecimento_id = (int)$data['estabelecimento_id'];
        $this->status = $data['status'];
        $this->data_inicio = $data['data_inicio'];
        $this->data_fim = $data['data_fim'];
        $this->ultimo_item_em = $data['ultimo_item_em'];
        $this->total_gasto = $data['total_gasto'] ?? null; 
        $this->total_poupado = $data['total_poupado'] ?? null; 
    }
    private static function fromData(array $data): Compra
    {
        return new Compra($data);
    }
    
    // (Funções find... idênticas...)
    public static function findById(PDO $pdo, int $id): ?Compra 
    {
        $stmt = $pdo->prepare("SELECT * FROM compras WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        return $data ? self::fromData($data) : null;
    }
    public static function findActiveByUser(PDO $pdo, int $usuario_id): ?Compra 
    {
        $stmt = $pdo->prepare("SELECT * FROM compras WHERE usuario_id = ? AND status = 'ativa'");
        $stmt->execute([$usuario_id]);
        $data = $stmt->fetch();
        return $data ? self::fromData($data) : null;
    }
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
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result === false ? null : $result;
    }

    // (Função create idêntica...)
    public static function create(PDO $pdo, int $usuario_id, int $estabelecimento_id): Compra
    {
        $dataInicio = (new DateTime())->format('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare(
            "INSERT INTO compras (usuario_id, estabelecimento_id, status, data_inicio, ultimo_item_em) 
             VALUES (?, ?, 'ativa', ?, ?)"
        );
        $stmt->execute([$usuario_id, $estabelecimento_id, $dataInicio, $dataInicio]);
        $newId = (int)$pdo->lastInsertId();
        
        return new Compra([
            'id' => $newId,
            'usuario_id' => $usuario_id,
            'estabelecimento_id' => $estabelecimento_id,
            'status' => 'ativa',
            'data_inicio' => $dataInicio,
            'data_fim' => null,
            'ultimo_item_em' => $dataInicio,
            'total_gasto' => 0.0,
            'total_poupado' => 0.0
        ]);
    }


    /**
     * ADICIONA um novo item a esta compra.
     */
    public function addItem(PDO $pdo, string $nome, string $qtdDesc, int $quantidade, float $precoPagoUnitario, ?float $precoNormalUnitario = null): int
    {
        $nomeNormalizado = StringUtils::normalize($nome); 
        $emPromocao = ($precoNormalUnitario !== null && $precoNormalUnitario > $precoPagoUnitario);
        
        // --- (A CORREÇÃO ESTÁ AQUI) ---
        // A variável $precoPago já é o preço unitário (vem do Parser)
        // Não precisamos mais dividir.
        // $precoUnitario = $precoPago / ($quantidade > 0 ? $quantidade : 1); 
        // --- (FIM DA CORREÇÃO) ---


        $pdo->beginTransaction();
        try {
            // 1. Insere o item
            $stmt = $pdo->prepare(
                "INSERT INTO itens_compra 
                    (compra_id, produto_nome, produto_nome_normalizado, quantidade_desc, quantidade, preco, preco_normal, em_promocao) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            // (Agora passamos o $precoPagoUnitario e $precoNormalUnitario diretamente)
            $stmt->execute([
                $this->id, $nome, $nomeNormalizado, $qtdDesc, $quantidade, $precoPagoUnitario, $precoNormalUnitario, $emPromocao
            ]);
            $itemId = (int)$pdo->lastInsertId();
            
            // 2. Atualiza o "timestamp" da compra
            $stmt = $pdo->prepare("UPDATE compras SET ultimo_item_em = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$this->id]);
            
            // 3. Atualiza o histórico de preços
            $stmt = $pdo->prepare(
                "INSERT INTO historico_precos 
                    (usuario_id, estabelecimento_id, compra_id, produto_nome, produto_nome_normalizado, preco_unitario, data_compra)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $this->usuario_id, $this->estabelecimento_id, $this->id, $nome, $nomeNormalizado, $precoPagoUnitario, $this->data_inicio
            ]);
            
            $pdo->commit();
            return $itemId;
            
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e; 
        }
    }

    // (O resto do ficheiro (finalize, findAllCompletedByUser, etc.) é idêntico)
    public function finalize(PDO $pdo): array
    {
        $dataFim = (new DateTime())->format('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare("UPDATE compras SET status = 'finalizada', data_fim = ? WHERE id = ?");
        $stmt->execute([$dataFim, $this->id]);
        $this->status = 'finalizada';
        $this->data_fim = $dataFim;
        
        $stmt = $pdo->prepare("
            SELECT * FROM itens_compra 
            WHERE compra_id = ?
        ");
        $stmt->execute([$this->id]);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['itens' => $itens];
    }
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
    public static function findByIdAndUser(PDO $pdo, int $id, int $usuario_id): ?array
    {
        $sql = "
            SELECT c.id, c.data_fim, c.total_gasto, c.total_poupado, e.nome as estabelecimento_nome
            FROM compras c
            JOIN estabelecimentos e ON c.estabelecimento_id = e.id
            WHERE c.id = ? 
              AND c.usuario_id = ? 
              AND c.status = 'finalizada'
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id, $usuario_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result === false ? null : $result;
    }
    public static function findItemsByCompraId(PDO $pdo, int $compra_id): array
    {
        $stmt = $pdo->prepare("
            SELECT produto_nome, quantidade_desc, quantidade, preco, preco_normal, em_promocao
            FROM itens_compra 
            WHERE compra_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$compra_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>