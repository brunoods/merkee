<?php
// ---
// /app/Services/WhatsAppService.php
// (VERSÃO COM NAMESPACE, getenv() e EXCEÇÕES)
// ---

// 1. Define o Namespace
namespace App\Services;

// 2. Importa a classe global Exception
use Exception; 

class WhatsAppService {
    
    private string $apiUrl;
    private string $clientToken;

    public function __construct() {
        // 3. Lê as chaves do .env
        $this->apiUrl = getenv('WHATSAPP_API_URL');
        $this->clientToken = getenv('WHATSAPP_CLIENT_TOKEN');

        if (empty($this->apiUrl) || empty($this->clientToken)) {
            throw new Exception("WHATSAPP_API_URL ou WHATSAPP_CLIENT_TOKEN não definidos no .env");
        }
    }

    /**
     * @param string $to_phone
     * @param string $message
     * @return bool (true se sucesso)
     * @throws Exception (em caso de falha da API)
     */
    public function sendMessage(string $to_phone, string $message): bool
    {
        $payload = json_encode([
            'phone' => $to_phone,
            'message' => $message
        ]);

        // 2. Prepara a chamada cURL
        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        // --- HEADERS ---
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'Client-Token: ' . $this->clientToken 
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch); // (Obtém o erro do cURL)
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            // (NOVO) Lança uma exceção em vez de logar
            // O script que a chamou (webhook/cron) será responsável por logar.
            throw new Exception(
                "Falha na API do WhatsApp. HTTP $httpCode. Resposta: $response. Erro cURL: $curlError"
            );
        }

        // Se chegou aqui, teve sucesso
        return true;
    }
}
?>