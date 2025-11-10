<?php
// ---
// /app/Services/GooglePlacesService.php
// (VERSÃO CORRIGIDA COM $_ENV)
// ---

namespace App\Services;

use Exception;

class GooglePlacesService {
    
    private string $apiKey;
    private string $apiUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json';

    public function __construct() {
        
        // --- (A CORREÇÃO ESTÁ AQUI) ---
        // Lemos do $_ENV primeiro para contornar o cache
        $this->apiKey = $_ENV['GOOGLE_PLACES_API_KEY'] ?? getenv('GOOGLE_PLACES_API_KEY');
        // --- (FIM DA CORREÇÃO) ---
        
        if (empty($this->apiKey)) {
            throw new Exception("GOOGLE_PLACES_API_KEY não definida no .env");
        }
    }

    /**
     * Busca locais no Google Places API
     * (O resto da função é idêntico)
     */
    public function buscarLocais(string $nomeMercado, string $cidadeEstado): array
    {
        $query = $nomeMercado . " em " . $cidadeEstado;
        
        $params = http_build_query([
            'input' => $query,
            'inputtype' => 'textquery',
            'fields' => 'name,formatted_address,place_id',
            'key' => $this->apiKey,
            'language' => 'pt-BR'
        ]);

        $urlCompleta = $this->apiUrl . "?" . $params;

        $ch = curl_init($urlCompleta);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode != 200) {
             throw new Exception(
                "Falha na API do Google Places. HTTP $httpCode. Resposta: $responseJson. Erro cURL: $curlError"
            );
        }

        $data = json_decode($responseJson, true);

        if ($data['status'] !== 'OK' || empty($data['candidates'])) {
            return []; // Nenhum resultado
        }

        $locaisFormatados = [];
        foreach ($data['candidates'] as $local) {
            $locaisFormatados[] = [
                'nome_google' => $local['name'],
                'endereco' => $local['formatted_address'],
                'place_id' => $local['place_id']
            ];
        }

        return $locaisFormatados;
    }
}
?>