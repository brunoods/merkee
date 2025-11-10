<?php
// ---
// /app/Controllers/BotController.php
// (VERSÃO COM ROTA PARA 'aguardando_nome')
// ---

// Models e Serviços essenciais que o BotController ainda usa
require_once __DIR__ . '/../Models/Compra.php';
require_once __DIR__ . '/../Models/Estabelecimento.php';
require_once __DIR__ . '/../Models/HistoricoPreco.php';
require_once __DIR__ . '/../Utils/StringUtils.php';
require_once __DIR__ . '/../Models/ListaCompra.php';
require_once __DIR__ . '/../Services/ItemParserService.php';
require_once __DIR__ . '/../Services/ParsedItemDTO.php';

// Handlers
require_once __DIR__ . '/Handlers/ListHandler.php';
require_once __DIR__ . '/Handlers/ConfigHandler.php';
require_once __DIR__ . '/Handlers/PurchaseStartHandler.php';
require_once __DIR__ . '/Handlers/CronFinalizeHandler.php';
require_once __DIR__ . '/../Services/CompraReportService.php';

// --- (INÍCIO DA ATUALIZAÇÃO) ---
// 1. Incluímos o nosso novo OnboardingHandler
require_once __DIR__ . '/Handlers/OnboardingHandler.php';
// --- (FIM DA ATUALIZAÇÃO) ---


class BotController {

    private PDO $pdo;
    private Usuario $usuario;
    private ?Compra $compraAtiva;
    private const TIMEOUT_MINUTOS = 10;

    // Propriedades para cachear os Handlers
    private ?ListHandler $listHandler = null;
    private ?ConfigHandler $configHandler = null;
    private ?PurchaseStartHandler $purchaseStartHandler = null;
    private ?CronFinalizeHandler $cronFinalizeHandler = null;
    
    // --- (INÍCIO DA ATUALIZAÇÃO) ---
    // 2. Criamos a propriedade para o novo handler
    private ?OnboardingHandler $onboardingHandler = null;
    // --- (FIM DA ATUALIZAÇÃO) ---
    

    public function __construct(PDO $pdo, Usuario $usuario, ?Compra $compraAtiva) {
        $this->pdo = $pdo;
        $this->usuario = $usuario;
        $this->compraAtiva = $compraAtiva;
    }

    // --- (Getters para os Handlers) ---
    private function getListHandler(): ListHandler {
        if ($this->listHandler === null) {
            $this->listHandler = new ListHandler($this->pdo, $this->usuario);
        }
        return $this->listHandler;
    }
    
    private function getConfigHandler(): ConfigHandler {
        if ($this->configHandler === null) {
            $this->configHandler = new ConfigHandler($this->pdo, $this->usuario);
        }
        return $this->configHandler;
    }
    
    private function getPurchaseStartHandler(): PurchaseStartHandler {
        if ($this->purchaseStartHandler === null) {
            $this->purchaseStartHandler = new PurchaseStartHandler($this->pdo, $this->usuario);
        }
        return $this->purchaseStartHandler;
    }

    private function getCronFinalizeHandler(): CronFinalizeHandler {
        if ($this->cronFinalizeHandler === null) {
            $this->cronFinalizeHandler = new CronFinalizeHandler($this->pdo, $this->usuario);
        }
        return $this->cronFinalizeHandler;
    }

    // --- (INÍCIO DA ATUALIZAÇÃO) ---
    // 3. Criamos o "Getter" para o OnboardingHandler
    private function getOnboardingHandler(): OnboardingHandler {
        if ($this->onboardingHandler === null) {
            $this->onboardingHandler = new OnboardingHandler($this->pdo, $this->usuario);
        }
        return $this->onboardingHandler;
    }
    // --- (FIM DA ATUALIZAÇÃO) ---


    public function processMessage(string $messageText): string 
    {
        $comando = trim(strtolower($messageText));
        
        // Lógica de timeout
        if ($this->usuario->conversa_estado && $this->usuario->conversa_estado_iniciado_em) {
            if ($this->usuario->conversa_estado !== 'aguardando_confirmacao_finalizacao') {
                $tempoInicio = strtotime($this->usuario->conversa_estado_iniciado_em);
                $agora = time();
                $minutosPassados = ($agora - $tempoInicio) / 60;
                if ($minutosPassados > self::TIMEOUT_MINUTOS) {
                    $this->usuario->clearState($this->pdo);
                }
            }
        }
        
        // Se está num estado, processa a conversa
        if ($this->usuario->conversa_estado) {
            if ($comando === 'cancelar') {
                $this->usuario->clearState($this->pdo);
                return "Ok, processo cancelado. 👍";
            }
            return $this->handleStatefulConversation($comando);
        }
        
        // Se não está num estado, verifica se tem compra ativa ou não
        if ($this->compraAtiva) {
            return $this->processStateWithPurchase($comando);
        } else {
            return $this->processStateWithoutPurchase($comando);
        }
    }


