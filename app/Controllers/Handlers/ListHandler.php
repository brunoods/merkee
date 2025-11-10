<?php
// ---
// /app/Controllers/Handlers/ListHandler.php
// (VERSÃO COM NAMESPACE)
// ---

// 1. Define o Namespace
namespace App\Controllers\Handlers;

// 2. Importa dependências
use App\Models\ListaCompra; // O modelo que este handler precisa
// (BaseHandler está no mesmo namespace)

/**
 * Gere TODO o fluxo de conversa relacionado a Listas de Compras
 */
class ListHandler extends BaseHandler { // (Funciona)

    /**
     * Ponto de entrada.
     * O BotController chama este método e passa o estado ou o comando inicial.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // Se o estado for 'lista_start', significa que é um novo comando (ex: "listas")
        if ($estado === 'lista_start') {
            return $this->handleComandoInicial(strtolower($respostaUsuario));
        }

        // Se já está num estado de conversa, continua
        switch ($estado) {
            case 'aguardando_nome_lista':
                return $this->handleCriarNomeLista($respostaUsuario);
            
            case 'adicionando_itens_lista':
                return $this->handleAdicionarItens($respostaUsuario, $contexto);
                
            case 'aguardando_lista_para_apagar':
                return $this->handleApagarLista($respostaUsuario, $contexto);

            default:
                $this->usuario->clearState($this->pdo);
                return "Opa! 🤔 Parece que me perdi na gestão das tuas listas. Vamos recomeçar. Envia *listas* para ver as opções.";
        }
    }

    /**
     * Lida com o comando inicial (ex: "listas", "criar lista")
     */
    private function handleComandoInicial(string $comando): string
    {
        if ($comando === 'criar lista') {
            $this->usuario->updateState($this->pdo, 'aguardando_nome_lista');
            return "Ótimo! Qual será o nome desta nova lista? (Ex: *Compras do Mês*)";
        }
        
        if ($comando === 'ver listas') {
            return $this->mostrarListas("Aqui estão as tuas listas ativas:");
        }
        
        if ($comando === 'apagar lista') {
            $listas = ListaCompra::findAllByUser($this->pdo, $this->usuario->id);
            if (empty($listas)) {
                $this->usuario->clearState($this->pdo);
                return "Não tens nenhuma lista para apagar. 🤷‍♀️";
            }
            
            $resposta = "Qual lista queres apagar? (Envia só o *número*)\n";
            $contexto = [];
            foreach ($listas as $i => $lista) {
                $resposta .= "\n*" . ($i + 1) . "* - " . htmlspecialchars($lista['nome']);
                $contexto[$i + 1] = $lista['id']; // Mapeia 1 => ID_da_lista_X
            }
            
            $this->usuario->updateState($this->pdo, 'aguardando_lista_para_apagar', $contexto);
            return $resposta;
        }

        // Comando padrão "listas"
        $this->usuario->clearState($this->pdo); // Não inicia um estado
        $resposta = "Aqui estão os comandos para *Listas de Compras* 📝:\n";
        $resposta .= "\n➡️ *criar lista* (Cria uma nova lista)\n";
        $resposta .= "➡️ *ver listas* (Mostra todas as tuas listas)\n";
        $resposta .= "➡️ *apagar lista* (Remove uma lista)";
        return $resposta;
    }


    /**
     * Lógica do estado: aguardando_nome_lista
     */
    private function handleCriarNomeLista(string $respostaUsuario): string
    {
        $nomeLista = trim(strip_tags($respostaUsuario));
        if (empty($nomeLista) || strlen($nomeLista) > 50) {
            $this->usuario->updateState($this->pdo, 'aguardando_nome_lista'); // Tenta de novo
            return "Por favor, envia um nome válido para a lista (máx 50 caracteres).";
        }
        
        // Verifica se já existe
        $existente = ListaCompra::findByName($this->pdo, $this->usuario->id, $nomeLista);
        if ($existente) {
            $this->usuario->updateState($this->pdo, 'aguardando_nome_lista'); // Tenta de novo
            return "Já tens uma lista chamada *{$nomeLista}*. Tenta outro nome.";
        }
        
        // Cria a lista
        $novaLista = ListaCompra::create($this->pdo, $this->usuario->id, $nomeLista);
        
        // Muda o estado para adicionar itens
        $contexto = ['lista_id' => $novaLista->id, 'lista_nome' => $novaLista->nome];
        $this->usuario->updateState($this->pdo, 'adicionando_itens_lista', $contexto);
        
        return "Lista *{$novaLista->nome}* criada! ✅\n\nAgora, envia-me os produtos que queres adicionar (um por mensagem).\n\nEx: *Arroz 5kg*\nEx: *Leite Integral*\n\n(Envia *pronto* quando terminares)";
    }

