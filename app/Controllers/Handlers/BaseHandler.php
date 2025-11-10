<?php
// ---
// /app/Controllers/Handlers/BaseHandler.php
// (VERSÃO COM NAMESPACE)
// ---

// 1. Define o Namespace
namespace App\Controllers\Handlers;

// 2. Importa dependências
use PDO;
use App\Models\Usuario; // Importa a classe Usuario

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
     * @param string $respostaUsuario O texto exato que o usuário enviou
     * @param array $contexto Dados guardados (ex: 'lista_id')
     * @return string A resposta do bot
     */
    public abstract function process(string $estado, string $respostaUsuario, array $contexto): string;

}
?>