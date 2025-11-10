<?php
// ---
// /app/Models/Estabelecimento.php
// ---

// 1. (A CORREÇÃO)
namespace App\Models;

// 2. (A CORREÇÃO)
use PDO;

// 3. (A CORREÇÃO)
class Estabelecimento {

    public int $id;
    public ?string $google_place_id;
    public string $nome;
    public string $cidade;
    public string $estado;

    private function __construct(array $data) {
        $this->id = (int)$data['id'];
        $this->google_place_id = $data['google_place_id'];
        $this->nome = $data['nome'];
        $this->cidade = $data['cidade'];
        $this->estado = $data['estado'];
    }

    /**
     * Helper para criar um objeto a partir de dados do PDO.
     */
    private static function fromData(array $data): Estabelecimento
    {
        return new Estabelecimento($data);
    }

    /**
     * Tenta encontrar um estabelecimento pelo Google Place ID.
     */
    public static function findByPlaceId(PDO $pdo, string $place_id): ?Estabelecimento
    {
        $stmt = $pdo->prepare("SELECT * FROM estabelecimentos WHERE google_place_id = ?");
        $stmt->execute([$place_id]);
        $data = $stmt->fetch();
        return $data ? self::fromData($data) : null;
    }

    /**
     * Encontra um estabelecimento manualmente (para evitar duplicados).
     */
    public static function findByManualEntry(PDO $pdo, string $nome, string $cidade, string $estado): ?Estabelecimento
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM estabelecimentos 
             WHERE google_place_id IS NULL 
               AND nome = ? 
               AND cidade = ? 
               AND estado = ?"
        );
        $stmt->execute([$nome, $cidade, $estado]);
        $data = $stmt->fetch();
        return $data ? self::fromData($data) : null;
    }

    /**
     * Cria um novo estabelecimento (registo manual).
     */
    public static function createManual(PDO $pdo, string $nome, string $cidade, string $estado): Estabelecimento
    {
        $stmt = $pdo->prepare(
            "INSERT INTO estabelecimentos (nome, cidade, estado) VALUES (?, ?, ?)"
        );
        $stmt->execute([$nome, $cidade, $estado]);
        $newId = (int)$pdo->lastInsertId();
        
        return new Estabelecimento([
            'id' => $newId,
            'google_place_id' => null,
            'nome' => $nome,
            'cidade' => $cidade,
            'estado' => $estado
        ]);
    }

    /**
     * Cria um novo estabelecimento a partir de dados do Google.
     */
    public static function createFromGoogle(PDO $pdo, string $place_id, string $nomeGoogle, string $cidade, string $estado): Estabelecimento
    {
         $stmt = $pdo->prepare(
            "INSERT INTO estabelecimentos (google_place_id, nome, cidade, estado) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$place_id, $nomeGoogle, $cidade, $estado]);
        $newId = (int)$pdo->lastInsertId();
        
        return new Estabelecimento([
            'id' => $newId,
            'google_place_id' => $place_id,
            'nome' => $nomeGoogle,
            'cidade' => $cidade,
            'estado' => $estado
        ]);
    }
    
    /**
     * Encontra um estabelecimento pelo seu ID (para o resumo).
     */
    public static function findById(PDO $pdo, int $id): ?Estabelecimento
    {
        $stmt = $pdo->prepare("SELECT * FROM estabelecimentos WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        return $data ? self::fromData($data) : null;
    }
}
?>