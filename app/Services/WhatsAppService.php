<?php
// ---
// /app/Services/WhatsAppService.php
// (VERSÃO CORRIGIDA COM $_ENV)
// ---

namespace App\Services;

use Exception; 

class WhatsAppService {
    
    private string $apiUrl;
    private string $clientToken;

    public function __construct() {
        
        // --- (A CORREÇÃO ESTÁ AQUI) ---
        // Lemos do $_ENV primeiro para contornar o cache do servidor
        $this->apiUrl = $_ENV['WHATSAPP_API_URL'] ?? getenv('WHATSAPP_API_URL');
        $this->clientToken = $_ENV['WHATSAPP_CLIENT_TOKEN'] ?? getenv('WHATSAPP_CLIENT_TOKEN');
        // --- (FIM DA CORREÇÃO) ---

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

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'Client-Token: ' . $this->clientToken 
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception(
                "Falha na API do WhatsApp. HTTP $httpCode. Resposta: $response. Erro cURL: $curlError"
            );
        }

        return true;
    }
}
?>