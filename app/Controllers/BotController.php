<?php
// ---
// /app/Controllers/BotController.php
// (VERSÃO ATUALIZADA - ADICIONA 'ver itens' e 'desfazer')
// ---

namespace App\Controllers;

// 1. Importa TODAS as dependências
use PDO;
use Exception;
use App\Models\Compra;
use App\Models\Estabelecimento;
use App\Models\HistoricoPreco;
use App\Models\ListaCompra;
use App\Models\Usuario;
use App\Utils\StringUtils;
use App\Services\ItemParserService;
use App\Services\ParsedItemDTO;
use App\Services\CompraReportService;
use App\Controllers\Handlers\ListHandler;
use App\Controllers\Handlers\ConfigHandler;
use App\Controllers\Handlers\PurchaseStartHandler;
use App\Controllers\Handlers\CronFinalizeHandler;
use App\Controllers\Handlers\OnboardingHandler;

/**
 * O "Cérebro" do Bot.
 * Decide para qual "Especialista" (Handler) enviar a mensagem.
 */
class BotController {

    private PDO $pdo;
    private Usuario $usuario;
    private ?Compra $compraAtiva;
    
    private const TIMEOUT_MINUTOS = 10; // Tempo para um estado de conversa expirar

    // Propriedades para cachear os Handlers
    private ?ListHandler $listHandler = null;
    private ?ConfigHandler $configHandler = null;
    private ?PurchaseStartHandler $purchaseStartHandler = null;
    private ?CronFinalizeHandler $cronFinalizeHandler = null;
    private ?OnboardingHandler $onboardingHandler = null;
    

    public function __construct(PDO $pdo, Usuario $usuario, ?Compra $compraAtiva) {
        $this->pdo = $pdo;
        $this->usuario = $usuario;
        $this->compraAtiva = $compraAtiva;
    }

    // --- (Getters para os Handlers - Padrão "Lazy Load" - Sem alterações) ---

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
    
    private function getOnboardingHandler(): OnboardingHandler {
        if ($this->onboardingHandler === null) {
            $this->onboardingHandler = new OnboardingHandler($this->pdo, $this->usuario);
        }
        return $this->onboardingHandler;
    }

