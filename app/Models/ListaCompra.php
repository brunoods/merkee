<?php
// ---
// /app/Models/ListaCompra.php
// ---

// 1. (A CORREÇÃO)
namespace App\Models;

// 2. (A CORREÇÃO)
use PDO;
use App\Utils\StringUtils; // (Esta classe usa StringUtils)

// 3. (A CORREÇÃO)
class ListaCompra {
    
    public int $id;
    public int $usuario_id;
    public string $nome;

    private function __construct(array $data) {
        $this->id = (int)$data['id'];
        $this->usuario_id = (int)$data['usuario_id'];
        $this->nome = $data['nome'];
    }
    
    /**
     * Helper para criar um objeto a partir de dados do PDO.
     */
    private static function fromData(array $data): ListaCompra
    {
        return new ListaCompra($data);
    }

    /**
     * Cria uma nova lista de compras.
     */
    public static function create(PDO $pdo, int $usuario_id, string $nome_lista): ListaCompra
    {
        $stmt = $pdo->prepare(
            "INSERT INTO listas_compra (usuario_id, nome) VALUES (?, ?)"
        );
        $stmt->execute([$usuario_id, $nome_lista]);
        $newId = (int)$pdo->lastInsertId();
        
        return new ListaCompra([
            'id' => $newId,
            'usuario_id' => $usuario_id,
            'nome' => $nome_lista
        ]);
    }

    /**
     * Adiciona um item (produto) a uma lista de compras.
     */
    public static function addItem(PDO $pdo, int $lista_id, string $produto_nome): bool
    {
        // (Usa a classe StringUtils importada)
        $nomeNormalizado = StringUtils::normalize($produto_nome);
        
        $stmt = $pdo->prepare(
            "INSERT INTO itens_lista (lista_id, produto_nome, produto_nome_normalizado)
             VALUES (?, ?, ?)"
        );
        return $stmt->execute([$lista_id, $produto_nome, $nomeNormalizado]);
    }

    /**
     * Encontra todas as listas de um usuário.
     */
    public static function findAllByUser(PDO $pdo, int $usuario_id): array
    {
        $stmt = $pdo->prepare("SELECT * FROM listas_compra WHERE usuario_id = ? ORDER BY nome ASC");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca todos os itens de uma lista específica.
     */
    public static function findItemsByListId(PDO $pdo, int $lista_id): array
    {
        $stmt = $pdo->prepare("SELECT * FROM itens_lista WHERE lista_id = ? ORDER BY produto_nome ASC");
        $stmt->execute([$lista_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Encontra uma lista pelo nome (para evitar duplicados).
     */
    public static function findByName(PDO $pdo, int $usuario_id, string $nome_lista): ?ListaCompra
    {
        $stmt = $pdo->prepare("SELECT * FROM listas_compra WHERE usuario_id = ? AND nome = ?");
        $stmt->execute([$usuario_id, $nome_lista]);
        $data = $stmt->fetch();
        return $data ? self::fromData($data) : null;
    }

    /**
     * Apaga uma lista de compras (e os seus itens, por CASCATA na DB).
     */
    public static function delete(PDO $pdo, int $lista_id, int $usuario_id): bool
    {
        $stmt = $pdo->prepare(
            "DELETE FROM listas_compra WHERE id = ? AND usuario_id = ?"
        );
        return $stmt->execute([$lista_id, $usuario_id]);
    }
}
?>