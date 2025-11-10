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
        
        // --- (TODA A LÓGICA DE PREG_MATCH VEM PARA AQUI - sem mudança) ---

        // FORMATO PROMOÇÃO (ex: 2x Arroz 5kg 30,00 25,00)
        if (preg_match('/^(\d+ ?[xX*uUuNn]?)? ?(.+?) ([\w\d.,]+) ([\d.,]+) ([\d.,]+)$/', $comando, $matches)) {
            // ... (lógica idêntica)
        
        // FORMATO QUANTIDADE EXPLÍCITA (ex: Arroz 2x 10,00)
        } elseif (preg_match('/(.+) (\d+) ?[xX*] ?([\d.,]+)$/', $comando, $matches)) {
            // ... (lógica idêntica)

        // FORMATO BARRA (ex: Arroz / 1un / 10,00)
        } elseif (str_contains($comando, '/')) {
            // ... (lógica idêntica)

        // FORMATO PADRÃO (ex: Arroz 1un 10,00 ou 2x Arroz 1un 10,00)
        } else {
            // ... (lógica idêntica)
        }
        
        // --- (FIM DA LÓGICA DE PARSING) ---
        
        if ($item->isSuccess() === false && $item->errorMessage === null) {
            $item->errorMessage = "Opa, não entendi o preço. 😕\nUse números, como *21.90* ou *21,90*.";
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