    /**
     * Lida com todas as conversas que dependem de um estado (multi-passos)
     */
    private function handleStatefulConversation(string $respostaUsuario): string
    {
        $estado = $this->usuario->conversa_estado;
        $contexto = $this->usuario->conversa_contexto ?? [];
        
        // --- (O ROTEADOR) ---
        
        // Estados de GESTÃO DE LISTAS
        if (in_array($estado, ['aguardando_nome_lista', 'adicionando_itens_lista', 'aguardando_lista_para_apagar'])) {
            return $this->getListHandler()->process($estado, $respostaUsuario, $contexto);
        }

        // Estados de CONFIGURAÇÃO
        if (in_array($estado, ['aguardando_configuracao'])) {
            return $this->getConfigHandler()->process($estado, $respostaUsuario, $contexto);
        }

        // Estados de INÍCIO DE COMPRA
        $purchaseStartStates = [
            'aguardando_confirmacao_ultimo_mercado', 'aguardando_nome_mercado',
            'aguardando_cidade_estado', 'aguardando_confirmacao_mercado',
            'aguardando_tipo_inicio', 'aguardando_lista_para_analise',
            'aguardando_mercado_da_lista'
        ];
        if (in_array($estado, $purchaseStartStates)) {
            return $this->getPurchaseStartHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        // Estado de FINALIZAÇÃO (CRON)
        if (in_array($estado, ['aguardando_confirmacao_finalizacao'])) {
            return $this->getCronFinalizeHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        // --- (INÍCIO DA ATUALIZAÇÃO) ---
        // 4. (NOVO!) Estado de ONBOARDING (agora inclui o pedido de nome)
        $onboardingStates = [
            'aguardando_nome_para_onboarding', // <-- O NOVO ESTADO
            'aguardando_decisao_onboarding',
            'onboarding_registrar_1',
            'onboarding_listas_1'
        ];
        if (in_array($estado, $onboardingStates)) {
            return $this->getOnboardingHandler()->process($estado, $respostaUsuario, $contexto);
        }
        // --- (FIM DA ATUALIZAÇÃO) ---
        

        $this->usuario->clearState($this->pdo);
        return "Ops, algo estranho aconteceu (estado desconhecido: '{$estado}'). Vamos tentar de novo.";
    }


    /**
     * Lógica principal quando o usuário NÃO TEM compra ativa
     * (Função "gatilho" de comandos)
     */
    private function processStateWithoutPurchase(string $comando): string {
        
        // (Lógica de "Pesquisar" - continua aqui, pois não é "stateful")
        if (str_starts_with($comando, 'pesquisar') || str_starts_with($comando, 'comparar')) {
            $partesComando = explode(' ', $comando, 2);
            $produtoNome = trim($partesComando[1] ?? '');
            if (empty($produtoNome)) {
                return "Formato inválido. 😕\nUse: *pesquisar <nome do produto>*\nExemplo: *pesquisar café pilão 500g*";
            }
            $ultimoLocal = Compra::findLastCompletedByUser($this->pdo, $this->usuario->id);
            if (!$ultimoLocal) {
                return "Preciso que completes pelo menos uma compra antes de poderes pesquisar preços, para eu saber qual é a tua cidade. 😉";
            }
            $cidadeUsuario = $ultimoLocal['cidade'];
            if (empty($cidadeUsuario)) {
                 return "Não consegui identificar a tua cidade. 😥\nPor favor, inicia uma nova compra (podes cancelar logo a seguir) para que eu possa registar a tua localização.";
            }
            $nomeNormalizado = StringUtils::normalize($produtoNome);
            $resultados = HistoricoPreco::findBestPricesInCity($this->pdo, $nomeNormalizado, $cidadeUsuario);
            if (empty($resultados)) {
                return "Que pena! 😥 Não encontrei registos recentes para '*{$produtoNome}*' em *{$cidadeUsuario}*.";
            }
            $resposta = "Encontrei estes preços para '*{$produtoNome}*' em *{$cidadeUsuario}* (últimos 30 dias):\n\n";
            $i = 1;
            foreach ($resultados as $mercado) {
                $min = number_format($mercado['preco_minimo'], 2, ',', '.');
                $med = number_format($mercado['preco_medio'], 2, ',', '.');
                $resposta .= "*{$i}) {$mercado['estabelecimento_nome']}*\n";
                $resposta .= "   💰 *Menor Preço:* R$ {$min}\n";
                $resposta .= "   📊 *Preço Médio:* R$ {$med} (baseado em {$mercado['total_registos']} registos)\n\n";
                $i++;
            }
            return $resposta;
        }

        // Comandos "state-trigger" (que iniciam um fluxo nos Handlers)
        switch ($comando) {
            
            case 'iniciar compra':
                $listas = ListaCompra::findAllByUser($this->pdo, $this->usuario->id);
                if (!empty($listas)) {
                    $this->usuario->updateState($this->pdo, 'aguardando_tipo_inicio');
                    $resposta = "Olá! 👋\nComo queres começar a tua compra?\n\n";
                    $resposta .= "*1)* Usar uma lista de compras (e comparar preços 📊)\n";
                    $resposta .= "*2)* Registar manualmente (como antes)\n\n";
                    $resposta .= "(Podes também dizer `ver listas` ou `criar lista` a qualquer momento)";
                    return $resposta;
                } else {
                    return $this->getPurchaseStartHandler()->process('aguardando_tipo_inicio', '2', []);
                }
            
            case 'criar lista':
                $this->usuario->updateState($this->pdo, 'aguardando_nome_lista');
                return "Vamos criar uma nova lista! 📝\n\nQual nome queres dar a ela? (ex: *Compras do Mês*, *Churrasco FDS*)";

            case 'ver listas':
                return $this->getPurchaseStartHandler()->process('aguardando_tipo_inicio', '3', []);

            case 'apagar lista':
            case 'deletar lista':
                $listas = ListaCompra::findAllByUser($this->pdo, $this->usuario->id);
                if (empty($listas)) {
                    return "Não tens nenhuma lista guardada para apagar. 😕";
                }
                $resposta = "Qual lista queres apagar? 🗑️\n\n";
                $contextoListas = [];
                $i = 1;
                foreach ($listas as $lista) {
                    $resposta .= "*{$i})* {$lista->nome_lista}\n";
                    $contextoListas[$i] = ['id' => $lista->id, 'nome' => $lista->nome_lista];
                    $i++;
                }
                $resposta .= "\nDigite o *número* da lista para apagar, ou *cancelar*.";
                $this->usuario->updateState($this->pdo, 'aguardando_lista_para_apagar', ['listas_para_apagar' => $contextoListas]);
                return $resposta;

            case 'config':
            case 'configurações':
            case 'configuracoes':
                $this->usuario->updateState($this->pdo, 'aguardando_configuracao');
                $statusAlertas = $this->usuario->receber_alertas ? "Ativado 🔔" : "Desativado 🔕";
                $statusDicas = $this->usuario->receber_dicas ? "Ativado 💡" : "Desativado 🔇";
                $resposta = "Menu de Configurações ⚙️\n";
                $resposta .= "O que queres alterar?\n\n";
                $resposta .= "*1)* Receber Alertas de Preço\n    (Status: *{$statusAlertas}*)\n\n";
                $resposta .= "*2)* Receber Dicas Aleatórias\n    (Status: *{$statusDicas}*)\n\n";
                $resposta .= "Digite o número (1 ou 2) para alterar, ou *cancelar* para sair.";
                return $resposta;

            // --- (ATUALIZAÇÃO) ---
            // (A lógica de saudações agora inicia o Onboarding)
            case 'ajuda':
            case '?':
            case 'oi':
            case 'ola':
            case 'olá':
            case 'bom dia':
            case 'boa tarde':
            case 'boa noite':
            case 'eai':
            case 'eae':
            case 'salve':
                $this->usuario->updateState($this->pdo, 'aguardando_decisao_onboarding');
                return OnboardingHandler::getMensagemInicialOnboarding();
            
            default:
                // Se o utilizador disser qualquer outra coisa não reconhecida,
                // assume que ele quer ajuda (onboarding).
                $this->usuario->updateState($this->pdo, 'aguardando_decisao_onboarding');
                return OnboardingHandler::getMensagemInicialOnboarding();
            // --- (FIM DA ATUALIZAÇÃO) ---
        }
    }


    /**
     * Lógica de finalizar compra
     */
    private function finalizarCompra(Compra $compra): string
    {
        // (Delega para o CompraReportService)
        return CompraReportService::gerarResumoFinalizacao($this->pdo, $compra);
    }


    /**
     * Lógica de registar um item (enquanto a compra está ativa)
     */
    private function processStateWithPurchase(string $comando): string {
        
        if ($comando === 'finalizar compra') {
            try {
                return $this->finalizarCompra($this->compraAtiva);
            } catch (\PDOException $e) {
                writeToLog("!!! ERRO AO FINALIZAR !!!: " . $e->getMessage());
                return "❌ Ops! Tive um problema ao finalizar sua compra. Parece que minha base de dados está desatualizada. Já avisei o suporte!";
            }
        }

        $parser = new ItemParserService();
        $item = $parser->parse($comando);

        if ($item->isSuccess() === false) {
            return $item->errorMessage ?? "Não entendi o formato, desculpe. 😕";
        }
        
        try {
            $this->compraAtiva->addItem(
                $this->pdo, 
                $item->nomeProduto, 
                $item->quantidadeDesc, 
                $item->quantidadeInt, 
                $item->precoPagoFloat, 
                $item->precoNormalFloat 
            );
        } catch (\PDOException $e) {
            writeToLog("!!! ERRO AO ADICIONAR ITEM !!!: " . $e->getMessage());
            return "❌ Ops! Tive um problema ao salvar esse item. Parece que minha base de dados está desatualizada. Já avisei o suporte!";
        }
        
        $precoPagoTotal = $item->precoPagoFloat * $item->quantidadeInt;
        $precoPagoTotalFmt = number_format($precoPagoTotal, 2, ',', '.');
        $nomeProdutoDisplay = $item->quantidadeInt > 1 ? "{$item->quantidadeInt}x {$item->nomeProduto}" : $item->nomeProduto;
        
        $resposta = "Registrado! ✅\n*{$nomeProdutoDisplay}* ({$item->quantidadeDesc}) - *R$ {$precoPagoTotalFmt}*";
        
        if ($item->promocaoDetectada) {
            $precoNormalTotal = $item->precoNormalFloat * $item->quantidadeInt;
            $economiaTotal = $precoNormalTotal - $precoPagoTotal;
            $resposta .= "\n💰 *Ótima promoção!* (De R$ " . number_format($precoNormalTotal, 2, ',', '.') . ". Economizou R$ " . number_format($economiaTotal, 2, ',', '.') . ")";
        } elseif ($item->quantidadeInt > 1) {
            $resposta .= "\n_(Total de {$item->quantidadeInt}un a R$ " . number_format($item->precoPagoFloat, 2, ',', '.') . " cada)_";
        }
        
        $nomeNormalizado = StringUtils::normalize($item->nomeProduto);
        $ultimoRegistro = HistoricoPreco::getUltimoRegistro(
            $this->pdo, $this->usuario->id, $nomeNormalizado, $this->compraAtiva->id 
        );
        
        if ($ultimoRegistro !== null) {
            $ultimoPrecoUnitario = (float)$ultimoRegistro['preco'];
            $localCompraAntiga = $ultimoRegistro['estabelecimento_id'] == $this->compraAtiva->estabelecimento_id
                ? "aqui mesmo"
                : "no *{$ultimoRegistro['estabelecimento_nome']}*";
            $precoAntigoFmt = number_format($ultimoPrecoUnitario, 2, ',', '.');
            $diferenca = $item->precoPagoFloat - $ultimoPrecoUnitario;

            if (abs($diferenca) < 0.001) {
                $resposta .= "\n\n💡 *Você pagou o mesmo valor unitário (R$ {$precoAntigoFmt}) {$localCompraAntiga} da última vez.*";
            } elseif ($diferenca < 0) {
                 $resposta .= "\n\n✨ *Ótimo! Você pagou R$ {$precoAntigoFmt} (un) {$localCompraAntiga} da última vez.*";
            } else {
                $resposta .= "\n\n🔺 *Atenção! Você pagou R$ {$precoAntigoFmt} (un) {$localCompraAntiga} da última vez.*";
            }
        }
        return $resposta . "\n\nPróximo item?";
    }

}
?>