<?php
// ---
// /app/Controllers/Handlers/ConfigHandler.php
// (NOVO FICHEIRO)
// ---

require_once __DIR__ . '/BaseHandler.php'; // O "molde"

/**
 * Gere o fluxo de conversa do Menu de Configurações
 */
class ConfigHandler extends BaseHandler {

    /**
     * Ponto de entrada. O BotController chama este método.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // Este handler é simples e só gere um estado.
        if ($estado === 'aguardando_configuracao') {
            return $this->handleConfiguracao($respostaUsuario);
        }

        // Segurança
        $this->usuario->clearState($this->pdo);
        return "Ops, algo correu mal nas Configurações. Vamos recomeçar.";
    }

    // --- (LÓGICA MOVIDA DIRETAMENTE DO BotController) ---

    /**
     * Lógica do estado: aguardando_configuracao
     */
    private function handleConfiguracao(string $respostaUsuario): string
    {
        $feedback = null;
        switch ($respostaUsuario) {
            case '1':
                // Usamos $this->usuario (da BaseHandler)
                $novoValor = !$this->usuario->receber_alertas; 
                $this->usuario->updateConfig($this->pdo, 'receber_alertas', $novoValor);
                $feedback = $novoValor ? "Alertas de preço ativados! 🔔" : "Alertas de preço desativados. 🔕";
                break;
            case '2':
                $novoValor = !$this->usuario->receber_dicas;
                $this->usuario->updateConfig($this->pdo, 'receber_dicas', $novoValor);
                $feedback = $novoValor ? "Dicas aleatórias ativadas! 💡" : "Dicas aleatórias desativadas. 🔇";
                break;
            default:
                return "Opção inválida. 😕 Por favor, digite *1* ou *2* para alterar, ou *cancelar* para sair.";
        }
        
        $this->usuario->clearState($this->pdo); // Limpa o estado após a ação
        return "Feito! 👍 {$feedback}";
    }
}
?>