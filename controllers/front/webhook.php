<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class PaySuiteWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = false;

    public function postProcess()
    {
        try {
            $this->handleWebhook();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('PaySuite webhook: ' . $e->getMessage(), 3);
            $this->respond(500, ['message' => 'Payment processing failed']);
        }
    }

    private function handleWebhook()
    {
        // 1) Validar a assinatura sobre o corpo bruto
        $secret = (string) Configuration::get('PAYSUITE_WEBHOOK_SECRET');
        $signature = isset($_SERVER['HTTP_X_SIGNATURE']) ? trim((string) $_SERVER['HTTP_X_SIGNATURE']) : '';
        $rawBody = (string) file_get_contents('php://input');

        if (!$this->module->active || $secret === '' || $signature === '') {
            PrestaShopLogger::addLog('PaySuite webhook sem assinatura ou segredo.', 2);
            $this->respond(401, ['message' => 'Unauthorized']);
        }

        if (!hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature)) {
            PrestaShopLogger::addLog('PaySuite webhook com assinatura inválida.', 2);
            $this->respond(401, ['message' => 'Invalid signature']);
        }

        // 2) Interpretar o JSON
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->respond(400, ['message' => 'Invalid payload']);
        }

        $event = isset($payload['event']) ? $payload['event'] : null;
        $data = isset($payload['data']) ? $payload['data'] : null;
        if (!is_array($data)) {
            $this->respond(400, ['message' => 'Invalid payment data']);
        }

        if (!in_array($event, ['payment.success', 'payment.failed'], true)) {
            $this->respond(200, ['received' => true]);
        }

        $reference = isset($data['reference']) ? trim((string) $data['reference']) : '';
        $providerId = isset($data['id']) ? trim((string) $data['id']) : '';
        if ($reference === '' || $providerId === '') {
            $this->respond(422, ['message' => 'Missing payment reference']);
        }

        // 3) Encontrar a encomenda (a referência da PaySuite é a da encomenda)
        $order = Order::getByReference($reference)->getFirst();
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            PrestaShopLogger::addLog('PaySuite: encomenda não encontrada: ' . $reference, 2);
            $this->respond(404, ['message' => 'Order not found']);
        }

        // 4) Processar com um bloqueio por encomenda (webhooks repetidos em simultâneo)
        $db = Db::getInstance();
        $lock = pSQL('paysuite_order_' . (int) $order->id);
        $db->getValue("SELECT GET_LOCK('" . $lock . "', 10)");

        try {
            $order = new Order((int) $order->id); // estado fresco, já com o bloqueio
            list($code, $body) = $this->processWebhook($order, $event, $data, $providerId);
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('" . $lock . "')");
        }

        $this->respond($code, $body);
    }

    private function processWebhook(Order $order, $event, array $data, $providerId)
    {
        // Já pago: um webhook repetido não faz nada
        if ($order->hasBeenPaid()) {
            return [200, ['received' => true, 'result' => 'already_paid']];
        }

        // Validar o montante (em cêntimos, para evitar erros de vírgula flutuante)
        if (!isset($data['amount']) || !is_numeric($data['amount']) || (float) $data['amount'] < 0) {
            return [422, ['message' => 'Payment amount mismatch']];
        }
        $received = (int) round((float) $data['amount'] * 100);
        $expected = (int) round((float) $order->total_paid * 100);
        if ($received !== $expected) {
            PrestaShopLogger::addLog(
                sprintf('PaySuite: montante diferente na encomenda %s (%d vs %d).', $order->reference, $received, $expected),
                3,
                null,
                'Order',
                (int) $order->id,
                true
            );

            return [422, ['message' => 'Payment amount mismatch']];
        }

        if (isset($data['currency']) && strtoupper((string) $data['currency']) !== 'MZN') {
            return [422, ['message' => 'Payment currency mismatch']];
        }

        // Pagamento falhado: só muda se ainda estiver pendente
        if ($event === 'payment.failed') {
            if ((int) $order->current_state === (int) Configuration::get('PAYSUITE_OS_PENDING')) {
                $order->setCurrentState((int) Configuration::get('PS_OS_ERROR'));
            }

            return [200, ['received' => true, 'result' => 'payment_failed']];
        }

        // Pagamento confirmado
        $order->setCurrentState((int) Configuration::get('PS_OS_PAYMENT'));

        // O PrestaShop cria o registo de pagamento ao passar para um estado "pago":
        // guardamos nele o ID da PaySuite
        $payment = $order->getOrderPaymentCollection()->getLast();
        if (Validate::isLoadedObject($payment)) {
            $payment->transaction_id = $providerId;
            $payment->save();
        }

        return [200, ['received' => true, 'result' => 'payment_confirmed']];
    }

    private function respond($code, array $body)
    {
        http_response_code((int) $code);
        header('Content-Type: application/json');
        die(json_encode($body));
    }
}