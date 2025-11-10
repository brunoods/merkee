<?php
// ---
// /app/Controllers/Handlers/PurchaseStartHandler.php
// (VERSÃO 3 - CORREÇÃO DEFINITIVA DO BUG DE SELEÇÃO E SINTAXE)
// ---

namespace App\Controllers\Handlers;

use App\Models\Compra;
use App\Models\Estabelecimento;
use App\Models\HistoricoPreco;
use App\Models\ListaCompra;
use App\Services\GooglePlacesService;
use App\Utils\StringUtils;

class PurchaseStartHandler extends BaseHandler {

    public function process(string $estado, string $respostaUsuario, array $contexto): string
    {
        // Roteador de estados para este Handler
        
        if ($estado === 'inicio_start') {
            return $this->handleInicioCompra();
        }
        
        if ($estado === 'aguardando_localizacao') {
            if ($respostaUsuario === 'USER_SENT_LOCATION' && isset($contexto['location'])) {
                return $this->handleLocalizacaoRecebida($contexto['location']);
            } else {
                return $this->handleInicioCompraFallback($respostaUsuario);
            }
        }
        
        if ($estado === 'aguardando_local_google_confirmacao') {
             return $this->handleLocalGoogleConfirmacao($respostaUsuario, $contexto);
        }

        if ($estado === 'aguardando_local_manual_cidade') {
            return $this->handleLocalManualCidade($respostaUsuario, $contexto);
        }
        if ($estado === 'aguardando_local_manual_estado') {
             return $this->handleLocalManualEstado($respostaUsuario, $contexto);
        }
        
        if ($estado === 'aguardando_lista_para_iniciar') {
             return $this->handleEscolhaDeLista($respostaUsuario, $contexto);
        }
                     
        $this->usuario->clearState($this->pdo);
        return "Opa! 🤔 Parece que me perdi no início da tua compra. Vamos recomeçar. Envia *iniciar compra* novamente.";
    }
    
    /**
     * PASSO 1: Pede a localização
     */
    private function handleInicioCompra(): string
    {
        $this->usuario->updateState($this->pdo, 'aguardando_localizacao');
        $resposta = "Vamos começar! 🛍️\n\n";
        $resposta .= "Para encontrar os mercados mais próximos, por favor, *partilhe a sua localização* atual.\n";
        $resposta .= "(Use o clip 📎 e escolha 'Localização' > 'Localização Atual')";
        $resposta .= "\n\nSe preferir, podes *digitar o nome do mercado* para pesquisar.";
        return $resposta;
    }
    
    /**
     * PASSO 2 (Localização): Mostra resultados da proximidade
     */
    private function handleLocalizacaoRecebida(array $location): string
    {
        $google = new GooglePlacesService();
        $locais = $google->buscarSupermercadosProximos(
            $location['latitude'], 
            $location['longitude']
        );

        if (empty($locais)) {
            return $this->handleInicioCompraFallback("Não encontrei supermercados perto de si. 😕");
        }
        
        $resposta = "Encontrei estes locais perto de si. Onde estás? (Envia só o *número*)\n";
        $novoContexto = [];
        $opcoes = 1;
        
        // CORREÇÃO DE EXIBIÇÃO: Usamos $opcoes para a contagem visual (1, 2, 3...)
        foreach ($locais as $local) {
            $resposta .= "\n*$opcoes* - " . htmlspecialchars($local['nome_google']);
            $resposta .= "\n  _" . htmlspecialchars($local['endereco']) . "_";
            // Armazenamos no array com chaves 0-based, pois o JSON as reindexa.
            $novoContexto[] = $local; 
            $opcoes++;
        }
        
        $novoContexto['acao_manual'] = $opcoes; // O último número é a opção "Nenhum destes"
        $resposta .= "\n\n*$opcoes* - Nenhum destes (digitar nome)";

        $this->usuario->updateState($this->pdo, 'aguardando_local_google_confirmacao', $novoContexto);
        return $resposta;
    }

    /**
     * PASSO 2 (Fallback): Mostra resultados da pesquisa por texto
     */
    private function handleInicioCompraFallback(string $respostaUsuario): string
    {
        $ultimoLocal = Compra::findLastCompletedByUser($this->pdo, $this->usuario->id);
        if (!$ultimoLocal) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', ['nome_mercado' => $respostaUsuario]);
             return "Entendido. Como é a tua primeira compra, vamos registar manualmente o *{$respostaUsuario}*.\n\nEm qual *cidade* ele fica?";
        }
        $cidadeEstado = $ultimoLocal['cidade'] . " - " . $ultimoLocal['estado'];

        $google = new GooglePlacesService();
        $locais = $google->buscarLocais($respostaUsuario, $cidadeEstado);
        
