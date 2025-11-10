<?php
// ---
// /app/Controllers/Handlers/OnboardingHandler.php
// (VERSÃO COM 'aguardando_nome_para_onboarding')
// ---

require_once __DIR__ . '/BaseHandler.php';

/**
 * Gere o fluxo de "Onboarding" (Tutorial) para novos utilizadores,
 * explicando as funcionalidades principais.
 */
class OnboardingHandler extends BaseHandler {

    /**
     * Ponto de entrada. O BotController chama este método.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // O "switch" para os diferentes passos do tutorial
        switch ($estado) {
            
            // --- (A LÓGICA QUE FALTAVA) ---
            // O primeiro passo de todos: Salvar o nome
            case 'aguardando_nome_para_onboarding':
                return $this->handleSalvarNome($respostaUsuario);
            // --- (FIM DA ATUALIZAÇÃO) ---

            // O segundo passo: A pergunta inicial do menu
            case 'aguardando_decisao_onboarding':
                return $this->handleDecisaoOnboarding($respostaUsuario);

            // Fluxo 1: Aprender a registar
            case 'onboarding_registrar_1':
                return $this->handleTutorialRegistrar_Passo2($respostaUsuario);

            // Fluxo 2: Aprender listas
            case 'onboarding_listas_1':
                return $this->handleTutorialListas_Passo2($respostaUsuario);

            default:
                // Segurança
                $this->usuario->clearState($this->pdo);
                return "Ops, perdi-me no tutorial. 😅 Vamos recomeçar do zero. Tenta dizer `ajuda` novamente.";
        }
    }

    // --- (A LÓGICA QUE FALTAVA) ---
    /**
     * (NOVO!) Estado: aguardando_nome_para_onboarding
     * Salva o nome do utilizador e avisa sobre a subscrição.
     */
    private function handleSalvarNome(string $respostaUsuario): string
    {
        $novoNome = trim($respostaUsuario);
        // Remove caracteres especiais ou quebras de linha que possam vir do WhatsApp
        $novoNome = preg_replace('/[^\p{L}\p{N}\s]/u', '', $novoNome); 

        if (empty($novoNome) || strlen($novoNome) < 2) {
            return "Nome inválido. 😕 Por favor, diz-me um nome ou apelido com pelo menos 2 letras.";
        }

        // Atualiza o nome no Modelo
        $this->usuario->updateNameAndConfirm($this->pdo, $novoNome);

        // Limpa o estado (o fluxo de "pedir nome" acabou)
        $this->usuario->clearState($this->pdo);

        // Mensagem de boas-vindas E de bloqueio (porque ele ainda é is_ativo = 0)
        $resposta = "Perfeito, {$novoNome}! 👋\n\nO teu registo está quase completo. 🔒\n\n";
        $resposta .= "O Merkia é um serviço privado e parece que este número ainda não está ativado.\n\n";
        $resposta .= "Para saber mais, entre em contato com o administrador.";
        
        return $resposta;
    }
    // --- (FIM DA ATUALIZAÇÃO) ---


    /**
     * Estado: aguardando_decisao_onboarding
     * O utilizador respondeu à pergunta inicial (1, 2 ou 3)
     */
    private function handleDecisaoOnboarding(string $respostaUsuario): string
    {
        switch ($respostaUsuario) {
            case '1': // "Aprender a registar uma compra"
                $this->usuario->updateState($this->pdo, 'onboarding_registrar_1');
                $resposta = "Perfeito! 👨‍🏫 *Tutorial: Como Registar Itens*\n\n";
                $resposta .= "O Merkeeia funciona em duas 'fases':\n\n";
                $resposta .= "1️⃣ *Sem compra ativa:* Podes pedir-me para `criar lista`, `pesquisar` ou `ajuda`.\n";
                $resposta .= "2️⃣ *Com compra ativa:* Estás 'dentro' de um mercado e tudo o que digitares será registado como um item.\n\n";
                $resposta .= "Para começar, primeiro tens de dizer:\n*iniciar compra*\n\n(Não te preocupes, não precisas de digitar agora. Quando quiseres continuar, envia *ok*.)";
                return $resposta;

            case '2': // "Aprender a usar Listas Inteligentes"
                $this->usuario->updateState($this->pdo, 'onboarding_listas_1');
                $resposta = "Excelente escolha! 📊 *Tutorial: Listas Inteligentes*\n\n";
                $resposta .= "Esta é a funcionalidade mais poderosa do Merkeeia.\n\n";
                $resposta .= "1️⃣ Primeiro, cria uma lista de compras antes de ires ao mercado. Diz: `criar lista`\n";
                $resposta .= "2️⃣ O *bot* vai pedir um nome (ex: 'Compras do Mês') e, em seguida, pedirá os itens, um por um.\n\n";
                $resposta .= "(Quando quiseres continuar, envia *ok*.)";
                return $resposta;

            case '3': // "Ver todos os comandos"
                $this->usuario->clearState($this->pdo); // Limpa o estado
                return self::getMensagemAjudaCompleta();

            default:
                return "Opção inválida. 😕 Por favor, digite *1*, *2* ou *3* para escolher o tutorial, ou *cancelar* para sair.";
        }
    }

