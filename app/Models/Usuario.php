<?php
// ---
// /app/Models/Usuario.php
// (VERSÃO COM NAMESPACE E findAll CORRIGIDO)
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

    private function __construct(int $id, string $whatsapp_id, ?string $nome, bool $nome_confirmado, 
                                 string $criado_em, ?string $estado, ?string $contexto, ?string $estado_iniciado, 
                                 bool $receber_alertas, bool $receber_dicas,
                                 bool $is_ativo, ?string $data_expiracao)
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
            $userData['data_expiracao']
        );
    }

    /**
     * Encontra todos os usuários (para o CRON).
     * (MODIFICADO: Agora inclui is_ativo para evitar spam a usuários inativos)
     */
    public static function findAll(PDO $pdo): array
    {
        // Seleciona todos os campos que os CRONs precisam
        $stmt = $pdo->query(
            "SELECT id, whatsapp_id, nome, receber_alertas, receber_dicas, is_ativo 
             FROM usuarios"
        );
        $usersData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $usuarios = [];
        foreach ($usersData as $userData) {
            $user = new stdClass(); // (Leve, para os CRONs)
            $user->id = (int)$userData['id'];
            $user->whatsapp_id = $userData['whatsapp_id'];
            $user->nome = $userData['nome'];
            $user->receber_alertas = (bool)$userData['receber_alertas'];
            $user->receber_dicas = (bool)$userData['receber_dicas'];
            $user->is_ativo = (bool)$userData['is_ativo']; // (IMPORTANTE)
            $usuarios[] = $user;
        }
        return $usuarios;
    }

    /**
     * Encontra um usuário pelo seu ID numérico.
     */
    public static function findById(PDO $pdo, int $id): ?Usuario 
    {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $userData = $stmt->fetch();
        return $userData ? self::fromData($userData) : null;
    }

    /**
     * Encontra um usuário ou cria um novo.
     */
    public static function findOrCreate(PDO $pdo, string $whatsapp_id, ?string $nome): Usuario 
    {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE whatsapp_id = ?");
        $stmt->execute([$whatsapp_id]);
        $userData = $stmt->fetch();

        if ($userData) {
            return self::fromData($userData);
        } else {
            // (Substituído 'NOW()' por (new DateTime())->format() para consistência)
            $dataCriacao = (new DateTime())->format('Y-m-d H:i:s');
            
            $stmt = $pdo->prepare(
                "INSERT INTO usuarios (whatsapp_id, nome, nome_confirmado, criado_em) VALUES (?, ?, 0, ?)"
            );
            $stmt->execute([$whatsapp_id, $nome, $dataCriacao]); 
            $newId = (int)$pdo->lastInsertId();
            
            // Retorna um objeto "falso" mas funcional para o resto do script
            // (Nasce inativo por padrão, admin deve ativar)
            return new Usuario(
                $newId, $whatsapp_id, $nome, false, 
                $dataCriacao,
                null, null, null, true, true,
                false, 
                null
            );
        }
    }

    /**
     * (NOVO!) Atualiza o nome e marca como confirmado.
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
        // Garante que só estas colunas podem ser alteradas
        if ($coluna !== 'receber_alertas' && $coluna !== 'receber_dicas') {
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE usuarios SET {$coluna} = ? WHERE id = ?"
        );
        $stmt->execute([$valor, $this->id]);
        
        // Atualiza o objeto local
        $this->{$coluna} = $valor;
    }

    /**
     * Gera e guarda um token de login único e temporário.
     */
    public function updateLoginToken(PDO $pdo): string
    {
        // Gera um token seguro de 64 caracteres
        $token = bin2hex(random_bytes(32)); 
        
        // Define a validade para 10 minutos a partir de agora
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
     * Encontra um usuário pelo seu token de login,
     * garantindo que não tenha expirado.
     */
    public static function findByLoginToken(PDO $pdo, string $token): ?Usuario 
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM usuarios WHERE login_token = ? AND login_token_expira_em > NOW()"
        );
        $stmt->execute([$token]);
        $userData = $stmt->fetch();
        
        if ($userData) {
            // (Encontrou o utilizador E o token é válido)
            // Limpa o token para que não possa ser usado novamente
            $pdo->prepare("UPDATE usuarios SET login_token = NULL WHERE id = ?")
                ->execute([$userData['id']]);
                
            return self::fromData($userData);
        }
        
        return null; // Token inválido ou expirado
    }
}
?>