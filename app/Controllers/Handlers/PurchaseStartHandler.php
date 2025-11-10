<?php
// ---
// /app/Controllers/Handlers/PurchaseStartHandler.php
// (VERSÃO COM NAMESPACE)
// ---

// 1. Define o Namespace
namespace App\Controllers\Handlers;

// 2. Importa dependências
use App\Models\Compra;
use App\Models\Estabelecimento;
use App\Models\HistoricoPreco;
use App\Models\ListaCompra;
use App\Services\GooglePlacesService; // (O serviço que este handler precisa)
use App\Utils\StringUtils;
// (BaseHandler está no mesmo namespace)

/**
 * Gere TODO o fluxo de conversa para INICIAR uma nova compra,
 * seja manualmente ou usando uma lista inteligente.
 */
class PurchaseStartHandler extends BaseHandler { // (Funciona)

    /**
     * Ponto de entrada.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // Se o estado for 'inicio_start', é um novo comando "iniciar compra"
        if ($estado === 'inicio_start') {
            return $this->handleInicioCompra();
        }
        
        // Continua uma conversa existente
        switch ($estado) {
            case 'aguardando_local_manual_cidade':
                return $this->handleLocalManualCidade($respostaUsuario, $contexto);
            
            case 'aguardando_local_manual_estado':
                 return $this->handleLocalManualEstado($respostaUsuario, $contexto);
            
            case 'aguardando_local_google':
                 return $this->handleLocalGoogle($respostaUsuario, $contexto);
            
            case 'aguardando_lista_para_iniciar':
                 return $this->handleEscolhaDeLista($respostaUsuario, $contexto);
                 
            default:
                $this->usuario->clearState($this->pdo);
                return "Opa! 🤔 Parece que me perdi no início da tua compra. Vamos recomeçar. Envia *iniciar compra* novamente.";
        }
    }
    
    /**
     * Estado: inicio_start
     * (O utilizador acabou de enviar "iniciar compra")
     */
    private function handleInicioCompra(): string
    {
        // Tenta encontrar a última localização
        $ultimoLocal = Compra::findLastCompletedByUser($this->pdo, $this->usuario->id);
        
        $resposta = "Vamos começar! 🛍️\n\nOnde estás a fazer compras hoje?\n";
        $contexto = [];
        $opcoes = 1;

        if ($ultimoLocal) {
            $nomeLocal = htmlspecialchars($ultimoLocal['estabelecimento_nome']);
            $cidadeLocal = htmlspecialchars($ultimoLocal['cidade']);
            $resposta .= "\n*$opcoes* - {$nomeLocal} ({$cidadeLocal})";
            
            // Mapeia a opção 1 para o ID do estabelecimento
            $contexto[$opcoes] = $ultimoLocal['estabelecimento_id'];
            $opcoes++;
        }
        
        $resposta .= "\n*$opcoes* - 🔎 Pesquisar (Google Places)";
        $contexto['acao_google'] = $opcoes; // Mapeia a ação de pesquisa
        $opcoes++;
        
        $resposta .= "\n*$opcoes* - Manual (Digitar cidade/estado)";
        $contexto['acao_manual'] = $opcoes; // Mapeia a ação manual
        
        // Guarda o mapa de opções no contexto
        $this->usuario->updateState($this->pdo, 'aguardando_local_google', $contexto);
        return $resposta;
    }
    
    /**
     * Estado: aguardando_local_google
     * (O utilizador vê as opções de local)
     */
    private function handleLocalGoogle(string $respostaUsuario, array $contexto): string
    {
        $escolha = trim($respostaUsuario);
        
        // 1. O utilizador escolheu o local anterior (Ex: Opção "1")
        if (is_numeric($escolha) && isset($contexto[$escolha])) {
            $idEstabelecimento = (int)$contexto[$escolha];
            $estabelecimento = Estabelecimento::findById($this->pdo, $idEstabelecimento);
            
            if ($estabelecimento) {
                // Vai para o Passo 3 (Escolha de Lista)
                return $this->iniciarFluxoDeLista($estabelecimento);
            }
        }
        
        // 2. O utilizador escolheu "Manual"
        if (isset($contexto['acao_manual']) && $escolha == $contexto['acao_manual']) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', ['nome_mercado' => 'Manual']);
            return "Entendido. Qual o *nome* do mercado?";
        }

