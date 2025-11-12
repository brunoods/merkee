<?php
// ---
// /app/Services/GooglePlacesService.php
// (VERSÃO FINAL 2.0 - COM FALLBACK DE CIDADE 'administrative_area_level_2')
// ---

namespace App\Services;

use Exception;

class GooglePlacesService {
    
    private string $apiKey;
    private string $textSearchUrl = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json';
    private string $nearbySearchUrl = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json';
    private string $placeDetailsUrl = 'https://maps.googleapis.com/maps/api/place/details/json';


    public function __construct() {
        $this->apiKey = $_ENV['GOOGLE_PLACES_API_KEY'] ?? getenv('GOOGLE_PLACES_API_KEY');
        if (empty($this->apiKey)) {
            throw new Exception("GOOGLE_PLACES_API_KEY não definida no .env");
        }
    }

    /**
     * Busca os detalhes de um local (Cidade, Estado) usando o Place ID.
     * Esta é a forma mais fiável de obter dados de endereço.
     */
    public function buscarDetalhesDoLocal(string $place_id): array
    {
        $params = http_build_query([
            'place_id' => $place_id,
            'fields' => 'address_components,name', // Pedimos os "componentes do endereço"
            'key' => $this->apiKey,
            'language' => 'pt-BR'
        ]);

        $urlCompleta = $this->placeDetailsUrl . "?" . $params;
        $data = $this->fazerRequestApi($urlCompleta);

        if ($data['status'] !== 'OK' || empty($data['result'])) {
            throw new Exception("Não foi possível obter detalhes para o place_id: $place_id");
        }
        
        $componentes = $data['result']['address_components'];
        
        // --- (INÍCIO DA CORREÇÃO 2.0) ---

        $cidade = 'N/A';
        $estado = 'N/A';
        $cidade_aal2 = null; // Variável temporária para o fallback (nível 2)

        // O Google retorna os componentes do endereço. Vamos extraí-los.
        foreach ($componentes as $componente) {
            
            // Prioridade 1: 'locality' é o tipo padrão para Cidade
            if (in_array('locality', $componente['types'])) {
                $cidade = $componente['long_name'];
            }
            
            // Prioridade 2: 'administrative_area_level_2' (Município)
            if (in_array('administrative_area_level_2', $componente['types'])) {
                $cidade_aal2 = $componente['long_name'];
            }
            
            // 'administrative_area_level_1' é o tipo padrão para Estado
            if (in_array('administrative_area_level_1', $componente['types'])) {
                $estado = $componente['short_name']; // (Ex: "SP")
            }
        }

        // Se não encontrámos 'locality' (Prioridade 1), 
        // usamos 'administrative_area_level_2' (Prioridade 2)
        if ($cidade === 'N/A' && $cidade_aal2 !== null) {
            $cidade = $cidade_aal2;
        }
        
        // --- (FIM DA CORREÇÃO 2.0) ---

        return [
            'nome_google' => $data['result']['name'],
            'cidade' => $cidade,
            'estado' => $estado
        ];
    }


    /**
     * Busca supermercados próximos com base na latitude e longitude.
     */
    public function buscarSupermercadosProximos(float $latitude, float $longitude): array
    {
        $params = http_build_query([
            'location' => $latitude . ',' . $longitude,
            'rankby' => 'distance',
            'type' => 'supermarket',
            'key' => $this->apiKey,
            'language' => 'pt-BR'
        ]);

        $urlCompleta = $this->nearbySearchUrl . "?" . $params;
        $data = $this->fazerRequestApi($urlCompleta);

        if ($data['status'] !== 'OK' || empty($data['results'])) {
            return [];
        }

        $locaisFormatados = [];
        $resultados = array_slice($data['results'], 0, 3); 
        
        foreach ($resultados as $local) {
            $locaisFormatados[] = [
                'nome_google' => $local['name'],
                'endereco' => $local['vicinity'] ?? 'Endereço não disponível',
                'place_id' => $local['place_id']
            ];
        }

        return $locaisFormatados;
    }


    /**
     * Busca locais no Google Places API (por texto)
     */
    public function buscarLocais(string $nomeMercado, string $cidadeEstado): array
    {
        $params = http_build_query([
            'input' => $nomeMercado . " em " . $cidadeEstado,
            'inputtype' => 'textquery',
            'fields' => 'name,formatted_address,place_id',
            'key' => $this->apiKey,
            'language' => 'pt-BR'
        ]);

        $urlCompleta = $this->textSearchUrl . "?" . $params;
        $data = $this->fazerRequestApi($urlCompleta);
        
        if ($data['status'] !== 'OK' || empty($data['candidates'])) {
            return [];
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
    
    /**
     * Centraliza a lógica de fazer a chamada cURL
     */
    private function fazerRequestApi(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode != 200) {
             throw new Exception(
                "Falha na API do Google Places. HTTP $httpCode. Resposta: $responseJson. Erro cURL: $curlError"
            );
        }
        
        return json_decode($responseJson, true);
    }
}
?>