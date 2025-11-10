<?php
// ---
// /app/Services/ParsedItemDTO.php
// (VERSÃO COM NAMESPACE)
// ---

// 1. Define o Namespace
namespace App\Services;

/**
 * Data Transfer Object (DTO)
 * É um "contrato" simples que diz quais dados o ItemParserService extraiu.
 * Não tem lógica, apenas armazena dados.
 */
class ParsedItemDTO {
    
    // Os dados que extraímos
    public string $nomeProduto = '';
    public string $quantidadeDesc = '1un';
    public int $quantidadeInt = 1;
    public ?float $precoPagoFloat = null;
    public ?float $precoNormalFloat = null;
    public bool $promocaoDetectada = false;

    // Controlo de erros
    public ?string $errorMessage = null;

    /**
     * Verifica se o parsing foi bem sucedido.
     */
    public function isSuccess(): bool {
        // Precisa de um preço para ser válido
        return $this->errorMessage === null && $this->precoPagoFloat !== null;
    }
}
?>