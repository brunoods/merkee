<?php
// ---
// /app/Controllers/Handlers/OnboardingHandler.php
// (VERSÃO COM NAMESPACE)
// ---

// 1. Define o Namespace
namespace App\Controllers\Handlers;

// (BaseHandler está no mesmo namespace, não precisa de 'use')

/**
 * Gere o fluxo de "Onboarding" (Tutorial) para novos utilizadores,
 * incluindo a recolha do nome.
 */
class OnboardingHandler extends BaseHandler { // (Funciona)

    /**
     * Ponto de entrada.
     * O BotController chama este método e passa o estado.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        switch ($estado) {
            case 'aguardando_nome_para_onboarding':
                return $this->handleSalvarNome($respostaUsuario);
                
            case 'aguardando_decisao_onboarding':
                return $this->handleDecisaoOnboarding($respostaUsuario);

            case 'onboarding_registrar_1':
                return $this->handleTutorialRegistrar_Passo2($respostaUsuario);

            case 'onboarding_listas_1':
                return $this->handleTutorialListas_Passo2($respostaUsuario);
                
            default:
                $this->usuario->clearState($this->pdo);
                return "Opa! 🤔 Parece que me perdi no nosso tutorial. Vamos recomeçar. O que gostarias de fazer?";
        }
    }

    /**
     * Estado: aguardando_nome_para_onboarding
     * (Vem do webhook.php)
     */
    private function handleSalvarNome(string $respostaUsuario): string
    {
        $nomeLimpo = trim(strip_tags($respostaUsuario));
        
        if (strlen($nomeLimpo) < 2 || count(explode(' ', $nomeLimpo)) > 3) {
            return "Por favor, diz-me um nome ou apelido simples (ex: *Carlos* ou *Carlos Silva*).";
        }

        // Guarda o nome no objeto e na base de dados
        $this->usuario->updateNameAndConfirm($this->pdo, $nomeLimpo);
        
        // Coloca o usuário no próximo estado
        $this->usuario->updateState($this->pdo, 'aguardando_decisao_onboarding');
        
        // Retorna a primeira mensagem do tutorial
        return self::getMensagemInicialOnboarding($nomeLimpo);
    }


    /**
     * Estado: aguardando_decisao_onboarding
     * (O utilizador acabou de receber a 1ª mensagem do tutorial)
     */
    private function handleDecisaoOnboarding(string $respostaUsuario): string
    {
        $comando = trim(strtolower($respostaUsuario));

        switch ($comando) {
            case '1': // Iniciar Tutorial de Registo
                $this->usuario->updateState($this->pdo, 'onboarding_registrar_1');
                return "Vamos lá! 🚀\n\nImagina que estás no mercado e acabaste de pegar *2 caixas de leite* que custaram *R$ 5,00 cada*.\n\nComo me enviarias essa informação?";
            
            case '2': // Iniciar Tutorial de Listas
                $this->usuario->updateState($this->pdo, 'onboarding_listas_1');
                return "Ótimo! 📝\n\nAs listas ajudam-te a organizar e a comparar preços. Para criar uma, envia *criar lista*.\n\nImagina que queres criar uma lista chamada *Compras do Mês*. Como me enviarias esse comando?";
            
            case '3': // Ver todos os comandos
                $this->usuario->clearState($this->pdo); // Fim do onboarding
                return self::getMensagemAjudaCompleta();
            
            case '4': // Sair
                $this->usuario->clearState($this->pdo); // Fim do onboarding
                return "Sem problemas! 👋\n\nEstou pronto quando precisares. Envia *comandos* a qualquer altura se mudares de ideias.";
            
            default:
                return "Por favor, envia apenas o número (1, 2, 3 ou 4) da opção que desejas.";
        }
    }

