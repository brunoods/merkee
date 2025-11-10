<?php
// ---
// /app/Services/ItemParserService.php
// (VERSÃO 3.0 - CORRIGIDA A LÓGICA DE PREÇO)
// ---

namespace App\Services;

class ItemParserService {

    /**
     * Analisa o comando de texto e retorna um DTO.
     */
    public function parse(string $comando): ParsedItemDTO 
    {
        $item = new ParsedItemDTO();
        $comandoLimpo = trim($comando);

        // --- LÓGICA DE PARSING (Expressões Regulares) ---

        // FORMATO PROMOÇÃO (ex: 2x Arroz 5kg 30,00 25,00 ou Arroz 5kg 30,00 25,00)
        // (Qtd Opcional) (Nome Produto) (Preço Normal) (Preço Promo)
        if (preg_match('/^(\d+ ?[xX*uUuNn]?)? ?(.+?) ([\w\d.,]+) ([\d.,]+)$/', $comandoLimpo, $matches)) {
            
            $precoNormal = $this->formatPriceToDecimal($matches[3]);
            $precoPromo = $this->formatPriceToDecimal($matches[4]);

            if ($precoNormal !== null && $precoPromo !== null && $precoNormal > $precoPromo) {
                $item->promocaoDetectada = true;
                $item->precoNormalFloat = $precoNormal; // Preço Unitário Normal
                $item->precoPagoFloat = $precoPromo;    // Preço Unitário Promocional
                
                $item->quantidadeDesc = !empty($matches[1]) ? trim($matches[1]) : '1un';
                $item->quantidadeInt = (int)preg_replace('/[^0-9]/', '', $item->quantidadeDesc);
                if ($item->quantidadeInt === 0) $item->quantidadeInt = 1;
                
                $item->nomeProduto = trim($matches[2]);
                
            } else {
                 // Se não for promoção, cai para o formato padrão
                 return $this->parseFormatoPadrao($comandoLimpo, $item);
            }
        
        // FORMATO BARRA (ex: Arroz / 1un / 10,00) - Assume Preço Unitário
        } elseif (str_contains($comandoLimpo, '/')) {
            $partes = array_map('trim', explode('/', $comandoLimpo));
            if (count($partes) === 3) {
                $item->nomeProduto = $partes[0];
                $item->quantidadeDesc = $partes[1];
                $item->quantidadeInt = (int)preg_replace('/[^0-9]/', '', $item->quantidadeDesc);
                if ($item->quantidadeInt === 0) $item->quantidadeInt = 1;

                $item->precoPagoFloat = $this->formatPriceToDecimal($partes[2]); // Preço Unitário
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
     * Ex: 2x Arroz 5kg 10,00 (Significa 2 unidades, 10.00 CADA)
     * Ex: Arroz 5kg 10,00 (Significa 1 unidade, 10.00 CADA)
     */
    private function parseFormatoPadrao(string $comando, ParsedItemDTO $item): ParsedItemDTO
    {
         // (Qtd Opcional) (Nome Produto) (Preço Unitário)
        if (preg_match('/^(\d+ ?[xX*uUuNn]?)? ?(.+?) ([\d.,]+)$/', $comando, $matches)) {
            
            $item->precoPagoFloat = $this->formatPriceToDecimal($matches[3]); // PREÇO UNITÁRIO
            if ($item->precoPagoFloat === null) return $item; // Falha

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
     */
    private function formatPriceToDecimal(string $priceStr): ?float {
        $cleanedPrice = str_replace(['R$', 'r$', ' ', '.'], '', $priceStr);
        $cleanedPrice = str_replace(',', '.', $cleanedPrice);
        
        return is_numeric($cleanedPrice) ? (float)$cleanedPrice : null;
    }
}
?>