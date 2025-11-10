<?php
// ---
// /app/Services/ItemParserService.php
// (VERSÃO COM NAMESPACE)
// ---

// 1. Define o Namespace
namespace App\Services;

// 2. Importa dependências
// (ParsedItemDTO está no mesmo namespace, não precisa de 'use')
// use App\Services\ParsedItemDTO; 

/**
 * Responsável por "traduzir" o texto do usuário (ex: "2x Arroz 5kg 20,00")
 * para um objeto de dados estruturado (ParsedItemDTO).
 */
class ItemParserService {

    /**
     * Analisa o comando de texto e retorna um DTO.
     */
    public function parse(string $comando): ParsedItemDTO 
    {
        $item = new ParsedItemDTO();
        $comandoLimpo = trim($comando);

        // --- LÓGICA DE PARSING (Expressões Regulares) ---
        // (Esta lógica permanece idêntica à tua versão original)

        // FORMATO PROMOÇÃO (ex: 2x Arroz 5kg 30,00 25,00 ou Arroz 5kg 30,00 25,00)
        // (Qtd Opcional) (Nome Produto) (Preço Normal) (Preço Promo)
        if (preg_match('/^(\d+ ?[xX*uUuNn]?)? ?(.+?) ([\w\d.,]+) ([\d.,]+)$/', $comandoLimpo, $matches)) {
            
            // Verifica se os dois últimos são preços válidos
            $precoNormal = $this->formatPriceToDecimal($matches[3]);
            $precoPromo = $this->formatPriceToDecimal($matches[4]);

            if ($precoNormal !== null && $precoPromo !== null && $precoNormal > $precoPromo) {
                $item->promocaoDetectada = true;
                $item->precoNormalFloat = $precoNormal;
                $item->precoPagoFloat = $precoPromo;
                
                $item->quantidadeDesc = !empty($matches[1]) ? trim($matches[1]) : '1un';
                $item->quantidadeInt = (int)preg_replace('/[^0-9]/', '', $item->quantidadeDesc);
                if ($item->quantidadeInt === 0) $item->quantidadeInt = 1;
                
                $item->nomeProduto = trim($matches[2]);
                
            } else {
                 // Se não for promoção, cai para o formato padrão
                 return $this->parseFormatoPadrao($comandoLimpo, $item);
            }
        
        // FORMATO BARRA (ex: Arroz / 1un / 10,00)
        } elseif (str_contains($comandoLimpo, '/')) {
            $partes = array_map('trim', explode('/', $comandoLimpo));
            if (count($partes) === 3) {
                $item->nomeProduto = $partes[0];
                $item->quantidadeDesc = $partes[1];
                $item->quantidadeInt = (int)preg_replace('/[^0-9]/', '', $item->quantidadeDesc);
                if ($item->quantidadeInt === 0) $item->quantidadeInt = 1;

                $item->precoPagoFloat = $this->formatPriceToDecimal($partes[2]);
            } else {
                 $item->errorMessage = "Formato inválido. 😕\nUse: *Produto / Quantidade / Preço*";
            }
        
        // FORMATO PADRÃO
        } else {
           $item = $this->parseFormatoPadrao($comandoLimpo, $item);
        }
        
        // --- (FIM DA LÓGICA DE PARSING) ---
        
        if ($item->isSuccess() === false && $item->errorMessage === null) {
            $item->errorMessage = "Opa, não entendi o preço. 😕\nUse números, como *21.90* ou *21,90*.";
        }

        return $item;
    }
    
    /**
     * Helper PRIVADO para o formato mais comum.
     * Ex: 2x Arroz 5kg 1un 10,00
     * Ex: Arroz 5kg 1un 10,00
     * Ex: Arroz 5kg 10,00 (assume 1un)
     */
    private function parseFormatoPadrao(string $comando, ParsedItemDTO $item): ParsedItemDTO
    {
         // (Qtd Opcional) (Nome Produto) (QtdDesc Opcional) (Preço)
        if (preg_match('/^(\d+ ?[xX*uUuNn]?)? ?(.+?) ([\d.,]+)$/', $comando, $matches)) {
            
            $item->precoPagoFloat = $this->formatPriceToDecimal($matches[3]);
            if ($item->precoPagoFloat === null) return $item; // Falha

            $nomeEQuantidade = trim($matches[2]);
            $quantidadePrefixo = !empty($matches[1]) ? trim($matches[1]) : null;
            
            // Tenta encontrar a quantidade no final do nome (ex: Arroz 5kg 2un)
            if (preg_match('/^(.+?) (\d+ ?[a-zA-Z]?[kKgG]?[lL]?)$/', $nomeEQuantidade, $subMatches)) {
                 // Caso 1: Nome (QtdDesc)
                 $item->nomeProduto = trim($subMatches[1]);
                 $item->quantidadeDesc = trim($subMatches[2]);
                 
            } else {
                 // Caso 2: Nome (sem QtdDesc)
                 $item->nomeProduto = $nomeEQuantidade;
                 $item->quantidadeDesc = '1un';
            }
            
            // Define a quantidade INT
            if ($quantidadePrefixo) {
                 $item->quantidadeDesc = $quantidadePrefixo . " (" . $item->quantidadeDesc . ")";
                 $item->quantidadeInt = (int)preg_replace('/[^0-9]/', '', $quantidadePrefixo);
            } else {
                 $item->quantidadeInt = (int)preg_replace('/[^0-9]/', '', $item->quantidadeDesc);
            }
            
            if ($item->quantidadeInt === 0) $item->quantidadeInt = 1;

        } else {
            $item->errorMessage = "Não entendi. 😕\nFormato: *<Qtd>x <Produto> <Preço>*\nEx: *2x Arroz 5kg 21,90*";
        }
        
        return $item;
    }


    /**
     * Helper PRIVADO para formatar o preço.
     */
    private function formatPriceToDecimal(string $priceStr): ?float {
        // Limpa (remove R$, espaços, e usa . como decimal)
        $cleanedPrice = str_replace(['R$', 'r$', ' ', '.'], '', $priceStr);
        $cleanedPrice = str_replace(',', '.', $cleanedPrice);
        
        // Verifica se é um número válido após a limpeza
        return is_numeric($cleanedPrice) ? (float)$cleanedPrice : null;
    }
}
?>