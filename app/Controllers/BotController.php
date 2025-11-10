<?php
// ---
// /app/Controllers/BotController.php
// (VERSÃO COM CORREÇÃO NO TIMEOUT)
// ---

namespace App\Controllers;

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


class BotController {

    private PDO $pdo;
    private Usuario $usuario;
    private ?Compra $compraAtiva;
    
    private const TIMEOUT_MINUTOS = 10;

    // (Propriedades dos Handlers... idênticas)
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

    // --- (Getters para os Handlers - Padrão "Lazy Load" - idênticos) ---
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
    public function processMessage(string $messageText): string 
    {
        $comandoLimpo = strtolower(trim($messageText));
        
        // 1. Verifica se o estado da conversa expirou (timeout)
        if ($this->usuario->conversa_estado && $this->usuario->conversa_estado_iniciado_em) {
            try {
                $inicioEstado = new \DateTime($this->usuario->conversa_estado_iniciado_em);
                $agora = new \DateTime();
                $intervalo = $agora->getTimestamp() - $inicioEstado->getTimestamp();
                
                if ($intervalo > (self::TIMEOUT_MINUTOS * 60)) {
                    // --- (A CORREÇÃO ESTÁ AQUI) ---
                    // O estado expirou!
                    $estadoExpirado = $this->usuario->conversa_estado;
                    $this->usuario->clearState($this->pdo);
                    
                    // (Regista o erro no log, mas NÃO PÁRA O SCRIPT)
                    throw new Exception("Estado '{$estadoExpirado}' do Usuário #{$this->usuario->id} expirou. Estado foi limpo.");
                    // --- (FIM DA CORREÇÃO - O CATCH ABAIXO VAI PEGAR NISTO) ---
                }
            } catch (Exception $e) {
                // (O catch agora apanha o erro da data OU o erro de timeout que criámos)
                
                // --- (A CORREÇÃO ESTÁ AQUI) ---
                // Se o estado expirou, limpamos o estado e CONTINUAMOS
                // para que a mensagem ("Oi") seja processada como um novo comando.
                if (str_contains($e->getMessage(), 'expirou')) {
                     // (O estado já foi limpo, não fazemos nada,
                     // deixamos o script continuar para o passo 2)
                } else {
                    // Se foi um erro de data inválida, limpa e lança a exceção
                    $this->usuario->clearState($this->pdo);
                    throw new Exception("Erro ao processar data do estado: " . $e->getMessage());
                }
                // --- (FIM DA CORREÇÃO) ---
            }
        }

        // 2. Se o usuário está num estado de conversa, delega para o Handler
        if ($this->usuario->conversa_estado) {
            return $this->handleStatefulConversation($messageText); // (Usa $messageText original)
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
     * (Função idêntica à tua)
     */
    private function handleStatefulConversation(string $respostaUsuario): string
    {
        $estado = $this->usuario->conversa_estado;
        $contexto = $this->usuario->conversa_contexto ?? [];
        
        if (str_starts_with($estado, 'onboarding_') || $estado === 'aguardando_decisao_onboarding' || $estado === 'aguardando_nome_para_onboarding') {
            return $this->getOnboardingHandler()->process($estado, $respostaUsuario, $contexto);
        }
        if (str_starts_with($estado, 'lista_') || $estado === 'aguardando_nome_lista' || $estado === 'adicionando_itens_lista' || $estado === 'aguardando_lista_para_apagar') {
            return $this->getListHandler()->process($estado, $respostaUsuario, $contexto);
        }
        if (str_starts_with($estado, 'config_') || $estado === 'aguardando_configuracao') {
            return $this->getConfigHandler()->process($estado, $respostaUsuario, $contexto);
        }
        if (str_starts_with($estado, 'inicio_') || $estado === 'aguardando_local_manual_cidade' || $estado === 'aguardando_local_manual_estado' || $estado === 'aguardando_local_google' || $estado === 'aguardando_lista_para_iniciar' || $estado === 'aguardando_local_google_confirmacao') {
            return $this->getPurchaseStartHandler()->process($estado, $respostaUsuario, $contexto);
        }
        if ($estado === 'aguardando_confirmacao_finalizacao') {
             return $this->getCronFinalizeHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        $this->usuario->clearState($this->pdo);
        return "Opa! 🤔 Parece que me perdi na nossa conversa. Vamos recomeçar. O que gostarias de fazer?";
    }


    /**
     * Lógica principal quando o usuário NÃO TEM compra ativa
     * (Função idêntica à tua)
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

                case 'login':
            case 'painel':
            case 'dashboard':
            case 'acesso':
                return $this->handleMagicLinkRequest();
        }
    }


    /**
     * Lógica de finalizar compra
     * (Função idêntica à tua)
     */
    private function finalizarCompra(Compra $compra): string
    {
        return CompraReportService::gerarResumoFinalizacao($this->pdo, $compra);
    }

/**
     * Lógica de registar um item (enquanto a compra está ativa)
     * (VERSÃO CORRIGIDA - LÓGICA DE PREÇO)
     */
    private function processStateWithPurchase(string $comando): string {
        
        if ($comando === 'finalizar compra') {
            return $this->finalizarCompra($this->compraAtiva);
        }

        $parser = new ItemParserService();
        $item = $parser->parse($comando); // (O Parser agora retorna preço unitário)

        if ($item->isSuccess() === false) {
            return $item->errorMessage ?? "Não entendi o formato, desculpe. 😕";
        }
        
        // --- (INÍCIO DA CORREÇÃO) ---
        // O Parser deu-nos o preço UNITÁRIO.
        $precoUnitarioPago = $item->precoPagoFloat;
        $precoUnitarioNormal = $item->precoNormalFloat;
        
        // Passamos o preço UNITÁRIO para a base de dados
        $this->compraAtiva->addItem(
            $this->pdo, 
            $item->nomeProduto, 
            $item->quantidadeDesc, 
            $item->quantidadeInt, 
            $precoUnitarioPago, // (Preço Unitário)
            $precoUnitarioNormal // (Preço Normal Unitário)
        );
        
        // --- Feedback de Sucesso ---
        
        $nomeProdutoDisplay = $item->nomeProduto;
        if ($item->quantidadeDesc === '1un' && $item->quantidadeInt === 1) {
             $nomeProdutoDisplay = preg_replace('/\b1un\b/i', '', $nomeProdutoDisplay);
             $nomeProdutoDisplay = trim(preg_replace('/\s+/', ' ', $nomeProdutoDisplay));
        }
        
        // (Calcula o preço TOTAL apenas para a mensagem de resposta)
        $precoPagoTotal = $precoUnitarioPago * $item->quantidadeInt;
        $precoPagoTotalFmt = number_format($precoPagoTotal, 2, ',', '.');
        
        // (Ajusta a descrição da quantidade se for "1x (1un)")
        $qtdDisplay = $item->quantidadeDesc;
        if ($item->quantidadeInt > 1 && $item->quantidadeDesc === '1un') {
            $qtdDisplay = $item->quantidadeInt . "un";
        }
        
        $resposta = "Registado! ✅\n*{$nomeProdutoDisplay}* ({$qtdDisplay}) - *R$ {$precoPagoTotalFmt}*";
        
        // Feedback de Promoção
        if ($item->promocaoDetectada && $precoUnitarioNormal > $precoUnitarioPago) {
            $economiaItem = ($precoUnitarioNormal - $precoUnitarioPago) * $item->quantidadeInt;
            $economiaFmt = number_format($economiaItem, 2, ',', '.');
            $resposta .= "\n🤑 Boa! Poupaste *R$ {$economiaFmt}* nesta promoção!";
        }
        
        // Feedback de Comparação de Histórico
        $nomeNormalizado = StringUtils::normalize($item->nomeProduto);
        $historico = HistoricoPreco::getUltimoRegistro(
            $this->pdo, 
            $this->usuario->id, 
            $nomeNormalizado, 
            $this->compraAtiva->id
        );
        
        if ($historico) {
            $ultimoPrecoUnit = (float)$historico['preco_unitario'];
            $precoAtualUnit = $precoUnitarioPago; // (Agora está correto)
            
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
        // --- (FIM DA CORREÇÃO) ---
    }

/**
     * Lida com o pedido de 'login' ou 'painel'.
     * Gera o Link Mágico e envia-o ao utilizador.
     */
    private function handleMagicLinkRequest(): string
    {
        try {
            // 1. Gera e guarda o token (usando o método que criámos no Usuario.php)
            $token = $this->usuario->updateLoginToken($this->pdo);
            
            // 2. Lê o URL base do .env (usando $_ENV para evitar cache)
            $appUrl = $_ENV['APP_URL'] ?? getenv('APP_URL');
            if (empty($appUrl)) {
                // (Não podemos logar aqui, mas o webhook.php vai apanhar esta exceção)
                throw new Exception("APP_URL não está definido no ficheiro .env");
            }

            // 3. Monta o link
            $magicLink = $appUrl . "/merkee/public/auth.php?token=" . $token;
            
            // 4. Prepara a resposta
            $nomeCurto = explode(' ', $this->usuario->nome)[0];
            $resposta = "Olá, {$nomeCurto}! 👋\n\n";
            $resposta .= "Aqui está o teu link de acesso seguro ao teu painel. Clica nele para veres os teus relatórios e histórico de gastos.\n\n";
            $resposta .= $magicLink;
            $resposta .= "\n\n(Este link é válido apenas por 10 minutos e só pode ser usado uma vez).";
            
            return $resposta;

        } catch (Exception $e) {
            // (O webhook.php irá logar isto)
            throw new Exception("Erro ao gerar o link mágico: " . $e->getMessage());
        }
    }
}
?>