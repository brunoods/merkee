<?php
// ---
// /app/Services/ItemParserService.php
// (VERSÃO 3.1 - CORREÇÃO FINAL DO PARSER DE PREÇO)
// ---

namespace App\Services;

class ItemParserService {

    /**
     * Analisa o comando de texto e retorna um DTO.
     * (Esta função está correta)
     */
    public function parse(string $comando): ParsedItemDTO 
    {
        $item = new ParsedItemDTO();
        $comandoLimpo = trim($comando);

        // FORMATO PROMOÇÃO (ex: 2x Arroz 5kg 30,00 25,00)
        if (preg_match('/^(\d+ ?[xX*uUuNn]?)? ?(.+?) ([\w\d.,]+) ([\d.,]+)$/', $comandoLimpo, $matches)) {
            
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
        
        
        if ($item->isSuccess() === false && $item->errorMessage === null) {
            $item->errorMessage = "Opa, não entendi o preço. 😕\nUse números, como *21.90* ou *21,90*.";
        }

        return $item;
    }
    
    /**
     * Helper PRIVADO para o formato mais comum.
     * (Esta função está correta)
     */
    private function parseFormatoPadrao(string $comando, ParsedItemDTO $item): ParsedItemDTO
    {
        if (preg_match('/^(\d+ ?[xX*uUuNn]?)? ?(.+?) ([\d.,]+)$/', $comando, $matches)) {
            
            $item->precoPagoFloat = $this->formatPriceToDecimal($matches[3]);
            if ($item->precoPagoFloat === null) return $item;

            $item->nomeProduto = trim($matches[2]);
            $item->quantidadeDesc = !empty($matches[1]) ? trim($matches[1]) : '1un';
            
            $item->quantidadeInt = (int)preg_replace('/[^0-9]/', '', $item->quantidadeDesc);
            if ($item->quantidadeInt === 0) $item->quantidadeInt = 1;

        } else {
            $item->errorMessage = "Não entendi. 😕\nFormato: *<Qtd>x <Produto> <Preço>*\nEx: *2x Arroz 5kg 21,90*";
        }
        
        return $item;
    }


    /**
     * Helper PRIVADO para formatar o preço.
     * (VERSÃO CORRIGIDA)
     */
    private function formatPriceToDecimal(string $priceStr): ?float {
        
        // --- (INÍCIO DA CORREÇÃO) ---
        // 1. Remove R$, r$, espaços
        $cleanedPrice = str_replace(['R$', 'r$', ' '], '', $priceStr);
        
        // 2. Verifica se usa vírgula (ex: 5,00 ou 1.234,50)
        if (str_contains($cleanedPrice, ',')) {
            // Remove pontos de milhar (ex: 1.234,50 -> 1234,50)
            $cleanedPrice = str_replace('.', '', $cleanedPrice);
            // Troca a vírgula decimal por ponto (ex: 1234,50 -> 1234.50)
            $cleanedPrice = str_replace(',', '.', $cleanedPrice);
        }
        // Se não usou vírgula, ele já deve estar no formato 5.00 (que é válido)
        // --- (FIM DA CORREÇÃO) ---

        // 3. Verifica se é um número válido após a limpeza
        return is_numeric($cleanedPrice) ? (float)$cleanedPrice : null;
    }
}
?>