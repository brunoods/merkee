<?php
// ---
// /app/Controllers/Handlers/PurchaseStartHandler.php
// (VERSÃO COMPLETA - FLUXO DE LOCALIZAÇÃO + FALLBACKS)
// ---

// 1. Define o Namespace
namespace App\Controllers\Handlers;

// 2. Importa todas as dependências
use App\Models\Compra;
use App\Models\Estabelecimento;
use App\Models\HistoricoPreco;
use App\Models\ListaCompra;
use App\Services\GooglePlacesService;
use App\Utils\StringUtils;
// (BaseHandler está no mesmo namespace)

/**
 * Gere TODO o fluxo de conversa para INICIAR uma nova compra,
 * priorizando a localização do utilizador.
 */
class PurchaseStartHandler extends BaseHandler { 

    /**
     * Ponto de entrada.
     * O BotController chama este método e passa o estado e o contexto.
     * O contexto pode conter 'location' se o utilizador a partilhou.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // Roteador de estados para este Handler
        
        // Fluxo 1: Novo comando "iniciar compra"
        if ($estado === 'inicio_start') {
            return $this->handleInicioCompra();
        }
        
        // Fluxo 2: Utilizador partilhou a localização
        if ($estado === 'aguardando_localizacao') {
            // Verifica se a localização veio no contexto
            if ($respostaUsuario === 'USER_SENT_LOCATION' && isset($contexto['location'])) {
                return $this->handleLocalizacaoRecebida($contexto['location']);
            } else {
                // Se o utilizador escreveu texto (ex: nome do mercado)
                return $this->handleInicioCompraFallback($respostaUsuario);
            }
        }
        
        // Fluxo 3: Utilizador está a confirmar um local da lista (Google ou Proximidade)
        if ($estado === 'aguardando_local_google_confirmacao') {
             return $this->handleLocalGoogleConfirmacao($respostaUsuario, $contexto);
        }

        // Fluxo 4: Fluxo de registo manual (se tudo o resto falhar)
        if ($estado === 'aguardando_local_manual_cidade') {
            return $this->handleLocalManualCidade($respostaUsuario, $contexto);
        }
        if ($estado === 'aguardando_local_manual_estado') {
             return $this->handleLocalManualEstado($respostaUsuario, $contexto);
        }
        
        // Fluxo 5: Fluxo de escolha de lista (depois de o local estar definido)
        if ($estado === 'aguardando_lista_para_iniciar') {
             return $this->handleEscolhaDeLista($respostaUsuario, $contexto);
        }
        
        // (O estado 'aguardando_local_google' foi fundido no 'handleInicioCompraFallback')
                 
        $this->usuario->clearState($this->pdo);
        return "Opa! 🤔 Parece que me perdi no início da tua compra. Vamos recomeçar. Envia *iniciar compra* novamente.";
    }
    
    /**
     * PASSO 1 (Novo Fluxo):
     * O utilizador envia "iniciar compra". O bot pede a localização.
     */
    private function handleInicioCompra(): string
    {
        // Define o estado de espera
        $this->usuario->updateState($this->pdo, 'aguardando_localizacao');
        
        // Pede a localização
        $resposta = "Vamos começar! 🛍️\n\n";
        $resposta .= "Para encontrar os mercados mais próximos, por favor, *partilhe a sua localização* atual.\n";
        $resposta .= "(Use o clip 📎 e escolha 'Localização' > 'Localização Atual')";
        $resposta .= "\n\nSe preferir, podes *digitar o nome do mercado* para pesquisar.";
        
        return $resposta;
    }
    
