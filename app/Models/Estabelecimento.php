<?php
// ---
// /app/Models/Estabelecimento.php
// AGORA COM SUPORTE A GOOGLE PLACE ID
// ---

// 1. (A CORREÇÃO)
namespace App\Models;

// 2. (A CORREÇÃO)
use PDO;


class Estabelecimento {

    public int $id;
    public string $nome;
    public string $cidade;
    public string $estado;
    public ?string $google_place_id;

    private function __construct(array $data) {
        $this->id = (int)$data['id'];
        $this->nome = $data['nome'];
        $this->cidade = $data['cidade'];
        $this->estado = $data['estado'];
        $this->google_place_id = $data['google_place_id'];
    }

    /**
     * Tenta encontrar um estabelecimento pelo Google Place ID (o mais fiável).
     * @param PDO $pdo
     * @param string $place_id
     * @return Estabelecimento|null
     */
    public static function findByPlaceId(PDO $pdo, string $place_id): ?Estabelecimento
    {
        $stmt = $pdo->prepare("SELECT * FROM estabelecimentos WHERE google_place_id = ?");
        $stmt->execute([$place_id]);
        $data = $stmt->fetch();
        return $data ? new Estabelecimento($data) : null;
    }

    /**
     * Encontra um estabelecimento manualmente (como antes).
     * @param PDO $pdo
     * @param string $nome
     * @param string $cidade
     * @param string $estado
     * @return Estabelecimento|null
     */
    public static function findByManualEntry(PDO $pdo, string $nome, string $cidade, string $estado): ?Estabelecimento
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM estabelecimentos 
             WHERE nome = ? AND cidade = ? AND estado = ? AND google_place_id IS NULL" // Só encontra manuais
        );
        $stmt->execute([$nome, $cidade, $estado]);
        $data = $stmt->fetch();
        return $data ? new Estabelecimento($data) : null;
    }

    /**
     * Cria um novo estabelecimento (registo manual).
     * @param PDO $pdo
     * @param string $nome
     * @param string $cidade
     * @param string $estado
     * @return Estabelecimento
     */
    public static function createManual(PDO $pdo, string $nome, string $cidade, string $estado): Estabelecimento
    {
        $stmt = $pdo->prepare(
            "INSERT INTO estabelecimentos (nome, cidade, estado) 
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$nome, $cidade, $estado]);
        $newId = (int)$pdo->lastInsertId();
        
        $data = [
            'id' => $newId, 'nome' => $nome, 'cidade' => $cidade, 
            'estado' => $estado, 'google_place_id' => null
        ];
        return new Estabelecimento($data);
    }

    /**
     * Cria um novo estabelecimento a partir de dados do Google.
     * @param PDO $pdo
     * @param string $place_id
     * @param string $nomeGoogle
     * @param string $cidade
     * @param string $estado
     * @return Estabelecimento
     */
    public static function createFromGoogle(PDO $pdo, string $place_id, string $nomeGoogle, string $cidade, string $estado): Estabelecimento
    {
        $stmt = $pdo->prepare(
            "INSERT INTO estabelecimentos (nome, cidade, estado, google_place_id) 
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$nomeGoogle, $cidade, $estado, $place_id]);
        $newId = (int)$pdo->lastInsertId();
        
        $data = [
            'id' => $newId, 'nome' => $nomeGoogle, 'cidade' => $cidade, 
            'estado' => $estado, 'google_place_id' => $place_id
        ];
        return new Estabelecimento($data);
    }
    
    /**
     * Encontra um estabelecimento pelo seu ID (para o resumo).
     */
    public static function findById(PDO $pdo, int $id): ?Estabelecimento
    {
        $stmt = $pdo->prepare("SELECT * FROM estabelecimentos WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        return $data ? new Estabelecimento($data) : null;
    }
}