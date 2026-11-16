<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class PaySuiteApi
{
    const BASE_URL = 'https://paysuite.tech/api/v1';

    /** @var string */
    private $token;

    public function __construct($token)
    {
        $this->token = (string) $token;
    }

    /**
     * Cria um pagamento. Devolve o array "data" da resposta (com checkout_url).
     */
    public function createPayment(array $payload)
    {
        $response = $this->request('POST', '/payments', $payload);

        return isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
    }

    /**
     * Procura um contacto pelo email (filtrado na API). Devolve o contacto ou null.
     */
    public function findContactByEmail($email)
    {
        $query = http_build_query(['page' => 1, 'limit' => 20, 'email' => $email]);
        $response = $this->request('GET', '/contacts?' . $query);

        if (!isset($response['data']) || !is_array($response['data'])) {
            return null;
        }

        // Garante correspondência exacta, caso o filtro da API seja parcial
        foreach ($response['data'] as $contact) {
            if (isset($contact['email']) && strcasecmp($contact['email'], $email) === 0) {
                return $contact;
            }
        }

        return null;
    }

    public function createContact($name, $email, $phone)
    {
        $payload = ['name' => $name, 'email' => $email];
        if ($phone !== '') {
            $payload['phone'] = $phone;
        }

        $response = $this->request('POST', '/contacts', $payload);

        if (empty($response['data']['id'])) {
            throw new \RuntimeException('PaySuite: contacto criado sem id.');
        }

        return $response['data'];
    }

    /**
     * Devolve o ID do contacto: reutiliza se existir, cria se não existir.
     */
    public function findOrCreateContact($name, $email, $phone)
    {
        $contact = $this->findContactByEmail($email);
        if ($contact && !empty($contact['id'])) {
            return $contact['id'];
        }

        $created = $this->createContact($name, $email, $phone);

        return $created['id'];
    }

    private function request($method, $path, array $body = null)
    {
        $ch = curl_init(self::BASE_URL . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('PaySuite API: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new \RuntimeException('PaySuite API: HTTP ' . $status . ' ' . substr($raw, 0, 300));
        }

        return $decoded;
    }
}