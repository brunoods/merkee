<?php
// ---
// /app/Controllers/Handlers/PurchaseStartHandler.php
// (NOVO FICHEIRO)
// ---

require_once __DIR__ . '/BaseHandler.php'; // O "molde"

// Modelos e Serviços que este fluxo precisa
require_once __DIR__ . '/../../Models/Compra.php';
require_once __DIR__ . '/../../Models/Estabelecimento.php';
require_once __DIR__ . '/../../Models/HistoricoPreco.php';
require_once __DIR__ . '/../../Models/ListaCompra.php';
require_once __DIR__ . '/../../Services/GooglePlacesService.php';

/**
 * Gere TODO o fluxo de conversa para INICIAR uma nova compra,
 * seja manualmente ou usando uma lista inteligente.
 */
class PurchaseStartHandler extends BaseHandler {

    /**
     * Ponto de entrada. O BotController chama este método.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // O "switch" que estava no BotController, agora vive aqui.
        switch ($estado) {
            
            // --- FLUXO DE INÍCIO DE COMPRA (MANUAL) ---
            case 'aguardando_confirmacao_ultimo_mercado':
                return $this->handleConfirmacaoUltimoMercado($respostaUsuario, $contexto);
            
            case 'aguardando_nome_mercado':
                return $this->handleNomeMercado($respostaUsuario, $contexto);

            case 'aguardando_cidade_estado':
                return $this->handleCidadeEstado($respostaUsuario, $contexto);

            case 'aguardando_confirmacao_mercado':
                return $this->handleConfirmacaoMercadoGoogle($respostaUsuario, $contexto);

            // --- FLUXO DE INÍCIO (ESCOLHA) ---
            case 'aguardando_tipo_inicio':
                return $this->handleTipoInicio($respostaUsuario, $contexto);

            // --- FLUXO DE INÍCIO (COM LISTA) ---
            case 'aguardando_lista_para_analise':
                return $this->handleEscolhaListaParaAnalise($respostaUsuario, $contexto);
            
            case 'aguardando_mercado_da_lista':
                return $this->handleEscolhaMercadoDaLista($respostaUsuario, $contexto);

            default:
                // Segurança
                $this->usuario->clearState($this->pdo);
                return "Ops, me perdi um pouco (Handler de Início). Vamos recomeçar.";
        }
    }

    // --- (LÓGICA MOVIDA DIRETAMENTE DO BotController) ---
    // Repara como as funções startManualFlow, handleShowLists, e handleListAnalysis
    // foram movidas para aqui e tornaram-se 'private'.

    /**
     * Lógica do estado: aguardando_tipo_inicio
     */
    private function handleTipoInicio(string $respostaUsuario, array $contexto): string
    {
        $respostaLimpa = trim($respostaUsuario);
        if ($respostaLimpa === '1') { // "1. Usar lista"
            return $this->handleShowLists('aguardando_lista_para_analise', "Qual lista queres usar para a tua compra?\n\n(Digite o *número* da lista)");
        } elseif ($respostaLimpa === '2') { // "2. Registar manualmente"
            return $this->startManualFlow(); // Inicia o fluxo de pedir mercado
        } elseif ($respostaLimpa === '3') { // "3. Ver minhas listas"
            // Mostra as listas mas volta para este mesmo estado
            return $this->handleShowLists('aguardando_tipo_inicio', "\nO que queres fazer?\n*1.* Usar lista\n*2.* Registar manualmente\n*3.* Ver listas");
        } else {
            return "Não entendi 😕. Por favor, digite *1*, *2* ou *3*.";
        }
    }

    /**
     * Lógica do estado: aguardando_confirmacao_ultimo_mercado
     */
    private function handleConfirmacaoUltimoMercado(string $respostaUsuario, array $contexto): string
    {
        $respostaLimpa = trim(strtolower($respostaUsuario));
        if ($respostaLimpa === 'sim' || $respostaLimpa === 's') {
            $estId = $contexto['ultimo_est_id'];
            $estNome = $contexto['ultimo_est_nome'];
            Compra::create($this->pdo, $this->usuario->id, $estId);
            $this->usuario->clearState($this->pdo);
            return "Perfeito! Compra iniciada no *$estNome*. ✅\n\nAgora é só me enviar os produtos...";
        } elseif ($respostaLimpa === 'nao' || $respostaLimpa === 'n' || $respostaLimpa === 'não') {
            $this->usuario->updateState($this->pdo, 'aguardando_nome_mercado');
            return "Sem problemas. Em qual *estabelecimento* você está agora?";
        } else {
            return "Não entendi 😕. Responda apenas *sim* ou *nao*.\nVocê está no *{$contexto['ultimo_est_nome']}* novamente?";
        }
    }
    