    /**
     * Estado: onboarding_registrar_1
     * Continuar o tutorial de registo.
     */
    private function handleTutorialRegistrar_Passo2(string $respostaUsuario): string
    {
        $this->usuario->clearState($this->pdo); // Fim do tutorial
        $resposta = "Boa! 🚀\n\n";
        $resposta .= "Depois de dizer `iniciar compra`, o Merkeeia vai perguntar-te *onde* estás (usando o Google 📍).\n\n";
        $resposta .= "Assim que a compra começar, basta enviares os itens no formato:\n*Produto / Quantidade / Preço*\n\n";
        $resposta .= "Exemplo: `Arroz Tio João / 5kg / 21,90`\n\n";
        $resposta .= "Ou, se for uma promoção:\n`Nescau / 400g / 10,00 / 8,50`\n_(Produto / Qtd / Preço Normal / Preço Pago)_\n\n";
        $resposta .= "Quando terminares, é só dizer:\n*finalizar compra*\n\nE eu gero o teu resumo! 😉\n\nPronto! Agora já sabes o básico. Tenta `iniciar compra` quando quiseres.";
        return $resposta;
    }

    /**
     * Estado: onboarding_listas_1
     * Continuar o tutorial de listas.
     */
    private function handleTutorialListas_Passo2(string $respostaUsuario): string
    {
        $this->usuario->clearState($this->pdo); // Fim do tutorial
        $resposta = "Ok, vamos à parte 'Inteligente'. 🧠\n\n";
        $resposta .= "Quando digitares `iniciar compra` (e já tiveres uma lista salva):\n\n";
        $resposta .= "1️⃣ O Merkeeia vai perguntar se queres *'Usar uma lista'*.\n";
        $resposta .= "2️⃣ Escolhes a tua lista (ex: 'Compras do Mês').\n";
        $resposta .= "3️⃣ Eu vou varrer o histórico de preços de *todos os utilizadores* na tua cidade e mostrar-te em *qual mercado* essa lista fica mais barata! 📈\n\n";
        $resposta .= "Pronto! É assim que poupas tempo e dinheiro. Tenta dizer `criar lista` para começar.";
        return $resposta;
    }

    /**
     * Helper PÚBLICO para a mensagem inicial (será chamada pelo BotController)
     */
    public static function getMensagemInicialOnboarding(): string
    {
        $resposta = "Olá! 👋 Sou o Merkeeia, o teu assistente de compras e controlo de preços.\n\n";
        $resposta .= "Vejo que é a tua primeira vez por aqui (ou pediste ajuda). O que queres fazer primeiro?\n\n";
        $resposta .= "*1)* Aprender a registar uma compra (Tutorial Rápido ⏱️)\n\n";
        $resposta .= "*2)* Aprender a usar Listas Inteligentes (O mais poderoso 📊)\n\n";
        $resposta .= "*3)* Apenas ver todos os comandos 📋";
        return $resposta;
    }

    /**
     * Helper PÚBLICO para a lista de comandos (Opção 3)
     */
    public static function getMensagemAjudaCompleta(): string
    {
        $resposta = "Aqui está a lista completa de comandos:\n\n";
        $resposta .= "*PARA COMPRAS:*\n";
        $resposta .= "• `iniciar compra` - Começa a registar itens\n";
        $resposta .= "• `pesquisar <produto>` - Compara preços na tua cidade\n";
        $resposta .= "\n*PARA LISTAS:*\n";
        $resposta .= "• `criar lista` - Cria uma lista de compras\n";
        $resposta .= "• `ver listas` - Mostra as tuas listas\n";
        $resposta .= "• `apagar lista` - Apaga uma lista\n";
        $resposta .= "\n*OUTROS:*\n";
        $resposta .= "• `configurações` - Altera as tuas preferências\n";
        $resposta .= "• `ajuda` - Vê este tutorial novamente";
        return $resposta;
    }
}
?>