    /**
     * Ponto de entrada principal do Controller.
     */
    public function processMessage(string $messageText, array $contexto_extra = []): string 
    {
        $comandoLimpo = strtolower(trim($messageText));
        
        // --- (INÍCIO DA NOVA CORREÇÃO - BLOQUEIO DE TRIAL EXPIRADO) ---
        
        $expiraEm = $this->usuario->data_expiracao ? new \DateTime($this->usuario->data_expiracao) : null;
        $agora = new \DateTime();
        
        // A data de expiração existe E está no passado?
        $trialExpirado = ($expiraEm !== null && $expiraEm < $agora);
        
        // Se o trial expirou...
        if ($trialExpirado) {
            
            $comandosPermitidos = ['login', 'painel', 'dashboard', 'acesso', 'assinar'];
            
            if (in_array($comandoLimpo, $comandosPermitidos)) {
                
                if ($comandoLimpo === 'assinar') {
                     return "O teu período de teste terminou. Para assinar, envia *login* para acederes ao teu painel e clicares em 'Ativar Assinatura'.";
                }
                // Envia o link mágico (que o auth.php vai redirecionar para assinar.php)
                return $this->handleMagicLinkRequest();
            }
            
            // Bloqueia TODOS os outros comandos
            return "O seu período de teste de 24 horas terminou. ⏳\n\nPara continuar a usar o bot, precisas de ativar a tua assinatura.\n\nEnvia *login* para acederes ao teu painel e subscreveres.";
        }
        
        // 1. Verifica se o estado da conversa expirou (timeout)
        // (Esta é a lógica original do teu ficheiro)
        if ($this->usuario->conversa_estado && $this->usuario->conversa_estado_iniciado_em) {
            try {
                $inicioEstado = new \DateTime($this->usuario->conversa_estado_iniciado_em);
                // (Usamos o $agora que definimos ali em cima)
                $intervalo = $agora->getTimestamp() - $inicioEstado->getTimestamp();
                
                if ($intervalo > (self::TIMEOUT_MINUTOS * 60)) {
                    $estadoExpirado = $this->usuario->conversa_estado;
                    $this->usuario->clearState($this->pdo);
                    throw new Exception("Estado '{$estadoExpirado}' do Usuário #{$this->usuario->id} expirou. Estado foi limpo.");
                }
            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'expirou')) {
                     // (O estado já foi limpo, não fazemos nada)
                } else {
                    $this->usuario->clearState($this->pdo);
                    throw new Exception("Erro ao processar data do estado: " . $e->getMessage());
                }
            }
        }

        // 2. Se o usuário está num estado de conversa, delega para o Handler
        if ($this->usuario->conversa_estado) {
            return $this->handleStatefulConversation($messageText, $contexto_extra); 
        }
        
        // 3. Se não está num estado, trata como um novo comando
        if ($this->compraAtiva) {
            return $this->processStateWithPurchase($comandoLimpo);
        } else {
            return $this->processStateWithoutPurchase($comandoLimpo);
        }
    }

    /**
     * Lida com todas as conversas que dependem de um estado (multi-passos)
     */
    private function handleStatefulConversation(string $respostaUsuario, array $contexto_extra): string
    {
        $estado = $this->usuario->conversa_estado;
        $contexto = array_merge($this->usuario->conversa_contexto ?? [], $contexto_extra);
        
        if (str_starts_with($estado, 'onboarding_') || $estado === 'aguardando_decisao_onboarding' || $estado === 'aguardando_nome_para_onboarding') {
            return $this->getOnboardingHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        if (str_starts_with($estado, 'lista_') || $estado === 'aguardando_nome_lista' || $estado === 'adicionando_itens_lista' || $estado === 'aguardando_lista_para_apagar') {
            return $this->getListHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        if (str_starts_with($estado, 'config_') || $estado === 'aguardando_configuracao') {
            return $this->getConfigHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        if (str_starts_with($estado, 'inicio_') || $estado === 'aguardando_local_manual_cidade' || $estado === 'aguardando_local_manual_estado' || $estado === 'aguardando_local_google' || $estado === 'aguardando_lista_para_iniciar' || $estado === 'aguardando_local_google_confirmacao' || $estado === 'aguardando_localizacao' || $estado === 'aguardando_correcao_cidade' || $estado === 'aguardando_correcao_estado') {
            return $this->getPurchaseStartHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        if ($estado === 'aguardando_confirmacao_finalizacao') {
             return $this->getCronFinalizeHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        $this->usuario->clearState($this->pdo);
        return "Opa! 🤔 Parece que me perdi na nossa conversa. Vamos recomeçar. O que gostarias de fazer?";
    }


    // --- (processStateWithoutPurchase e finalizarCompra - Sem alterações) ---

    /**
     * Lógica principal quando o usuário NÃO TEM compra ativa
     */
    private function processStateWithoutPurchase(string $comando): string {
        
        if (str_starts_with($comando, 'pesquisar') || str_starts_with($comando, 'comparar')) {
            $partes = explode(' ', $comando, 2);
            if (count($partes) < 2 || empty(trim($partes[1]))) {
                return "Para pesquisar, envie *pesquisar <nome do produto>* (ex: *pesquisar arroz 5kg*).";
            }
            
            $nomeProduto = trim($partes[1]);
            $nomeNormalizado = StringUtils::normalize($nomeProduto);

            $ultimoLocal = Compra::findLastCompletedByUser($this->pdo, $this->usuario->id);
            if (!$ultimoLocal || empty($ultimoLocal['cidade'])) {
                return "Ainda não sei em que cidade estás. 📍\n\nPara pesquisar preços, por favor, *inicia uma compra* primeiro. Assim, saberei onde procurar.";
            }
            $cidadeUsuario = $ultimoLocal['cidade'];
            
            $precos = HistoricoPreco::findBestPricesInCity($this->pdo, $nomeNormalizado, $cidadeUsuario, 30);
            
            if (empty($precos)) {
                return "Não encontrei registos recentes para *{$nomeProduto}* em *{$cidadeUsuario}*. 😕";
            }
            
            $resposta = "Resultados para *{$nomeProduto}* em *{$cidadeUsuario}* (últimos 30 dias):\n";
            foreach ($precos as $preco) {
                $precoFmt = number_format((float)$preco['preco_minimo'], 2, ',', '.');
                $dataFmt = (new \DateTime($preco['data_mais_recente']))->format('d/m/Y');
                $resposta .= "\n📍 *{$preco['estabelecimento_nome']}*";
                $resposta .= "\n💰 *R$ {$precoFmt}* (visto em {$dataFmt})";
            }
            return $resposta;
        }

        // Comandos "state-trigger" (que iniciam uma conversa)
        switch ($comando) {
            
            case 'iniciar compra':
                return $this->getPurchaseStartHandler()->process('inicio_start', $comando, []);
            
            case 'listas':
            case 'criar lista':
            case 'ver listas':
            case 'apagar lista':
                return $this->getListHandler()->process('lista_start', $comando, []);
                
            case 'config':
            case 'configurar':
            case 'configurações':
                return $this->getConfigHandler()->process('config_start', $comando, []);
            
            case 'login':
            case 'painel':
            case 'dashboard':
            case 'acesso':
                return $this->handleMagicLinkRequest();

            case 'ajuda':
            case 'comandos':
            case 'tutorial':
                return OnboardingHandler::getMensagemAjudaCompleta();
            
            case 'olá':
            case 'oi':
            case 'bom dia':
            case 'boa tarde':
            case 'boa noite':
                $nome = $this->usuario->nome ? explode(' ', $this->usuario->nome)[0] : "Olá";
                return "Olá, {$nome}! 👋\nPosso ajudar-te a iniciar uma compra, gerir as tuas listas ou pesquisar preços.\n\nEnvia *comandos* para ver todas as opções.";

            default:
                return "Desculpa, não entendi. 😕\nEnvia *comandos* para ver tudo o que posso fazer.";
        }
    }

   /**
     * Lógica de finalizar compra (chamada internamente)
     * (VERSÃO ATUALIZADA COM LÓGICA DE TRIAL DE 24H)
     */
    private function finalizarCompra(Compra $compra): string
    {
        // --- (INÍCIO DA LÓGICA DE TRIAL) ---
        
        // 1. Verifica se o utilizador já tem compras ANTES desta.
        // (Usamos a mesma função que o dashboard usa)
        $comprasAnteriores = Compra::findAllCompletedByUser($this->pdo, $this->usuario->id);
        
        // 2. Se o utilizador NÃO está ativo E não tem NENHUMA compra anterior...
        if (!$this->usuario->is_ativo && count($comprasAnteriores) === 0) {
            // ...Ativa o trial de 24 horas!
            $this->usuario->ativarTrial24h($this->pdo); 
        }
        
        // --- (FIM DA LÓGICA DE TRIAL) ---

        // 3. Delega 100% da lógica de geração de relatório para o Serviço
        // (Esta linha continua igual)
        return CompraReportService::gerarResumoFinalizacao($this->pdo, $compra);
    }

    /**
     * Lógica de registar um item (enquanto a compra está ativa)
     * (*** MÉTODO ATUALIZADO COM AS NOVAS FUNCIONALIDADES ***)
     */
    private function processStateWithPurchase(string $comando): string {
        
        // --- (INÍCIO DAS NOVAS FUNCIONALIDADES) ---
        
        // FEATURE #4: Listar itens
        if ($comando === 'ver itens' || $comando === 'o que ja registrei' || $comando === 'lista') {
            $itens = $this->compraAtiva->findAllItems($this->pdo);
            
            if (empty($itens)) {
                return "Ainda não registaste nenhum item nesta compra. 🛒\n\nEnvia-me o teu primeiro item (Ex: *2x Leite 5,00*).";
            }
            
            $resposta = "Itens registados até agora:\n";
            $total = 0;
            foreach ($itens as $item) {
                $precoTotalItem = (float)$item['preco'] * (int)$item['quantidade'];
                $precoFmt = number_format($precoTotalItem, 2, ',', '.');
                $resposta .= "\n- *{$item['produto_nome']}* ({$item['quantidade_desc']}) - R$ {$precoFmt}";
                $total += $precoTotalItem;
            }
            $totalFmt = number_format($total, 2, ',', '.');
            $resposta .= "\n\n*Total atual: R$ {$totalFmt}*";
            return $resposta;
        }

        // FEATURE #5: Desfazer (cancelar último)
        if ($comando === 'desfazer' || $comando === 'cancelar' || $comando === 'cancelar ultimo') {
            $ultimoItem = $this->compraAtiva->findLastItem($this->pdo);
            
            if ($ultimoItem === null) {
                return "Não há nenhum item para cancelar. 🤷‍♂️";
            }
            
            try {
                $this->compraAtiva->deleteLastItemAndHistory($this->pdo, $ultimoItem);
                $nomeItem = $ultimoItem['produto_nome'];
                return "Item *{$nomeItem}* removido! 👍\n\nPodes continuar a enviar os teus itens.";
            } catch (Exception $e) {
                // (O webhook.php irá logar isto)
                throw new Exception("Falha crítica ao tentar apagar item: " . $e->getMessage());
            }
        }
        
        // --- (FIM DAS NOVAS FUNCIONALIDADES) ---

        
        // (Lógica de Finalizar Compra - movida para cima para prioridade)
        if ($comando === 'finalizar compra') {
            return $this->finalizarCompra($this->compraAtiva);
        }

        // 1. O Parser (lógica antiga continua)
        $parser = new ItemParserService();
        $item = $parser->parse($comando); 

        if ($item->isSuccess() === false) {
            return $item->errorMessage ?? "Não entendi o formato, desculpe. 😕";
        }
        
        $precoUnitarioPago = $item->precoPagoFloat;
        $precoUnitarioNormal = $item->precoNormalFloat;
        
        // 2. Passamos o preço UNITÁRIO para a base de dados
        $this->compraAtiva->addItem(
            $this->pdo, 
            $item->nomeProduto, 
            $item->quantidadeDesc, 
            $item->quantidadeInt, 
            $precoUnitarioPago, 
            $precoUnitarioNormal
        );
        
        // 3. Feedback de Sucesso
        $nomeProdutoDisplay = $item->nomeProduto;
        if ($item->quantidadeDesc === '1un' && $item->quantidadeInt === 1) {
             $nomeProdutoDisplay = preg_replace('/\b1un\b/i', '', $nomeProdutoDisplay);
             $nomeProdutoDisplay = trim(preg_replace('/\s+/', ' ', $nomeProdutoDisplay));
        }
        
        $precoPagoTotal = $precoUnitarioPago * $item->quantidadeInt;
        $precoPagoTotalFmt = number_format($precoPagoTotal, 2, ',', '.');
        
        $qtdDisplay = $item->quantidadeDesc;
        if ($item->quantidadeInt > 1 && $item->quantidadeDesc === '1un') {
            $qtdDisplay = $item->quantidadeInt . "un";
        }
        
        $resposta = "Registado! ✅\n*{$nomeProdutoDisplay}* ({$qtdDisplay}) - *R$ {$precoPagoTotalFmt}*";
        
        // 4. Feedback de Promoção
        if ($item->promocaoDetectada && $precoUnitarioNormal > $precoUnitarioPago) {
            $economiaItem = ($precoUnitarioNormal - $precoUnitarioPago) * $item->quantidadeInt;
            $economiaFmt = number_format($economiaItem, 2, ',', '.');
            $resposta .= "\n🤑 Boa! Poupaste *R$ {$economiaFmt}* nesta promoção!";
        }
        
        // 5. Feedback de Comparação de Histórico
        $nomeNormalizado = StringUtils::normalize($item->nomeProduto);
        $historico = HistoricoPreco::getUltimoRegistro(
            $this->pdo, 
            $this->usuario->id, 
            $nomeNormalizado, 
            $this->compraAtiva->id
        );
        
        if ($historico) {
            $ultimoPrecoUnit = (float)$historico['preco_unitario'];
            $precoAtualUnit = $precoUnitarioPago; 
            
            $diff = $precoAtualUnit - $ultimoPrecoUnit;
            $percentual = $ultimoPrecoUnit > 0 ? ($diff / $ultimoPrecoUnit) * 100 : 0;
            
            $ultimoPrecoFmt = number_format($ultimoPrecoUnit, 2, ',', '.');
            $localUltimaCompra = $historico['estabelecimento_nome'] ?? 'outra loja';
            
            if ($diff > 0.01 && $percentual > 5) { // Subiu mais de 5%
                $resposta .= "\n📈 *Atenção:* Pagaste *R$ {$ultimoPrecoFmt}* (unid.) em {$localUltimaCompra} da última vez.";
            } elseif ($diff < -0.01 && $percentual < -5) { // Caiu mais de 5%
                $resposta .= "\n📉 *Ótimo preço!* Pagaste *R$ {$ultimoPrecoFmt}* (unid.) em {$localUltimaCompra} da última vez.";
            }
        }
        
        return $resposta . "\n\nPróximo item?";
    }


    // --- (handleMagicLinkRequest - Sem alterações) ---

    /**
     * Lida com o pedido de 'login' ou 'painel'.
     */
    private function handleMagicLinkRequest(): string
    {
        try {
            $token = $this->usuario->updateLoginToken($this->pdo);
            $appUrl = $_ENV['APP_URL'] ?? getenv('APP_URL');
            if (empty($appUrl)) {
                throw new Exception("APP_URL não está definido no ficheiro .env");
            }

            $magicLink = $appUrl . "/aplicativo/public/auth.php?token=" . $token;
            
            $nomeCurto = explode(' ', $this->usuario->nome)[0];
            $resposta = "Olá, {$nomeCurto}! 👋\n\n";
            $resposta .= "Aqui está o teu link de acesso seguro ao teu painel. Clica nele para veres os teus relatórios e histórico de gastos.\n\n";
            $resposta .= $magicLink;
            $resposta .= "\n\n(Este link é válido apenas por 10 minutos e só pode ser usado uma vez).";
            
            return $resposta;

        } catch (Exception $e) {
            throw new Exception("Erro ao gerar o link mágico: " . $e->getMessage());
        }
    }
}
?>