    /**
     * Lógica do estado: aguardando_nome_mercado
     */
    private function handleNomeMercado(string $respostaUsuario, array $contexto): string
    {
        $nomeMercado = trim($respostaUsuario);
        $this->usuario->updateState($this->pdo, 'aguardando_cidade_estado', ['nome_mercado' => $nomeMercado]);
        return "Entendido: *$nomeMercado*.\n\nAgora, por favor, me diga a *cidade e o estado*.\nExemplo: *Mirassol SP*";
    }

    /**
     * Lógica do estado: aguardando_cidade_estado
     */
    private function handleCidadeEstado(string $respostaUsuario, array $contexto): string
    {
        $partes = preg_split('/\s+/', trim($respostaUsuario)); 
        if (count($partes) < 2) {
            return "Formato inválido. 😕\nEnvie no formato *Cidade UF* (separado por espaço).\n\nExemplo: *Mirassol SP*";
        }
        $estado = trim(strtoupper(array_pop($partes)));
        $cidade = trim(implode(' ', $partes));
        if (strlen($estado) !== 2 || empty($cidade)) {
            return "Formato inválido. 😕\nO estado (UF) deve ter 2 letras e a cidade não pode estar vazia.\n\nExemplo: *Mirassol SP*";
        }
        $nomeMercado = $contexto['nome_mercado'];
        $googleService = new GooglePlacesService();
        $locais = $googleService->buscarLocais($nomeMercado, $cidade . ' - ' . $estado); 
        $contexto['input_cidade'] = $cidade;
        $contexto['input_estado'] = $estado;
        if (empty($locais)) {
            // Se o Google não achou, vai para o fluxo manual (que neste handler já é o default)
            // A função startManualFlow() foi fundida aqui.
            $est = Estabelecimento::findByManualEntry($this->pdo, $nomeMercado, $cidade, $estado);
            if (!$est) {
                $est = Estabelecimento::createManual($this->pdo, $nomeMercado, $cidade, $estado);
            }
            Compra::create($this->pdo, $this->usuario->id, $est->id);
            $this->usuario->clearState($this->pdo);
            return "Não encontrei esse local no Google, mas registei manualmente.\n\nCompra iniciada no *$est->nome* ($est->cidade/$est->estado). ✅\n\nAgora é só me enviar os produtos.";
        }
        $resposta = "Encontrei estes locais. Qual é o correto?\n\n";
        $contexto['google_results'] = []; 
        $i = 1;
        foreach ($locais as $local) {
            $resposta .= "*$i)* {$local['nome_google']} ({$local['endereco']})\n";
            $contexto['google_results'][$i] = $local;
            $i++;
        }
        $resposta .= "\nDigite o número, ou envie *N* se não for nenhum destes.";
        $this->usuario->updateState($this->pdo, 'aguardando_confirmacao_mercado', $contexto);
        return $resposta;
    }

    /**
     * Lógica do estado: aguardando_confirmacao_mercado
     */
    private function handleConfirmacaoMercadoGoogle(string $respostaUsuario, array $contexto): string
    {
        $respostaLimpa = trim(strtolower($respostaUsuario));
        if ($respostaLimpa === 'n' || $respostaLimpa === 'nenhum') {
            // O usuário não gostou dos resultados do Google, regista manualmente
            $nomeMercado = $contexto['nome_mercado'];
            $cidade = $contexto['input_cidade'];
            $estado = $contexto['input_estado'];
            
            $est = Estabelecimento::findByManualEntry($this->pdo, $nomeMercado, $cidade, $estado);
            if (!$est) {
                $est = Estabelecimento::createManual($this->pdo, $nomeMercado, $cidade, $estado);
            }
            Compra::create($this->pdo, $this->usuario->id, $est->id);
            $this->usuario->clearState($this->pdo);
            return "Ok, registei manualmente.\n\nCompra iniciada no *$est->nome* ($est->cidade/$est->estado). ✅\n\nAgora é só me enviar os produtos.";
        }

        if (is_numeric($respostaLimpa) && isset($contexto['google_results'][(int)$respostaLimpa])) {
            // O usuário escolheu uma opção do Google
            $localEscolhido = $contexto['google_results'][(int)$respostaLimpa];
            $est = Estabelecimento::findByPlaceId($this->pdo, $localEscolhido['place_id']);
            if (!$est) {
                $est = Estabelecimento::createFromGoogle($this->pdo, $localEscolhido['place_id'], $localEscolhido['nome_google'], $contexto['input_cidade'], $contexto['input_estado']);
            }
            Compra::create($this->pdo, $this->usuario->id, $est->id);
            $this->usuario->clearState($this->pdo);
            return "Perfeito! Compra iniciada no *$est->nome* ($est->cidade/$est->estado). ✅\n\nAgora é só me enviar os produtos no formato:\n*produto / quantidade / preço*";
        } else {
            return "Não entendi. 😕 Por favor, digite o *número* da opção correta, ou *N* se não for nenhuma delas.";
        }
    }

