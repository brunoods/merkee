<?php
// ---
// /app/Controllers/Handlers/ListHandler.php
// (NOVO FICHEIRO)
// ---

require_once __DIR__ . '/BaseHandler.php'; // O nosso "molde"
require_once __DIR__ . '/../../Models/ListaCompra.php'; // O modelo que este handler precisa

/**
 * Gere TODO o fluxo de conversa relacionado a Listas de Compras
 * (Criar, Adicionar Itens, Apagar).
 */
class ListHandler extends BaseHandler {

    /**
     * Ponto de entrada. O BotController chama este método.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // Delega para a função interna correta baseada no estado
        switch ($estado) {
            case 'aguardando_nome_lista':
                return $this->handleCriarNomeLista($respostaUsuario);
            
            case 'adicionando_itens_lista':
                return $this->handleAdicionarItens($respostaUsuario, $contexto);

            case 'aguardando_lista_para_apagar':
                return $this->handleApagarLista($respostaUsuario, $contexto);
            
            default:
                // Segurança: se algo der errado, limpa o estado
                $this->usuario->clearState($this->pdo);
                return "Ops, me perdi um pouco (Handler de Lista). Vamos recomeçar.";
        }
    }

    // --- (LÓGICA MOVIDA DIRETAMENTE DO BotController) ---

    /**
     * Lógica do estado: aguardando_nome_lista
     */
    private function handleCriarNomeLista(string $respostaUsuario): string
    {
        $nomeLista = trim($respostaUsuario);
        if (empty($nomeLista)) {
            return "O nome não pode ser vazio. 😕 Por favor, digite um nome para a sua lista (ex: *Compras do Mês*).";
        }
        // (Usamos $this->pdo e $this->usuario, que vieram da BaseHandler)
        if (ListaCompra::findByName($this->pdo, $this->usuario->id, $nomeLista)) {
            return "Já existe uma lista com o nome '*{$nomeLista}*'. 😕 Tente um nome diferente.";
        }
        
        $lista = ListaCompra::create($this->pdo, $this->usuario->id, $nomeLista);
        $this->usuario->updateState($this->pdo, 'adicionando_itens_lista', ['lista_id' => $lista->id, 'lista_nome' => $lista->nome_lista]);
        
        return "Perfeito! Lista '*{$nomeLista}*' criada. ✅\n\nAgora, envia-me os produtos que queres adicionar, *um por um*.\nEx: `Arroz Tio João 5kg`\n\nQuando terminares, digita *salvar* ou *pronto*.";
    }

    /**
     * Lógica do estado: adicionando_itens_lista
     */
    private function handleAdicionarItens(string $respostaUsuario, array $contexto): string
    {
        $listaId = $contexto['lista_id'];
        $listaNome = $contexto['lista_nome'];
        
        if ($respostaUsuario === 'salvar' || $respostaUsuario === 'pronto' || $respostaUsuario === 'fim') {
            $this->usuario->clearState($this->pdo);
            return "Lista '*{$listaNome}*' salva com sucesso! 👍\n\nPodes vê-la com o comando `ver listas` ou usá-la da próxima vez que digitares `iniciar compra`.";
        }

        // Adiciona o item à lista
        ListaCompra::addItem($this->pdo, $listaId, $respostaUsuario);
        return "Item '*{$respostaUsuario}*' adicionado! ✅\nPróximo item? (ou *salvar* para terminar)";
    }

    /**
     * Lógica do estado: aguardando_lista_para_apagar
     */
    private function handleApagarLista(string $respostaUsuario, array $contexto): string
    {
        $listas = $contexto['listas_para_apagar'] ?? [];
        $respostaLimpa = trim($respostaUsuario);

        if (is_numeric($respostaLimpa) && isset($listas[(int)$respostaLimpa])) {
            $listaParaApagar = $listas[(int)$respostaLimpa];
            ListaCompra::delete($this->pdo, $listaParaApagar['id'], $this->usuario->id);
            $this->usuario->clearState($this->pdo);
            return "Lista '*{$listaParaApagar['nome']}*' apagada com sucesso. 🗑️";
        } else {
            return "Opção inválida. 😕 Por favor, digite o *número* da lista que queres apagar, ou *cancelar*.";
        }
    }
}
?>