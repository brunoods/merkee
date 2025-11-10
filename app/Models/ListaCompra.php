<?php
// ---
// /app/Models/ListaCompra.php
// (NOVO FICHEIRO)
// ---

// Precisamos do normalizador para salvar os nomes dos produtos
require_once __DIR__ . '/../Utils/StringUtils.php';

/**
 * Representa uma única lista de compras (ex: "Compras do Mês")
 */
class ListaCompra {
    
    public int $id;
    public int $usuario_id;
    public string $nome_lista;
    public string $criada_em;

    private function __construct(array $data) {
        $this->id = (int)$data['id'];
        $this->usuario_id = (int)$data['usuario_id'];
        $this->nome_lista = $data['nome_lista'];
        $this->criada_em = $data['criada_em'];
    }

    /**
     * Cria uma nova lista de compras.
     */
    public static function create(PDO $pdo, int $usuario_id, string $nome_lista): ListaCompra
    {
        $stmt = $pdo->prepare(
            "INSERT INTO listas_compras (usuario_id, nome_lista) VALUES (?, ?)"
        );
        $stmt->execute([$usuario_id, $nome_lista]);
        $newId = (int)$pdo->lastInsertId();

        $data = [
            'id' => $newId,
            'usuario_id' => $usuario_id,
            'nome_lista' => $nome_lista,
            'criada_em' => date('Y-m-d H:i:s')
        ];
        return new ListaCompra($data);
    }

    /**
     * Adiciona um item (produto) a uma lista de compras.
     */
    public static function addItem(PDO $pdo, int $lista_id, string $produto_nome): bool
    {
        // Normaliza o nome do produto ANTES de salvar
        $nomeNormalizado = StringUtils::normalize($produto_nome);

        // Não adiciona se for vazio
        if (empty($nomeNormalizado)) {
            return false;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO listas_itens (lista_id, produto_nome, produto_nome_normalizado)
             VALUES (?, ?, ?)"
        );
        return $stmt->execute([$lista_id, $produto_nome, $nomeNormalizado]);
    }

    /**
     * Encontra todas as listas de um usuário.
     * @return array Lista de objetos ListaCompra
     */
    public static function findAllByUser(PDO $pdo, int $usuario_id): array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM listas_compras WHERE usuario_id = ? ORDER BY criada_em DESC"
        );
        $stmt->execute([$usuario_id]);
        
        $listas = [];
        foreach ($stmt->fetchAll() as $data) {
            $listas[] = new ListaCompra($data);
        }
        return $listas;
    }

    /**
     * Busca todos os itens de uma lista específica.
     * @return array Lista de itens (ex: ['produto_nome_normalizado' => 'arroz 5kg'])
     */
    public static function findItemsByListId(PDO $pdo, int $lista_id): array
    {
        $stmt = $pdo->prepare(
            "SELECT produto_nome, produto_nome_normalizado FROM listas_itens WHERE lista_id = ?"
        );
        $stmt->execute([$lista_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Encontra uma lista pelo nome (para evitar duplicados).
     */
    public static function findByName(PDO $pdo, int $usuario_id, string $nome_lista): ?ListaCompra
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM listas_compras WHERE usuario_id = ? AND nome_lista = ?"
        );
        $stmt->execute([$usuario_id, $nome_lista]);
        $data = $stmt->fetch();
        return $data ? new ListaCompra($data) : null;
    }

    /**
     * Apaga uma lista de compras (e todos os seus itens, graças ao 'ON DELETE CASCADE' do SQL).
     */
    public static function delete(PDO $pdo, int $lista_id, int $usuario_id): bool
    {
        // Garante que o usuário só apague as próprias listas
        $stmt = $pdo->prepare(
            "DELETE FROM listas_compras WHERE id = ? AND usuario_id = ?"
        );
        return $stmt->execute([$lista_id, $usuario_id]);
    }
}
?>