        // 3. O utilizador escolheu "Pesquisar" (ou digitou o nome)
        $nomeMercado = $respostaUsuario;
        if (isset($contexto['acao_google']) && $escolha == $contexto['acao_google']) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', ['nome_mercado' => 'Google']);
            return "Certo. Qual o *nome do mercado* que queres pesquisar?";
        }
        
        // Se chegou aqui, o utilizador digitou o nome do mercado diretamente (fluxo de pesquisa)
        
        // Precisamos da cidade/estado do utilizador para pesquisar
        $ultimoLocal = Compra::findLastCompletedByUser($this->pdo, $this->usuario->id);
        if (!$ultimoLocal) {
            // Se não temos local, força o fluxo manual
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', ['nome_mercado' => 'Manual']);
             return "Não encontrei essa opção. 😕\nComo é a tua primeira compra, vamos registar manualmente.\n\nQual o *nome* do mercado?";
        }
        $cidadeEstado = $ultimoLocal['cidade'] . " - " . $ultimoLocal['estado'];

        // USA O SERVIÇO GOOGLE PLACES
        $google = new GooglePlacesService();
        $locais = $google->buscarLocais($nomeMercado, $cidadeEstado);
        
        if (empty($locais)) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', ['nome_mercado' => 'Manual']);
            return "Não encontrei *{$nomeMercado}* no Google. 📍\nVamos registar manualmente. Qual o *nome* do mercado?";
        }

        $resposta = "Encontrei estes locais. Qual é o correto? (Envia só o *número*)\n";
        $novoContexto = [];
        foreach ($locais as $i => $local) {
            $resposta .= "\n*" . ($i + 1) . "* - " . htmlspecialchars($local['nome_google']);
            $resposta .= "\n  _" . htmlspecialchars($local['endereco']) . "_";
            // Guarda os dados do Google no contexto
            $novoContexto[$i + 1] = $local; 
        }
        $novoContexto['acao_manual'] = count($locais) + 1;
        $resposta .= "\n*" . $novoContexto['acao_manual'] . "* - Nenhum destes (Registar manualmente)";

        $this->usuario->updateState($this->pdo, 'aguardando_local_google_confirmacao', $novoContexto);
        return $resposta;
    }
    
    /**
     * Estado: aguardando_local_google_confirmacao
     * (O utilizador está a ver os resultados da pesquisa Google)
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
                 // TODO: Extrair cidade/estado do 'endereco' (Isto é complexo)
                 // Por agora, vamos usar a cidade/estado da última compra
                 $ultimoLocal = Compra::findLastCompletedByUser($this->pdo, $this->usuario->id);
                 $cidade = $ultimoLocal['cidade'] ?? 'N/A';
                 $estado = $ultimoLocal['estado'] ?? 'N/A';
                 
                 $estabelecimento = Estabelecimento::createFromGoogle(
                     $this->pdo, 
                     $localEscolhido['place_id'], 
                     $localEscolhido['nome_google'], 
                     $cidade, 
                     $estado
                 );
             }
             
             // Vai para o Passo 3 (Escolha de Lista)
             return $this->iniciarFluxoDeLista($estabelecimento);
         }
         
         // 3. Não entendeu
         $this->usuario->clearState($this->pdo);
         return "Não entendi a tua escolha. 😕 Vamos recomeçar. Envia *iniciar compra*.";
    }

    /**
     * Estado: aguardando_local_manual_cidade
     * (O utilizador está no fluxo de registo manual)
     */
    private function handleLocalManualCidade(string $respostaUsuario, array $contexto): string
    {
        $nomeMercado = trim(strip_tags($respostaUsuario));
        if (empty($nomeMercado) || strlen($nomeMercado) > 100) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', $contexto);
            return "Por favor, envia um nome válido para o mercado.";
        }
        
        $contexto['nome_mercado'] = $nomeMercado;
        $this->usuario->updateState($this->pdo, 'aguardando_local_manual_estado', $contexto);
        return "Entendido. E em qual *cidade* fica o *{$nomeMercado}*?";
    }
    
    /**
     * Estado: aguardando_local_manual_estado
     * (O utilizador está no fluxo de registo manual)
     */
    private function handleLocalManualEstado(string $respostaUsuario, array $contexto): string
    {
        $cidade = trim(strip_tags($respostaUsuario));
         if (empty($cidade) || strlen($cidade) > 50) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_estado', $contexto);
            return "Por favor, envia um nome de cidade válido.";
        }
        
        $contexto['cidade'] = $cidade;
        
        // (Simples, para o fluxo não ficar muito longo)
        // TODO: Pedir o estado (ex: SP, PR)
        $estado = "N/A"; 
        
        $nomeMercado = $contexto['nome_mercado'];
        
        // Tenta encontrar este mercado manual
        $estabelecimento = Estabelecimento::findByManualEntry($this->pdo, $nomeMercado, $cidade, $estado);
        if (!$estabelecimento) {
            // Cria
            $estabelecimento = Estabelecimento::createManual($this->pdo, $nomeMercado, $cidade, $estado);
        }

        // Vai para o Passo 3 (Escolha de Lista)
        return $this->iniciarFluxoDeLista($estabelecimento);
    }
    
    /**
     * PASSO 3 (Final): Iniciar Fluxo de Lista
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
     * Estado: aguardando_lista_para_iniciar
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
        
        // Precisamos da cidade
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