    /**
     * Lógica do estado: adicionando_itens_lista
     */
    private function handleAdicionarItens(string $respostaUsuario, array $contexto): string
    {
        $comando = trim(strtolower($respostaUsuario));
        $listaId = $contexto['lista_id'];
        $listaNome = $contexto['lista_nome'];
        
        if ($comando === 'pronto' || $comando === 'fim' || $comando === 'terminar' || $comando === 'finalizar') {
            $this->usuario->clearState($this->pdo);
            return "Lista *{$listaNome}* guardada com sucesso! 💾\n\nPodes usá-la na próxima vez que enviares *iniciar compra*.";
        }
        
        // Adiciona o item
        $nomeItem = trim(strip_tags($respostaUsuario));
         if (empty($nomeItem) || strlen($nomeItem) > 100) {
             // Mantém o estado, pede de novo
             $this->usuario->updateState($this->pdo, 'adicionando_itens_lista', $contexto);
             return "Nome de produto inválido. Tenta de novo (ou envia *pronto*).";
         }
         
        ListaCompra::addItem($this->pdo, $listaId, $nomeItem);
        
        // Mantém o estado, pede o próximo
        $this->usuario->updateState($this->pdo, 'adicionando_itens_lista', $contexto);
        return "Adicionado: *{$nomeItem}* ✅\nPróximo item? (ou envia *pronto*)";
    }

    /**
     * Lógica do estado: aguardando_lista_para_apagar
     */
    private function handleApagarLista(string $respostaUsuario, array $contexto): string
    {
        $numero = trim($respostaUsuario);
        
        if (is_numeric($numero) && isset($contexto[$numero])) {
            $listaIdParaApagar = (int)$contexto[$numero];
            
            // Tenta apagar
            $sucesso = ListaCompra::delete($this->pdo, $listaIdParaApagar, $this->usuario->id);
            
            if ($sucesso) {
                $this->usuario->clearState($this->pdo);
                return "Lista apagada com sucesso! 🗑️";
            } else {
                $this->usuario->clearState($this->pdo);
                return "Não consegui apagar essa lista. Tenta enviar *apagar lista* novamente.";
            }
            
        } else {
            // Não entendeu, limpa o estado por segurança
            $this->usuario->clearState($this->pdo);
            return "Não entendi. 😕 Por favor, envia *apagar lista* e tenta de novo, enviando apenas o número.";
        }
    }
    
    /**
     * Helper para mostrar as listas do utilizador
     */
    private function mostrarListas(string $cabecalho): string
    {
        $listas = ListaCompra::findAllByUser($this->pdo, $this->usuario->id);
        if (empty($listas)) {
            $this->usuario->clearState($this->pdo);
            return "Não tens nenhuma lista guardada. 🤷‍♀️\nEnvia *criar lista* para começar uma!";
        }
        
        $this->usuario->clearState($this->pdo); // Apenas mostra, não inicia estado
        $resposta = $cabecalho . "\n";
        
        foreach ($listas as $lista) {
            $resposta .= "\n📋 *".htmlspecialchars($lista['nome'])."*";
            $itens = ListaCompra::findItemsByListId($this->pdo, $lista['id']);
            if (empty($itens)) {
                $resposta .= "\n  _(lista vazia)_";
            } else {
                foreach ($itens as $item) {
                    $resposta .= "\n  - " . htmlspecialchars($item['produto_nome']);
                }
            }
        }
        return $resposta;
    }
}
?>