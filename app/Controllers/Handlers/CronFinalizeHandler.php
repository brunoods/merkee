<?php
// ---
// /app/Controllers/Handlers/CronFinalizeHandler.php
// (VERSÃO COM NAMESPACE)
// ---

// 1. Define o Namespace
namespace App\Controllers\Handlers;

// 2. Importa dependências
use App\Models\Compra;
use App\Services\CompraReportService; // (Serviço partilhado)
// (BaseHandler está no mesmo namespace)

/**
 * Gere o fluxo de conversa do CRON que pergunta ao usuário
 * se ele quer finalizar uma compra inativa.
 */
class CronFinalizeHandler extends BaseHandler { // (Funciona)

    /**
     * Ponto de entrada.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // Este Handler só tem um estado
        if ($estado === 'aguardando_confirmacao_finalizacao') {
             return $this->handleConfirmacaoFinalizacao($respostaUsuario, $contexto);
        }
        
        $this->usuario->clearState($this->pdo);
        return "Opa! 🤔 Parece que me perdi. O que gostarias de fazer?";
    }

    /**
     * Lógica do estado: aguardando_confirmacao_finalizacao
     */
    private function handleConfirmacaoFinalizacao(string $respostaUsuario, array $contexto): string
    {
        $respostaLimpa = trim(strtolower($respostaUsuario));
        
        // Verifica se o ID da compra ainda está no contexto
        if (!isset($contexto['compra_id'])) {
            $this->usuario->clearState($this->pdo);
            return "Erro: Não sei a qual compra te referes. 😕";
        }
        
        $compra = Compra::findById($this->pdo, $contexto['compra_id']);
        
        // Verifica se a compra ainda existe e está ativa
        if (!$compra || $compra->status !== 'ativa') {
            $this->usuario->clearState($this->pdo);
            return "Essa compra já foi finalizada ou cancelada. 👍";
        }

        // --- Processa a resposta (Sim ou Não) ---
        
        if ($respostaLimpa === 'sim' || $respostaLimpa === 's') {
            
            try {
                // Usa o mesmo Serviço que o BotController usa!
                $respostaCompleta = CompraReportService::gerarResumoFinalizacao($this->pdo, $compra); 
                
                $this->usuario->clearState($this->pdo);
                return $respostaCompleta; 

            } catch (\PDOException $e) {
                 // (O webhook.php irá logar este erro)
                 $this->usuario->clearState($this->pdo);
                 return "❌ Ops! Tive um problema ao finalizar a tua compra. Por favor, tenta enviar *finalizar compra* manualmente.";
            }

        } elseif ($respostaLimpa === 'nao' || $respostaLimpa === 'n' || $respostaLimpa === 'não') {
            
            // Apenas limpa o estado. A compra continua ativa.
            // O CRON Job não vai perguntar de novo (porque o estado foi limpo).
            $this->usuario->clearState($this->pdo);
            return "Entendido! A compra continua ativa. 👍\n\nQuando quiseres, podes continuar a registar itens ou enviar *finalizar compra*.";
        
        } else {
            // Pede de novo (mantém o estado)
            $this->usuario->updateState($this->pdo, 'aguardando_confirmacao_finalizacao', $contexto);
            return "Não entendi. 😕 Por favor, responde apenas com *sim* ou *não*.";
        }
    }
}
?>