    /**
     * Estado: onboarding_registrar_1
     * (O utilizador está a tentar responder ao tutorial de registo)
     */
    private function handleTutorialRegistrar_Passo2(string $respostaUsuario): string
    {
        $respostaLimpa = trim(strtolower($respostaUsuario));
        
        // Verifica se a resposta contém "leite", "2" e "5" (bem flexível)
        if (str_contains($respostaLimpa, 'leite') && str_contains($respostaLimpa, '2') && (str_contains($respostaLimpa, '5,00') || str_contains($respostaLimpa, '5.00') || str_contains($respostaLimpa, ' 5 '))) {
            
            $this->usuario->clearState($this->pdo); // Fim do onboarding
            return "Perfeito! ✨\n\nEntendeste exatamente. Podes enviar *'2x Leite 5,00'* ou *'Leite 2un 5.00'*.\n\nQuando quiseres começar a sério, envia *iniciar compra*.\n\nEstou pronto! O que gostarias de fazer agora?";

        } else {
            // Tenta de novo
            $this->usuario->updateState($this->pdo, 'onboarding_registrar_1'); // Mantém o estado
            return "Quase lá! Tenta ser específico sobre a quantidade e o preço.\n\nLembra-te: *2 caixas de leite* a *R$ 5,00 cada*.\n\nTenta enviar algo como: *2x Leite 5,00*";
        }
    }

    /**
     * Estado: onboarding_listas_1
     * (O utilizador está a tentar responder ao tutorial de listas)
     */
    private function handleTutorialListas_Passo2(string $respostaUsuario): string
    {
        $respostaLimpa = trim(strtolower($respostaUsuario));

        if ($respostaLimpa === 'criar lista') {
            $this->usuario->clearState($this->pdo); // Fim do onboarding
            return "Exatamente! 🥳\n\nEu iria então perguntar-te o *nome da lista* (ex: 'Compras do Mês') e depois os *itens* (ex: 'Arroz 5kg').\n\nQuando estiveres pronto, é só usar os comandos.\n\nO que gostarias de fazer agora?";
        
        } else {
            // Tenta de novo
            $this->usuario->updateState($this->pdo, 'onboarding_listas_1'); // Mantém o estado
            return "Não exatamente. 😅\n\nPara iniciar o processo, envia apenas o comando *criar lista*.\n\nTenta enviar esse comando agora.";
        }
    }

    /**
     * Helper PÚBLICO para a mensagem inicial
     */
    public static function getMensagemInicialOnboarding(string $nomeUsuario): string
    {
        $nomeCurto = explode(' ', $nomeUsuario)[0];
        
        $mensagem = "Prazer, {$nomeCurto}! 👋\n\nEu sou o *WalletlyBot*, o teu assistente de compras inteligente.\n\nPosso ajudar-te a:\n✅ *Registar* itens durante a compra.\n📊 *Comparar* preços com as tuas compras passadas.\n💰 *Alertar-te* quando um produto favorito fica mais barato.\n\nQueres fazer um tutorial rápido de 1 minuto para ver como funciona?";
        $mensagem .= "\n\n*1* - Sim, vamos lá! (Tutorial de Registo)\n*2* - Quero aprender sobre as Listas\n*3* - Não, mostra-me todos os comandos\n*4* - Sair por agora";
        
        return $mensagem;
    }

    /**
     * Helper PÚBLICO para a lista de comandos (Opção 3)
     */
    public static function getMensagemAjudaCompleta(): string
    {
        $resposta = "Aqui está tudo o que posso fazer: 🤖\n\n";
        $resposta .= "--- *DURANTE A COMPRA* ---\n";
        $resposta .= "_(Depois de enviar `iniciar compra`)_\n\n";
        $resposta .= "➡️ *<Qtd>x <Produto> <Preço>* (Ex: `2x Arroz 5kg 21,90`)\n";
        $resposta .= "➡️ *<Produto> <Qtd>un <Preço>* (Ex: `Leite 12un 45,00`)\n";
        $resposta .= "➡️ *<Produto> / <Qtd> / <Preço>* (Ex: `Pão / 1un / 5,20`)\n";
        $resposta .= "➡️ *finalizar compra* (Gera o teu resumo)\n\n";
        $resposta .= "--- *GESTÃO* ---\n";
        $resposta .= "➡️ *iniciar compra* (Começa uma nova compra)\n";
        $resposta .= "➡️ *pesquisar <Produto>* (Ex: `pesquisar arroz 5kg`)\n";
        $resposta .= "➡️ *listas* (Vê os comandos de listas)\n";
        $resposta .= "➡️ *config* (Muda as tuas preferências)";
        
        return $resposta;
    }
}
?>