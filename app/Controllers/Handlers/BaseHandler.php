<?php
// ---
// /app/Controllers/Handlers/BaseHandler.php
// (NOVO FICHEIRO)
// ---

// Inclui os Modelos que TODOS os handlers provavelmente usarão
require_once __DIR__ . '/../../Models/Usuario.php';

/**
 * Classe Abstrata Base
 * Serve como "molde" para todos os nossos Handlers.
 * Fornece acesso fácil ao PDO e ao Usuário.
 */
abstract class BaseHandler {

    protected PDO $pdo;
    protected Usuario $usuario;

    public function __construct(PDO $pdo, Usuario $usuario) {
        $this->pdo = $pdo;
        $this->usuario = $usuario;
    }

    /**
     * O "contrato" que obriga todas as classes filhas a ter
     * um método 'process' para lidar com a mensagem.
     *
     * @param string $estado O estado atual (ex: 'aguardando_nome_lista')
     * @param string $respostaUsuario A mensagem do usuário
     * @param array $contexto O contexto salvo no banco
     * @return string A resposta do bot
     */
    public abstract function process(string $estado, string $respostaUsuario, array $contexto): string;

}
?>