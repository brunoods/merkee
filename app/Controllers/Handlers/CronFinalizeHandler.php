<?php
// ---
// /app/Controllers/Handlers/CronFinalizeHandler.php
// (VERSÃO 2.0 - AGORA ENVIA O RESUMO COMPLETO)
// ---

require_once __DIR__ . '/BaseHandler.php'; // O "molde"
require_once __DIR__ . '/../../Models/Compra.php';

// --- (INÍCIO DA ATUALIZAÇÃO) ---
// 1. Incluímos o novo Serviço de Relatório
require_once __DIR__ . '/../../Services/CompraReportService.php';
// --- (FIM DA ATUALIZAÇÃO) ---

/**
 * Gere o fluxo de conversa do CRON que pergunta ao usuário
 * se ele quer finalizar uma compra inativa.
 */
class CronFinalizeHandler extends BaseHandler {

    /**
     * Ponto de entrada. O BotController chama este método.
     */
    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // Este handler é simples e só gere um estado.
        if ($estado === 'aguardando_confirmacao_finalizacao') {
            return $this->handleConfirmacaoFinalizacao($respostaUsuario, $contexto);
        }

        // Segurança
        $this->usuario->clearState($this->pdo);
        return "Ops, algo correu mal (Handler de Finalização). Vamos recomeçar.";
    }

    // --- (LÓGICA MOVIDA DIRETAMENTE DO BotController) ---

    /**
     * Lógica do estado: aguardando_confirmacao_finalizacao
     * (AGORA DEVOLVE O RESUMO COMPLETO)
     */
    private function handleConfirmacaoFinalizacao(string $respostaUsuario, array $contexto): string
    {
        $respostaLimpa = trim(strtolower($respostaUsuario));
        $compra = Compra::findById($this->pdo, $contexto['compra_id']);
        
        if (!$compra || $compra->status === 'finalizada') {
            $this->usuario->clearState($this->pdo);
            return "Ops, parece que esta compra já foi finalizada. Pode iniciar uma nova!";
        }

        if ($respostaLimpa === 'sim' || $respostaLimpa === 's') {
            
            // --- (INÍCIO DA ATUALIZAÇÃO) ---
            // 2. Agora chamamos o mesmo serviço que o BotController usa
            try {
                
                // Chamamos o serviço que faz tudo:
                $respostaCompleta = CompraReportService::gerarResumoFinalizacao($this->pdo, $compra);
                
                $this->usuario->clearState($this->pdo);
                return $respostaCompleta; // <--- Devolve o resumo completo!

            } catch (\PDOException $e) {
                 // writeToLog("!!! ERRO AO FINALIZAR (vinda do CRON) !!!: " . $e->getMessage());
                 return "❌ Ops! Tive um problema ao finalizar sua compra.";
            }
            // --- (FIM DA ATUALIZAÇÃO) ---

        } elseif ($respostaLimpa === 'nao' || $respostaLimpa === 'n' || $respostaLimpa === 'não') {
            $this->usuario->clearState($this->pdo);
            return "Sem problemas! 👍 Pode continuar a adicionar itens.";
        } else {
            return "Não entendi 😕. Responda apenas *sim* ou *nao*.";
        }
    }
}
?>