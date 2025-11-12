<?php
// ---
// /app/Models/Usuario.php
// (VERSÃO COM NAMESPACE E FUNÇÕES DE LOGIN + MERCADO FAVORITO)
// ---

// 1. Define o Namespace
namespace App\Models;

// 2. Importa dependências
use PDO;
use stdClass; // (para o método findAll)
use DateTime; // (para o findOrCreate)

class Usuario {
    
    public int $id;
    public string $whatsapp_id;
    public ?string $nome;
    public bool $nome_confirmado;
    public string $criado_em;
    public ?string $conversa_estado;
    public ?array $conversa_contexto;
    public ?string $conversa_estado_iniciado_em;
    public bool $receber_alertas;
    public bool $receber_dicas;
    public bool $is_ativo;
    public ?string $data_expiracao;
    public ?string $login_token; 
    public ?string $login_token_expira_em;
    // --- (NOVA PROPRIEDADE) ---
    public ?int $mercado_favorito_id; 

    private function __construct(int $id, string $whatsapp_id, ?string $nome, bool $nome_confirmado, 
                                 string $criado_em, ?string $estado, ?string $contexto, ?string $estado_iniciado, 
                                 bool $receber_alertas, bool $receber_dicas,
                                 bool $is_ativo, ?string $data_expiracao,
                                 ?string $login_token, ?string $login_token_expira_em,
                                 ?int $mercado_favorito_id) // (Adicionado)
    {
        $this->id = $id;
        $this->whatsapp_id = $whatsapp_id;
        $this->nome = $nome;
        $this->nome_confirmado = $nome_confirmado;
        $this->criado_em = $criado_em;
        $this->conversa_estado = $estado;
        $this->conversa_contexto = $contexto ? json_decode($contexto, true) : [];
        $this->conversa_estado_iniciado_em = $estado_iniciado;
        $this->receber_alertas = $receber_alertas;
        $this->receber_dicas = $receber_dicas;
        $this->is_ativo = $is_ativo;
        $this->data_expiracao = $data_expiracao;
        $this->login_token = $login_token; 
        $this->login_token_expira_em = $login_token_expira_em; 
        $this->mercado_favorito_id = $mercado_favorito_id; // (Adicionado)
    }

    /**
     * Helper para criar um objeto Usuario a partir de dados do PDO.
     */
    private static function fromData(array $userData): Usuario
    {
        return new Usuario(
            (int)$userData['id'],
            (string)$userData['whatsapp_id'],
            $userData['nome'],
            (bool)$userData['nome_confirmado'], 
            (string)$userData['criado_em'],
            $userData['conversa_estado'],
            $userData['conversa_contexto'],
            $userData['conversa_estado_iniciado_em'],
            (bool)$userData['receber_alertas'],
            (bool)$userData['receber_dicas'],
            (bool)$userData['is_ativo'],
            $userData['data_expiracao'],
            $userData['login_token'] ?? null,
            $userData['login_token_expira_em'] ?? null,
            (int)$userData['mercado_favorito_id'] ?? null // (Adicionado)
        );
    }