    /**
     * PASSO 2 (Novo Fluxo):
     * O utilizador partilhou a localização.
     * O bot busca na API e mostra os 3+1 resultados.
     */
    private function handleLocalizacaoRecebida(array $location): string
    {
        $google = new GooglePlacesService();
        $locais = $google->buscarSupermercadosProximos(
            $location['latitude'], 
            $location['longitude']
        );

        if (empty($locais)) {
            // Se o Google não encontrar nada, vai para o fluxo de pesquisa manual
            return $this->handleInicioCompraFallback("Não encontrei supermercados perto de si. 😕");
        }
        
        $resposta = "Encontrei estes locais perto de si. Onde estás? (Envia só o *número*)\n";
        $novoContexto = [];
        $opcoes = 1;
        
        foreach ($locais as $local) {
            $resposta .= "\n*$opcoes* - " . htmlspecialchars($local['nome_google']);
            $resposta .= "\n  _" . htmlspecialchars($local['endereco']) . "_";
            $novoContexto[$opcoes] = $local; // Guarda dados do Google
            $opcoes++;
        }
        
        $novoContexto['acao_manual'] = $opcoes;
        $resposta .= "\n\n*$opcoes* - Nenhum destes (digitar nome)";

        // (Reutiliza o estado 'aguardando_local_google_confirmacao' do fluxo antigo)
        $this->usuario->updateState($this->pdo, 'aguardando_local_google_confirmacao', $novoContexto);
        return $resposta;
    }

    /**
     * PASSO 2 (Fallback):
     * O utilizador digitou texto (nome do mercado) em vez de partilhar localização.
     * Inicia o fluxo de pesquisa por nome (o fluxo antigo).
     */
    private function handleInicioCompraFallback(string $respostaUsuario): string
    {
        // Tenta encontrar a última cidade
        $ultimoLocal = Compra::findLastCompletedByUser($this->pdo, $this->usuario->id);
        if (!$ultimoLocal) {
            // Se não temos local, força o fluxo 100% manual
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', ['nome_mercado' => $respostaUsuario]);
             return "Entendido. Como é a tua primeira compra, vamos registar manualmente o *{$respostaUsuario}*.\n\nEm qual *cidade* ele fica?";
        }
        $cidadeEstado = $ultimoLocal['cidade'] . " - " . $ultimoLocal['estado'];

        // USA O SERVIÇO GOOGLE PLACES (Busca por Texto)
        $google = new GooglePlacesService();
        $locais = $google->buscarLocais($respostaUsuario, $cidadeEstado);
        
        if (empty($locais)) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', ['nome_mercado' => $respostaUsuario]);
            return "Não encontrei *{$respostaUsuario}* em {$cidadeEstado}. 📍\nVamos registar manualmente. Por favor, confirma-me o *nome* do mercado (ou digita 'cancelar').";
        }

        $resposta = "Encontrei estes locais para '{$respostaUsuario}'. Qual é o correto? (Envia só o *número*)\n";
        $novoContexto = [];
        foreach ($locais as $i => $local) {
            $resposta .= "\n*" . ($i + 1) . "* - " . htmlspecialchars($local['nome_google']);
            $resposta .= "\n  _" . htmlspecialchars($local['endereco']) . "_";
            $novoContexto[$i + 1] = $local; 
        }
        $novoContexto['acao_manual'] = count($locais) + 1;
        $resposta .= "\n*" . $novoContexto['acao_manual'] . "* - Nenhum destes (Registar manualmente)";

