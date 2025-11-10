<?php
// ---
// /app/Controllers/Handlers/ConfigHandler.php
// (VERSÃO COM NAMESPACE)
// ---

// 1. Define o Namespace
namespace App\Controllers\Handlers;

// (BaseHandler está no mesmo namespace, não precisa de 'use')

/**
 * Gere o fluxo de conversa do Menu de Configurações
 */
class ConfigHandler extends BaseHandler { // (Funciona)

    /**
     * Ponto de entrada.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // Se o estado for 'config_start', é um novo comando "config"
        if ($estado === 'config_start') {
            return $this->handleInicioConfig();
        }
        
        if ($estado === 'aguardando_configuracao') {
             return $this->handleConfiguracao($respostaUsuario);
        }

        $this->usuario->clearState($this->pdo);
        return "Opa! 🤔 Parece que me perdi nas configurações. Vamos recomeçar. Envia *config* novamente.";
    }

    /**
     * Estado: config_start
     * (O utilizador acabou de enviar "config")
     */
    private function handleInicioConfig(): string
    {
        // Busca os valores atuais do usuário (do objeto)
        $alertas = $this->usuario->receber_alertas;
        $dicas = $this->usuario->receber_dicas;
        
        $resposta = "⚙️ *Configurações*\n\nO que queres alterar? (Envia só o *número*)\n";
        
        $resposta .= "\n*1* - Alertas de Preço (quando um produto favorito baixa de preço)";
        $resposta .= $alertas ? " (Ativado ✅)" : " (Desativado ❌)";
        
        $resposta .= "\n*2* - Dicas e Resumos (dicas de poupança e resumos semanais)";
        $resposta .= $dicas ? " (Ativado ✅)" : " (Desativado ❌)";
        
        $resposta .= "\n\n(Envia *cancelar* para sair)";

        // Entra no estado de espera
        $this->usuario->updateState($this->pdo, 'aguardando_configuracao');
        return $resposta;
    }

    /**
     * Lógica do estado: aguardando_configuracao
     */
    private function handleConfiguracao(string $respostaUsuario): string
    {
        $comando = trim(strtolower($respostaUsuario));
        
        if ($comando === 'cancelar' || $comando === 'sair') {
             $this->usuario->clearState($this->pdo);
             return "Configurações mantidas. 👍";
        }
        
        if ($comando === '1') {
            // Inverte o valor atual
            $novoValor = !$this->usuario->receber_alertas;
            $this->usuario->updateConfig($this->pdo, 'receber_alertas', $novoValor);
            
            // Recomeça o fluxo
            return $this->handleInicioConfig(); 
        
        } elseif ($comando === '2') {
            // Inverte o valor atual
            $novoValor = !$this->usuario->receber_dicas;
            $this->usuario->updateConfig($this->pdo, 'receber_dicas', $novoValor);
            
            // Recomeça o fluxo
            return $this->handleInicioConfig();
        
        } else {
            // Não entendeu, mas mantém o estado
            $this->usuario->updateState($this->pdo, 'aguardando_configuracao');
            return "Não entendi. 😕 Envia *1* para alterar os Alertas, *2* para as Dicas, ou *cancelar* para sair.";
        }
    }
}
?>