    /**
     * Atualiza o nome e marca como confirmado.
     */
    public function updateNameAndConfirm(PDO $pdo, string $nome): void
    {
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET nome = ?, nome_confirmado = 1 WHERE id = ?"
        );
        $stmt->execute([$nome, $this->id]);
        $this->nome = $nome;
        $this->nome_confirmado = true;
    }
    
    /**
     * Atualiza o estado da conversa do usuário.
     */
    public function updateState(PDO $pdo, string $estado, ?array $contexto = null): void
    {
        $contextoJson = $contexto ? json_encode($contexto) : null;
        $stmt = $pdo->prepare(
            "UPDATE usuarios 
             SET conversa_estado = ?, 
                 conversa_contexto = ?, 
                 conversa_estado_iniciado_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->execute([$estado, $contextoJson, $this->id]);
        $this->conversa_estado = $estado;
        $this->conversa_contexto = $contexto;
        $this->conversa_estado_iniciado_em = date('Y-m-d H:i:s');
    }
    
    /**
     * Limpa o estado da conversa do usuário.
     */
    public function clearState(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            "UPDATE usuarios 
             SET conversa_estado = NULL, 
                 conversa_contexto = NULL,
                 conversa_estado_iniciado_em = NULL
             WHERE id = ?"
        );
        $stmt->execute([$this->id]);
        $this->conversa_estado = null;
        $this->conversa_contexto = null;
        $this->conversa_estado_iniciado_em = null;
    }
    
    /**
     * Atualiza uma configuração específica do usuário.
     */
    public function updateConfig(PDO $pdo, string $coluna, bool $valor): void
    {
        if ($coluna !== 'receber_alertas' && $coluna !== 'receber_dicas') {
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE usuarios SET {$coluna} = ? WHERE id = ?"
        );
        $stmt->execute([$valor, $this->id]);
        
        $this->{$coluna} = $valor;
    }

    // --- (NOVO MÉTODO) ---

    /**
     * FEATURE #18: Atualiza o ID do mercado favorito do utilizador.
     */
    public function updateFavoriteMarket(PDO $pdo, ?int $estabelecimento_id): void
    {
        // Se o ID for 0 ou nulo, guardamos NULL na base de dados
        $id_para_db = ($estabelecimento_id > 0) ? $estabelecimento_id : null;
        
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET mercado_favorito_id = ? WHERE id = ?"
        );
        $stmt->execute([$id_para_db, $this->id]);
        
        $this->mercado_favorito_id = $id_para_db;
    }

    // --- (FIM DO NOVO MÉTODO) ---

    public static function findAll(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT id, whatsapp_id, nome, receber_alertas, receber_dicas, is_ativo 
             FROM usuarios"
        );
        $usersData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $usuarios = [];
        foreach ($usersData as $userData) {
            $user = new stdClass(); 
            $user->id = (int)$userData['id'];
            $user->whatsapp_id = $userData['whatsapp_id'];
            $user->nome = $userData['nome'];
            $user->receber_alertas = (bool)$userData['receber_alertas'];
            $user->receber_dicas = (bool)$userData['receber_dicas'];
            $user->is_ativo = (bool)$userData['is_ativo']; 
            $usuarios[] = $user;
        }
        return $usuarios;
    }

    public static function findById(PDO $pdo, int $id): ?Usuario 
    {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $userData = $stmt->fetch();
        return $userData ? self::fromData($userData) : null;
    }

    public static function findOrCreate(PDO $pdo, string $whatsapp_id, ?string $nome): Usuario 
    {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE whatsapp_id = ?");
        $stmt->execute([$whatsapp_id]);
        $userData = $stmt->fetch();

        if ($userData) {
            return self::fromData($userData);
        } else {
            $dataCriacao = (new DateTime())->format('Y-m-d H:i:s');
            
            $stmt = $pdo->prepare(
                "INSERT INTO usuarios (whatsapp_id, nome, nome_confirmado, criado_em) VALUES (?, ?, 0, ?)"
            );
            $stmt->execute([$whatsapp_id, $nome, $dataCriacao]); 
            $newId = (int)$pdo->lastInsertId();
            
            return new Usuario(
                $newId, $whatsapp_id, $nome, false, 
                $dataCriacao,
                null, null, null, true, true,
                false, 
                null, null, null, null // Adiciona null para mercado_favorito_id
            );
        }
    }

    public static function findByLoginToken(PDO $pdo, string $token): ?Usuario 
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM usuarios WHERE login_token = ? AND login_token_expira_em > NOW()"
        );
        $stmt->execute([$token]);
        $userData = $stmt->fetch();
        
        if ($userData) {
            $pdo->prepare("UPDATE usuarios SET login_token = NULL WHERE id = ?")
                ->execute([$userData['id']]);
                
            return self::fromData($userData);
        }
        
        return null;
    }

    public function updateLoginToken(PDO $pdo): string
    {
        $token = bin2hex(random_bytes(32)); 
        $expiraEm = (new \DateTime('+10 minutes'))->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            "UPDATE usuarios SET login_token = ?, login_token_expira_em = ? WHERE id = ?"
        );
        $stmt->execute([$token, $expiraEm, $this->id]);
        
        $this->login_token = $token;
        $this->login_token_expira_em = $expiraEm;
        
        return $token;
    }

    /**
     * NOVO MÉTODO: Ativa um trial de 24h para o utilizador.
     * Usado após a primeira compra ser finalizada.
     */
    public function ativarTrial24h(PDO $pdo): void
    {
        // Define a data de expiração para daqui a 24 horas
        $novaDataExpiracao = (new \DateTime('+24 hours'))->format('Y-m-d H:i:s');
        
        $stmt = $pdo->prepare(
            "UPDATE usuarios 
             SET is_ativo = 1, data_expiracao = ? 
             WHERE id = ?"
        );
        $stmt->execute([$novaDataExpiracao, $this->id]);
        
        // Atualiza o objeto local para que o resto do script saiba
        $this->is_ativo = true;
        $this->data_expiracao = $novaDataExpiracao;
    }
}
?>