    /**
     * Lógica do estado: aguardando_lista_para_analise
     */
    private function handleEscolhaListaParaAnalise(string $respostaUsuario, array $contexto): string
    {
        $listas = $contexto['listas_para_escolha'] ?? [];
        $respostaLimpa = trim($respostaUsuario);

        if (is_numeric($respostaLimpa) && isset($listas[(int)$respostaLimpa])) {
            $listaEscolhida = $listas[(int)$respostaLimpa];
            
            // A "MAGIA"! Chama o helper de análise
            return $this->handleListAnalysis($listaEscolhida['id'], $listaEscolhida['nome']);

        } else {
            return "Opção inválida. 😕 Por favor, digite o *número* da lista que queres usar, ou *cancelar*.";
        }
    }

    /**
     * Lógica do estado: aguardando_mercado_da_lista
     */
    private function handleEscolhaMercadoDaLista(string $respostaUsuario, array $contexto): string
    {
        $mercados = $contexto['mercados_analisados'] ?? [];
        $respostaLimpa = trim($respostaUsuario);

        if (is_numeric($respostaLimpa) && isset($mercados[(int)$respostaLimpa])) {
            $mercadoEscolhido = $mercados[(int)$respostaLimpa];
            
            // Inicia a compra!
            Compra::create($this->pdo, $this->usuario->id, $mercadoEscolhido['id']);
            $this->usuario->clearState($this->pdo);
            return "Perfeito! Compra iniciada no *{$mercadoEscolhido['nome']}*. ✅\n\nAgora é só me enviar os produtos...";
        } else {
            return "Opção inválida. 😕 Por favor, digite o *número* do mercado onde vais comprar, ou *cancelar*.";
        }
    }


    // --- (FUNÇÕES HELPER MOVIDAS DO BotController) ---

    /**
     * (HELPER) Inicia o fluxo manual (perguntando do último mercado ou nome)
     */
    private function startManualFlow(): string
    {
        $ultimoLocal = Compra::findLastCompletedByUser($this->pdo, $this->usuario->id);
        if ($ultimoLocal) {
            $this->usuario->updateState(
                $this->pdo, 
                'aguardando_confirmacao_ultimo_mercado',
                [
                    'ultimo_est_id' => $ultimoLocal['estabelecimento_id'],
                    'ultimo_est_nome' => $ultimoLocal['estabelecimento_nome']
                ]
            );
            return "Ok, vamos registar manualmente.\n\nNotei que a tua última compra foi no *{$ultimoLocal['estabelecimento_nome']}*.\n\nVocê está lá novamente? (sim / nao)";
        
        } else {
            // Utilizador novo.
            $this->usuario->updateState($this->pdo, 'aguardando_nome_mercado');
            return "Ok, vamos registar manualmente.\n\nEm qual *estabelecimento* você está?\n\n(Digite *cancelar* para parar)";
        }
    }

    /**
     * (HELPER) Mostra as listas do usuário
     */
    private function handleShowLists(string $nextState, string $footerMessage): string
    {
        $listas = ListaCompra::findAllByUser($this->pdo, $this->usuario->id);
        if (empty($listas)) {
            $this->usuario->clearState($this->pdo); // Limpa o estado se não há listas
            return "Ainda não tens listas de compras guardadas. 😕\n\nCria uma com o comando `criar lista`.";
        }
        
        $resposta = "Aqui estão as tuas listas guardadas:\n\n";
        $contextoListas = [];
        $i = 1;
        foreach ($listas as $lista) {
            $resposta .= "*{$i})* {$lista->nome_lista}\n";
            $contextoListas[$i] = ['id' => $lista->id, 'nome' => $lista->nome_lista];
            $i++;
        }

        $this->usuario->updateState($this->pdo, $nextState, ['listas_para_escolha' => $contextoListas]);
        $resposta .= "\n" . $footerMessage;
        return $resposta;
    }

