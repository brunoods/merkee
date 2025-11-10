<?php
// ---
// /app/Controllers/BotController.php
// (VERSÃO COM NAMESPACE)
// ---

// 1. Define o Namespace
namespace App\Controllers;

// 2. Importa TODAS as dependências
use PDO;
use Exception; // (Para o log de timeout)
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
    
    // Define o tempo que um estado pode ficar ativo antes de expirar
    private const TIMEOUT_MINUTOS = 10;

    // Propriedades para cachear os Handlers (evita criar o mesmo objeto várias vezes)
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

    // --- (Getters para os Handlers - Padrão "Lazy Load") ---
    // (Isto garante que só criamos o Handler se precisarmos dele)

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
     * Decide se a mensagem é um comando, um registo de item ou uma resposta a um estado.
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
                    // O estado expirou!
                    $estadoExpirado = $this->usuario->conversa_estado;
                    $this->usuario->clearState($this->pdo);
                    
                    // (Não podemos logar aqui, mas lançamos uma exceção que o webhook irá apanhar e logar)
                    throw new Exception("Estado '{$estadoExpirado}' do Usuário #{$this->usuario->id} expirou. Estado foi limpo.");
                }
            } catch (Exception $e) {
                // (Ignora se a data for inválida, mas limpa o estado por segurança)
                $this->usuario->clearState($this->pdo);
                throw new Exception("Erro ao processar data do estado: " . $e->getMessage());
            }
        }

        // 2. Se o usuário está num estado de conversa, delega para o Handler
        if ($this->usuario->conversa_estado) {
            return $this->handleStatefulConversation($messageText); // (Usa $messageText original)
        }
        
        // 3. Se não está num estado, trata como um novo comando
        
        // Se a compra está ativa, a lógica é diferente
        if ($this->compraAtiva) {
            return $this->processStateWithPurchase($comandoLimpo);
        } else {
            return $this->processStateWithoutPurchase($comandoLimpo);
        }
    }


    /**
     * Lida com todas as conversas que dependem de um estado (multi-passos)
     * Ex: "aguardando_nome_lista", "aguardando_local_manual", etc.
     */
    private function handleStatefulConversation(string $respostaUsuario): string
    {
        $estado = $this->usuario->conversa_estado;
        $contexto = $this->usuario->conversa_contexto ?? [];
        
        // Delega para o Handler apropriado com base no prefixo do estado
        
        if (str_starts_with($estado, 'onboarding_') || $estado === 'aguardando_decisao_onboarding' || $estado === 'aguardando_nome_para_onboarding') {
            return $this->getOnboardingHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        if (str_starts_with($estado, 'lista_') || $estado === 'aguardando_nome_lista' || $estado === 'adicionando_itens_lista' || $estado === 'aguardando_lista_para_apagar') {
            return $this->getListHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        if (str_starts_with($estado, 'config_') || $estado === 'aguardando_configuracao') {
            return $this->getConfigHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        if (str_starts_with($estado, 'inicio_') || $estado === 'aguardando_local_manual_cidade' || $estado === 'aguardando_local_manual_estado' || $estado === 'aguardando_local_google' || $estado === 'aguardando_lista_para_iniciar') {
            return $this->getPurchaseStartHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        if ($estado === 'aguardando_confirmacao_finalizacao') {
             return $this->getCronFinalizeHandler()->process($estado, $respostaUsuario, $contexto);
        }
        
        // Se o estado não for reconhecido, limpa e avisa
        $this->usuario->clearState($this->pdo);
        return "Opa! 🤔 Parece que me perdi na nossa conversa. Vamos recomeçar. O que gostarias de fazer?";
    }


    /**
     * Lógica principal quando o usuário NÃO TEM compra ativa
     * (Trata comandos de 'iniciar compra', 'listas', 'pesquisar', etc.)
     */
    private function processStateWithoutPurchase(string $comando): string {
        
        // Comando: Pesquisar Preço (prioritário)
        if (str_starts_with($comando, 'pesquisar') || str_starts_with($comando, 'comparar')) {
            $partes = explode(' ', $comando, 2);
            if (count($partes) < 2 || empty(trim($partes[1]))) {
                return "Para pesquisar, envie *pesquisar <nome do produto>* (ex: *pesquisar arroz 5kg*).";
            }
            
            $nomeProduto = trim($partes[1]);
            $nomeNormalizado = StringUtils::normalize($nomeProduto);

            // Tenta encontrar a cidade do usuário
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
                // Deixa o Handler de "Início de Compra" tomar conta
                return $this->getPurchaseStartHandler()->process('inicio_start', $comando, []);
            
            case 'listas':
            case 'criar lista':
            case 'ver listas':
            case 'apagar lista':
                // Deixa o Handler de "Listas" tomar conta
                return $this->getListHandler()->process('lista_start', $comando, []);
                
            case 'config':
            case 'configurar':
            case 'configurações':
                // Deixa o Handler de "Config" tomar conta
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
        }
    }


    /**
     * Lógica de finalizar compra (chamada internamente)
     */
    private function finalizarCompra(Compra $compra): string
    {
        // Delega 100% da lógica de geração de relatório para o Serviço
        // (O try/catch disto será feito no webhook.php)
        return CompraReportService::gerarResumoFinalizacao($this->pdo, $compra);
    }


    /**
     * Lógica de registar um item (enquanto a compra está ativa)
     */
    private function processStateWithPurchase(string $comando): string {
        
        // Comando: Finalizar Compra
        if ($comando === 'finalizar compra') {
            // (A exceção do PDO (se ocorrer) será lançada para o webhook.php)
            return $this->finalizarCompra($this->compraAtiva);
        }

        // Se não for 'finalizar', tenta "parsear" (traduzir) o item
        $parser = new ItemParserService();
        $item = $parser->parse($comando);

        if ($item->isSuccess() === false) {
            return $item->errorMessage ?? "Não entendi o formato, desculpe. 😕";
        }
        
        // (A exceção do PDO (se ocorrer) será lançada para o webhook.php)
        $this->compraAtiva->addItem(
            $this->pdo, 
            $item->nomeProduto, 
            $item->quantidadeDesc, 
            $item->quantidadeInt, 
            $item->precoPagoFloat, 
            $item->precoNormalFloat 
        );
        
        // --- Feedback de Sucesso ---
        
        // Formata o nome (remove '1un' se for o caso)
        $nomeProdutoDisplay = $item->nomeProduto;
        if ($item->quantidadeDesc === '1un' && $item->quantidadeInt === 1) {
             $nomeProdutoDisplay = preg_replace('/\b1un\b/i', '', $nomeProdutoDisplay);
             $nomeProdutoDisplay = trim(preg_replace('/\s+/', ' ', $nomeProdutoDisplay));
        }
        
        $precoPagoTotal = $item->precoPagoFloat * $item->quantidadeInt;
        $precoPagoTotalFmt = number_format($precoPagoTotal, 2, ',', '.');
        
        $resposta = "Registado! ✅\n*{$nomeProdutoDisplay}* ({$item->quantidadeDesc}) - *R$ {$precoPagoTotalFmt}*";
        
        // Feedback de Promoção
        if ($item->promocaoDetectada && $item->precoNormalFloat > $item->precoPagoFloat) {
            $economiaItem = ($item->precoNormalFloat - $item->precoPagoFloat) * $item->quantidadeInt;
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
            $precoAtualUnit = $item->precoPagoFloat / $item->quantidadeInt; // (Calcula o preço unitário atual)
            
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

}
?>