        $this->usuario->updateState($this->pdo, 'aguardando_local_google_confirmacao', $novoContexto);
        return $resposta;
    }


    /**
     * PASSO 3 (Fluxo Comum):
     * O utilizador está a ver os resultados da pesquisa (Google ou Proximidade) e escolhe.
     */
    private function handleLocalGoogleConfirmacao(string $respostaUsuario, array $contexto): string
    {
         $escolha = trim($respostaUsuario);
         
         // 1. Escolheu "Manual"
         if (isset($contexto['acao_manual']) && $escolha == $contexto['acao_manual']) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', ['nome_mercado' => 'Manual']);
            return "Entendido. Qual o *nome* do mercado?";
         }
         
         // 2. Escolheu um local
         if (is_numeric($escolha) && isset($contexto[$escolha])) {
             $localEscolhido = $contexto[$escolha]; // Array com 'place_id', 'nome_google', 'endereco'
             
             // Tenta encontrar ou criar este estabelecimento na nossa DB
             $estabelecimento = Estabelecimento::findByPlaceId($this->pdo, $localEscolhido['place_id']);
             
             if (!$estabelecimento) {
                 // Tenta extrair cidade/estado do endereço
                 $endereco = $localEscolhido['endereco']; 
                 $cidade = 'N/A';
                 $estado = 'N/A';
                 // Tenta extrair (Ex: "Rua X, Mirassol - SP" ou "Mirassol, SP")
                 if (preg_match('/, ([\w\s]+) - (\w{2})/', $endereco, $matches) || preg_match('/([\w\s]+), (\w{2})/', $endereco, $matches)) {
                     $cidade = trim($matches[1]);
                     $estado = $matches[2];
                 }
                 
                 $estabelecimento = Estabelecimento::createFromGoogle(
                     $this->pdo, 
                     $localEscolhido['place_id'], 
                     $localEscolhido['nome_google'], 
                     $cidade, 
                     $estado
                 );
             }
             
             // Vai para o Passo 4 (Escolha de Lista)
             return $this->iniciarFluxoDeLista($estabelecimento);
         }
         
         // 3. Não entendeu
         $this->usuario->clearState($this->pdo);
         return "Não entendi a tua escolha. 😕 Vamos recomeçar. Envia *iniciar compra*.";
    }

    /**
     * PASSO 3 (Fluxo Manual - Cidade):
     * O utilizador está no fluxo de registo manual.
     */
    private function handleLocalManualCidade(string $respostaUsuario, array $contexto): string
    {
        $nomeMercado = trim(strip_tags($respostaUsuario));
        if (strtolower($nomeMercado) === 'cancelar') {
            $this->usuario->clearState($this->pdo);
            return "Ok, cancelado. 👍";
        }
        
        if (empty($nomeMercado) || strlen($nomeMercado) > 100) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', $contexto);
            return "Por favor, envia um nome válido para o mercado.";
        }
        
        $contexto['nome_mercado'] = $nomeMercado;
        $this->usuario->updateState($this->pdo, 'aguardando_local_manual_estado', $contexto);
        return "Entendido. E em qual *cidade* fica o *{$nomeMercado}*?";
    }
    
    /**
     * PASSO 3 (Fluxo Manual - Estado/Final):
     * O utilizador está no fluxo de registo manual.
     */
    private function handleLocalManualEstado(string $respostaUsuario, array $contexto): string
    {
        $cidade = trim(strip_tags($respostaUsuario));
         if (empty($cidade) || strlen($cidade) > 50) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_estado', $contexto);
            return "Por favor, envia um nome de cidade válido.";
        }
        
        $contexto['cidade'] = $cidade;
        
        // (Podes adicionar um passo para pedir o estado se quiseres, por ex: "Mirassol SP")
        // (Por agora, vamos simplificar)
        $estado = "N/A"; 
        if (preg_match('/([\w\s]+) (\w{2})/', $cidade, $matches)) {
            $cidade = trim($matches[1]);
            $estado = strtoupper($matches[2]);
        }
        
        $nomeMercado = $contexto['nome_mercado'];
        
        // Tenta encontrar este mercado manual
        $estabelecimento = Estabelecimento::findByManualEntry($this->pdo, $nomeMercado, $cidade, $estado);
        if (!$estabelecimento) {
            // Cria
            $estabelecimento = Estabelecimento::createManual($this->pdo, $nomeMercado, $cidade, $estado);
        }

        // Vai para o Passo 4 (Escolha de Lista)
        return $this->iniciarFluxoDeLista($estabelecimento);
    }
    
    /**
     * PASSO 4 (Final): Iniciar Fluxo de Lista
     * (Chamado por todos os fluxos de seleção de local)
     */
    private function iniciarFluxoDeLista(Estabelecimento $estabelecimento): string
    {
        // Cria a compra!
        $novaCompra = Compra::create($this->pdo, $this->usuario->id, $estabelecimento->id);
        $this->usuario->clearState($this->pdo); // Limpa o estado *antes* de verificar as listas
        
        // Verifica se o utilizador tem listas
        $listas = ListaCompra::findAllByUser($this->pdo, $this->usuario->id);
        if (empty($listas)) {
            // Não tem listas, vai direto para o registo
            return "Compra iniciada no *{$estabelecimento->nome}*! ✅\n\nAgora é só enviares os teus itens. (Ex: *2x Leite 5,00*)";
        }
        
        // Tem listas! Pergunta se quer usar uma
        $resposta = "Compra iniciada no *{$estabelecimento->nome}*! ✅\n\nQueres usar uma das tuas listas de compras? (Envia só o *número*)\n";
        $contexto = [];
        
        $opcoes = 1;
        $contexto[$opcoes] = 'nenhuma';
        $resposta .= "\n*$opcoes* - Não, obrigado (compra livre)";
        $opcoes++;

        foreach ($listas as $lista) {
            $resposta .= "\n*$opcoes* - " . htmlspecialchars($lista['nome']);
            $contexto[$opcoes] = $lista['id']; // Mapeia 2 => ID_da_lista_X
            $opcoes++;
        }
        
        // Coloca o utilizador num estado final (mas com a compra já ativa)
        $this->usuario->updateState($this->pdo, 'aguardando_lista_para_iniciar', $contexto);
        return $resposta;
    }
    
    /**
     * PASSO 5 (Opcional): Utilizador escolhe uma lista
     * (O utilizador está a escolher se usa uma lista ou não)
     */
    private function handleEscolhaDeLista(string $respostaUsuario, array $contexto): string
    {
        $escolha = trim($respostaUsuario);
        
        // 1. Escolheu "Não, obrigado" (ou algo inválido)
        if (!is_numeric($escolha) || !isset($contexto[$escolha]) || $contexto[$escolha] === 'nenhuma') {
            $this->usuario->clearState($this->pdo);
            return "Entendido! 👍\n\nEstou pronto para registar os teus itens. (Ex: *2x Leite 5,00*)";
        }
        
        // 2. Escolheu uma lista
        $listaId = (int)$contexto[$escolha];
        $itens = ListaCompra::findItemsByListId($this->pdo, $listaId);
        
        if (empty($itens)) {
            $this->usuario->clearState($this->pdo);
            return "Essa lista está vazia. 😕\n\nMas não há problema, estou pronto para registar os teus itens. (Ex: *2x Leite 5,00*)";
        }
        
        // TEM ITENS! VAMOS COMPARAR OS PREÇOS
        $this->usuario->clearState($this->pdo);
        $nomesNormalizados = array_map(fn($item) => StringUtils::normalize($item['produto_nome']), $itens);
        
        // Precisamos da cidade (a compra já foi criada e está ativa)
        $compraAtiva = Compra::findActiveByUser($this->pdo, $this->usuario->id);
        $est = Estabelecimento::findById($this->pdo, $compraAtiva->estabelecimento_id);
        
        $precos = HistoricoPreco::findPricesForListInCity($this->pdo, $est->cidade, $nomesNormalizados, 30);
        
        // Formata os preços num mapa para fácil acesso (nome_normalizado => preco_minimo)
        $mapaPrecos = [];
        foreach ($precos as $p) {
            $mapaPrecos[$p['produto_nome_normalizado']] = $p;
        }

        $resposta = "Aqui está a tua lista com a *comparação de preços* em *{$est->cidade}*:\n";
        
        foreach ($itens as $item) {
            $nomeItem = htmlspecialchars($item['produto_nome']);
            $nomeNorm = StringUtils::normalize($nomeItem);
            
            $resposta .= "\n\n🛒 *{$nomeItem}*";
            
            if (isset($mapaPrecos[$nomeNorm])) {
                $dadosPreco = $mapaPrecos[$nomeNorm];
                $precoFmt = number_format((float)$dadosPreco['preco_minimo'], 2, ',', '.');
                $dataFmt = (new \DateTime($dadosPreco['data_mais_recente']))->format('d/m');
                $resposta .= "\n  💰 *R$ {$precoFmt}* (Visto em {$dadosPreco['estabelecimento_nome']} no dia {$dataFmt})";
            } else {
                $resposta .= "\n  _(Sem preço recente em {$est->cidade})_";
            }
        }
        
        $resposta .= "\n\nEstou pronto para registar os itens que comprares!";
        return $resposta;
    }
}
?>