        if (empty($locais)) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', ['nome_mercado' => $respostaUsuario]);
            return "Não encontrei *{$respostaUsuario}* em {$cidadeEstado}. 📍\nVamos registar manualmente. Por favor, confirma-me o *nome* do mercado (ou digita 'cancelar').";
        }

        $resposta = "Encontrei estes locais para '{$respostaUsuario}'. Qual é o correto? (Envia só o *número*)\n";
        $novoContexto = [];
        $opcoes = 1;

        // CORREÇÃO DE EXIBIÇÃO: Usamos $opcoes para a contagem visual (1, 2, 3...)
        foreach ($locais as $local) {
            $resposta .= "\n*$opcoes* - " . htmlspecialchars($local['nome_google']);
            $resposta .= "\n  _" . htmlspecialchars($local['endereco']) . "_";
            // Armazenamos no array com chaves 0-based, pois o JSON as reindexa.
            $novoContexto[] = $local; 
            $opcoes++;
        }
        
        $novoContexto['acao_manual'] = $opcoes;
        $resposta .= "\n*$opcoes* - Nenhum destes (Registar manualmente)";

        $this->usuario->updateState($this->pdo, 'aguardando_local_google_confirmacao', $novoContexto);
        return $resposta;
    }


    /**
     * PASSO 3 (Fluxo Comum): Utilizador confirma o local
     * (CORREÇÃO DE ÍNDICE APLICADA AQUI)
     */
    private function handleLocalGoogleConfirmacao(string $respostaUsuario, array $contexto): string
    {
        // $escolha é 1, 2, 3...
        $escolha = (int) trim($respostaUsuario);
        
        // CORREÇÃO DE LÓGICA: Mapeia a escolha do utilizador (1-based) para o índice interno (0-based).
        $indiceReal = $escolha - 1;

        // 1. Lógica de "Nenhum destes"
        if (isset($contexto['acao_manual']) && $escolha === (int)$contexto['acao_manual']) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', ['nome_mercado' => 'Manual']);
            return "Entendido. Qual o *nome* do mercado?";
        }
            
        // 2. Lógica de Seleção de Mercado (usa o índice corrigido)
        if (is_numeric($escolha) && $indiceReal >= 0 && isset($contexto[$indiceReal])) {
            $localEscolhido = $contexto[$indiceReal]; // <-- Acesso Corrigido!
            
            $estabelecimento = Estabelecimento::findByPlaceId($this->pdo, $localEscolhido['place_id']);
            
            if (!$estabelecimento) {
                $endereco = $localEscolhido['endereco']; 
                $cidade = 'N/A';
                $estado = 'N/A';
                // Tenta extrair (Ex: "Rua X, Mirassol - SP" ou "Mirassol, SP")
                if (preg_match('/, ([\w\s]+) - (\w{2})/', $endereco, $matches) || preg_match('/([\w\s]+), (\w{2})/', $endereco, $matches)) {
                    $cidade = trim($matches[1]);
                    $estado = $matches[2];
                }
                
                $estabelecimento = Estabelecimento::createFromGoogle(
                    $this->pdo, 
                    $localEscolhido['place_id'], 
                    $localEscolhido['nome_google'], 
                    $cidade, 
                    $estado
                );
            }
            
            return $this->iniciarFluxoDeLista($estabelecimento);
        }
        
        $this->usuario->clearState($this->pdo);
        return "Não entendi a tua escolha. 😕 Vamos recomeçar. Envia *iniciar compra*.";
    }

    /**
     * PASSO 3 (Fluxo Manual - Cidade):
     */
    private function handleLocalManualCidade(string $respostaUsuario, array $contexto): string
    {
        $nomeMercado = trim(strip_tags($respostaUsuario));
        if (strtolower($nomeMercado) === 'cancelar') {
            $this->usuario->clearState($this->pdo);
            return "Ok, cancelado. 👍";
        }
        if (empty($nomeMercado) || strlen($nomeMercado) > 100) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_cidade', $contexto);
            return "Por favor, envia um nome válido para o mercado.";
        }
        $contexto['nome_mercado'] = $nomeMercado;
        $this->usuario->updateState($this->pdo, 'aguardando_local_manual_estado', $contexto);
        return "Entendido. E em qual *cidade* fica o *{$nomeMercado}*?";
    }
    
    /**
     * PASSO 3 (Fluxo Manual - Estado/Final):
     */
    private function handleLocalManualEstado(string $respostaUsuario, array $contexto): string
    {
        $cidade = trim(strip_tags($respostaUsuario));
        if (empty($cidade) || strlen($cidade) > 50) {
            $this->usuario->updateState($this->pdo, 'aguardando_local_manual_estado', $contexto);
            return "Por favor, envia um nome de cidade válido.";
        }
        
        $contexto['cidade'] = $cidade;
        $estado = "N/A"; 
        if (preg_match('/([\w\s]+) (\w{2})/', $cidade, $matches)) {
            $cidade = trim($matches[1]);
            $estado = strtoupper($matches[2]);
        }
        $nomeMercado = $contexto['nome_mercado'];
        
        $estabelecimento = Estabelecimento::findByManualEntry($this->pdo, $nomeMercado, $cidade, $estado);
        if (!$estabelecimento) {
            $estabelecimento = Estabelecimento::createManual($this->pdo, $nomeMercado, $cidade, $estado);
        }

        return $this->iniciarFluxoDeLista($estabelecimento);
    }
    
    /**
     * PASSO 4 (Final): Iniciar Fluxo de Lista
     */
    private function iniciarFluxoDeLista(Estabelecimento $estabelecimento): string
    {
        $novaCompra = Compra::create($this->pdo, $this->usuario->id, $estabelecimento->id);
        $this->usuario->clearState($this->pdo); 
        
        $listas = ListaCompra::findAllByUser($this->pdo, $this->usuario->id);
        if (empty($listas)) {
            return "Compra iniciada no *{$estabelecimento->nome}*! ✅\n\nAgora é só enviares os teus itens. (Ex: *2x Leite 5,00*)";
        }
        
        $resposta = "Compra iniciada no *{$estabelecimento->nome}*! ✅\n\nQueres usar uma das tuas listas de compras? (Envia só o *número*)\n";
        $contexto = [];
        $opcoes = 1;
        $contexto[$opcoes] = 'nenhuma';
        $resposta .= "\n*$opcoes* - Não, obrigado (compra livre)";
        $opcoes++;

        foreach ($listas as $lista) {
            $resposta .= "\n*$opcoes* - " . htmlspecialchars($lista['nome']);
            $contexto[$opcoes] = $lista['id']; 
            $opcoes++;
        }
        
        $this->usuario->updateState($this->pdo, 'aguardando_lista_para_iniciar', $contexto);
        return $resposta;
    }
    
    /**
     * PASSO 5 (Opcional): Utilizador escolhe uma lista
     */
    private function handleEscolhaDeLista(string $respostaUsuario, array $contexto): string
    {
        $escolha = trim($respostaUsuario);
        
        if (!is_numeric($escolha) || !isset($contexto[$escolha]) || $contexto[$escolha] === 'nenhuma') {
            $this->usuario->clearState($this->pdo);
            return "Entendido! 👍\n\nEstou pronto para registar os teus itens. (Ex: *2x Leite 5,00*)";
        }
        
        $listaId = (int)$contexto[$escolha];
        $itens = ListaCompra::findItemsByListId($this->pdo, $listaId);
        
        if (empty($itens)) {
            $this->usuario->clearState($this->pdo);
            return "Essa lista está vazia. 😕\n\nMas não há problema, estou pronto para registar os teus itens. (Ex: *2x Leite 5,00*)";
        }
        
        $this->usuario->clearState($this->pdo);
        $nomesNormalizados = array_map(fn($item) => StringUtils::normalize($item['produto_nome']), $itens);
        
        $compraAtiva = Compra::findActiveByUser($this->pdo, $this->usuario->id);
        $est = Estabelecimento::findById($this->pdo, $compraAtiva->estabelecimento_id);
        
        $precos = HistoricoPreco::findPricesForListInCity($this->pdo, $est->cidade, $nomesNormalizados, 30);
        
        $mapaPrecos = [];
        foreach ($precos as $p) {
            $mapaPrecos[$p['produto_nome_normalizado']] = $p;
        }

        $resposta = "Aqui está a tua lista com a *comparação de preços* em *{$est->cidade}*:\n";
        
        foreach ($itens as $item) {
            $nomeItem = htmlspecialchars($item['produto_nome']);
            $nomeNorm = StringUtils::normalize($nomeItem);
            
            $resposta .= "\n\n🛒 *{$nomeItem}*";
            
            if (isset($mapaPrecos[$nomeNorm])) {
                $dadosPreco = $mapaPrecos[$nomeNorm];
                $precoFmt = number_format((float)$dadosPreco['preco_minimo'], 2, ',', '.');
                $dataFmt = (new \DateTime($dadosPreco['data_mais_recente']))->format('d/m');
                $resposta .= "\n  💰 *R$ {$precoFmt}* (Visto em {$dadosPreco['estabelecimento_nome']} no dia {$dataFmt})";
            } else {
                $resposta .= "\n  _(Sem preço recente em {$est->cidade})_";
            }
        }
        
        $resposta .= "\n\nEstou pronto para registar os itens que comprares!";
        return $resposta;
    }
}