    /**
     * (HELPER) A "MAGIA" - Analisa uma lista de compras (Consulta Única Otimizada)
     */
    private function handleListAnalysis(int $lista_id, string $lista_nome): string
    {
        // 1. Descobre a cidade do usuário
        $ultimoLocal = Compra::findLastCompletedByUser($this->pdo, $this->usuario->id);
        if (!$ultimoLocal || empty($ultimoLocal['cidade'])) {
            $this->usuario->clearState($this->pdo);
            return "Não consegui identificar a tua cidade para comparar os preços. 😥\nPor favor, *inicia uma compra manualmente* primeiro (podes cancelar logo a seguir) para que eu possa registar a tua localização.";
        }
        $cidadeUsuario = $ultimoLocal['cidade'];

        // 2. Busca os itens da lista
        $itensLista = ListaCompra::findItemsByListId($this->pdo, $lista_id);
        if (empty($itensLista)) {
            $this->usuario->clearState($this->pdo);
            return "A tua lista '*{$lista_nome}*' está vazia! 😕\nAdiciona itens a ela ou escolhe outra lista.";
        }

        $resposta = "A analisar a lista '*{$lista_nome}*' em *{$cidadeUsuario}*... 📊\n\n";
        
        // 3. Extrai apenas os nomes normalizados para a consulta
        $nomesNormalizados = [];
        foreach ($itensLista as $item) {
            $nomesNormalizados[] = $item['produto_nome_normalizado'];
        }
        
        // 4. FAZ A CONSULTA ÚNICA!
        $precosTodoMercado = HistoricoPreco::findPricesForListInCity(
            $this->pdo, $cidadeUsuario, $nomesNormalizados, 15 // Busca 15 dias
        );

        if (empty($precosTodoMercado)) {
            $this->usuario->clearState($this->pdo);
            return "Que pena! 😥 Nenhum dos itens da tua lista '*{$lista_nome}*' foi encontrado em registos recentes na tua cidade.";
        }

        // 5. Processa os resultados (em memória)
        $mercadoScores = []; // [ 'est_id' => ['id' => 1, 'nome' => 'Proença', 'total' => 0, 'itens_encontrados' => 0] ]
        foreach ($precosTodoMercado as $registoPreco) {
            $estId = $registoPreco['est_id'];
            if (!isset($mercadoScores[$estId])) {
                $mercadoScores[$estId] = [
                    'id' => $estId,
                    'nome' => $registoPreco['estabelecimento_nome'],
                    'total' => 0.0,
                    'itens_encontrados' => 0
                ];
            }
            $mercadoScores[$estId]['total'] += (float)$registoPreco['preco_minimo'];
            $mercadoScores[$estId]['itens_encontrados']++;
        }
        
        if (empty($mercadoScores)) {
            $this->usuario->clearState($this->pdo);
            return "Que pena! 😥 Nenhum dos itens da tua lista '*{$lista_nome}*' foi encontrado em registos recentes na tua cidade.";
        }

        // 6. Ordena
        usort($mercadoScores, function($a, $b) {
            return $a['total'] <=> $b['total'];
        });

        // 7. Monta a resposta
        $resposta .= "Aqui está a tua análise de preços:\n\n";
        $contextoMercados = [];
        $i = 1;
        foreach ($mercadoScores as $mercado) {
            $totalFmt = number_format($mercado['total'], 2, ',', '.');
            $resposta .= "*{$i}) {$mercado['nome']}*\n";
            $resposta .= "   💰 *Total Estimado:* R$ {$totalFmt}\n";
            $resposta .= "   🛒 *Itens Encontrados:* {$mercado['itens_encontrados']} de " . count($itensLista) . "\n\n";
            $contextoMercados[$i] = ['id' => $mercado['id'], 'nome' => $mercado['nome']];
            $i++;
        }
        
        $resposta .= "Onde queres iniciar a tua compra?\n(Digite o *número* do mercado ou *cancelar*)";
        $this->usuario->updateState($this->pdo, 'aguardando_mercado_da_lista', ['mercados_analisados' => $contextoMercados]);
        return $resposta;
    }
}
?>