<?php
// ---
// /app/Utils/StringUtils.php
// (VERSÃO COM NAMESPACE)
// ---

// 1. Define o Namespace
namespace App\Utils;

// (Esta classe não tem dependências externas)

class StringUtils {

    /**
     * Normaliza um nome de produto para comparação.
     * Ex: "1x Arroz Tio João (5kg)" -> "arroz tio joao 5kg"
     *
     * @param string $string
     * @return string
     */
    public static function normalize(string $string): string 
    {
        // 1. Converte para minúsculas
        $string = strtolower($string);
        
        // 2. Remove acentos
        if (function_exists('transliterator_transliterate')) {
            $string = transliterator_transliterate('Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove', $string);
        } else {
            $string = @iconv('UTF-8', 'ASCII//TRANSLIT', $string);
        }

        // 3. (NOVO!) Remove prefixos de quantidade
        $string = preg_replace('/^(\d+ ?[xX] ?|\d+ ?[uU][nN] ?|\d+ ?)/', '', $string);

        // 4. Remove caracteres especiais
        $string = preg_replace('/[^a-z0-9\s]/', '', $string);
        
        // 5. Substitui múltiplos espaços por um único espaço
        $string = preg_replace('/\s+/', ' ', $string);
        
        // 6. Remove espaços no início e no fim
        return trim($string);
    }
}
?>