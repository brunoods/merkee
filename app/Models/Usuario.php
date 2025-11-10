<?php
// ---
// /app/Models/Usuario.php
// (VERSÃO COM 'nome_confirmado' E 'data_expiracao')
// ---

class Usuario {
    
    public int $id;
    public string $whatsapp_id;
    public ?string $nome;

    // --- (INÍCIO DA ATUALIZAÇÃO) ---
    public bool $nome_confirmado; // 1. Adiciona a nova propriedade
    // --- (FIM DA ATUALIZAÇÃO) ---

    public string $criado_em;
    public ?string $conversa_estado;
    public ?array $conversa_contexto;
    public ?string $conversa_estado_iniciado_em;
    public bool $receber_alertas;
    public bool $receber_dicas;
    public bool $is_ativo;
    public ?string $data_expiracao;

    private function __construct(int $id, string $whatsapp_id, ?string $nome, bool $nome_confirmado, // 2. Adiciona ao construtor
                                 string $criado_em, ?string $estado, ?string $contexto, ?string $estado_iniciado, 
                                 bool $receber_alertas, bool $receber_dicas,
                                 bool $is_ativo, ?string $data_expiracao)
    {
        $this->id = $id;
        $this->whatsapp_id = $whatsapp_id;
        $this->nome = $nome;
        
        // --- (INÍCIO DA ATUALIZAÇÃO) ---
        $this->nome_confirmado = $nome_confirmado; // 3. Atribui a propriedade
        // --- (FIM DA ATUALIZAÇÃO) ---

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
            // --- (INÍCIO DA ATUALIZAÇÃO) ---
            (bool)$userData['nome_confirmado'], // 4. Lê a nova coluna
            // --- (FIM DA ATUALIZAÇÃO) ---
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
     */
    public static function findAll(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT id, whatsapp_id, nome, receber_alertas, receber_dicas FROM usuarios");
        $usersData = $stmt->fetchAll();
        
        $usuarios = [];
        foreach ($usersData as $userData) {
            $user = new stdClass();
            $user->id = (int)$userData['id'];
            $user->whatsapp_id = $userData['whatsapp_id'];
            $user->nome = $userData['nome'];
            $user->receber_alertas = (bool)$userData['receber_alertas'];
            $user->receber_dicas = (bool)$userData['receber_dicas'];
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
            // (O INSERT agora usa o nome 'Visitante' temporariamente)
            $stmt = $pdo->prepare(
                "INSERT INTO usuarios (whatsapp_id, nome, nome_confirmado) VALUES (?, ?, 0)"
            );
            $stmt->execute([$whatsapp_id, $nome]); // $nome aqui é 'Visitante' vindo do webhook
            $newId = (int)$pdo->lastInsertId();
            
            // --- (INÍCIO DA ATUALIZAÇÃO) ---
            // 5. Quando um novo usuário é criado, ele nasce 'inativo' e com 'nome_confirmado = false'
            return new Usuario(
                $newId, $whatsapp_id, $nome, false, // <-- nome_confirmado = false
                date('Y-m-d H:i:s'),
                null, null, null, true, true,
                false, // is_ativo = false
                null   // data_expiracao = null
            );
            // --- (FIM DA ATUALIZAÇÃO) ---
        }
    }

    // --- (INÍCIO DA ATUALIZAÇÃO) ---
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
    // --- (FIM DA ATUALIZAÇÃO) ---
    
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
        // Validação de segurança para garantir que a coluna é a correta
        if ($coluna !== 'receber_alertas' && $coluna !== 'receber_dicas') {
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE usuarios SET {$coluna} = ? WHERE id = ?"
        );
        $stmt->execute([$valor, $this->id]);
        
        // Atualiza o objeto
        $this->{$coluna} = $valor;
    }
}
?>