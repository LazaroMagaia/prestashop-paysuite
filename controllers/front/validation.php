<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class PaySuiteValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = false;

    public function init()
    {
        parent::init();

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
    }

    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$this->module->active || !$cart->id || $cart->id_customer == 0
            || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // A PaySuite cobra em MZN: recusamos qualquer outra moeda
        $currency = new Currency((int) $cart->id_currency);
        if (!Validate::isLoadedObject($currency) || $currency->iso_code !== 'MZN') {
            $this->errors[] = $this->module->l('A PaySuite só aceita pagamentos em Metical (MZN).');
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // 0) Contacto PaySuite: reutiliza (por email) ou cria.
        //    Feito antes da encomenda, para não consumir o carrinho se algo falhar.
        $contactId = $this->resolveContactId($cart, $customer);

        // 1) Cria a encomenda pendente (gera a referência da encomenda)

        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('PAYSUITE_OS_PENDING'),
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        $order = new Order((int) $this->module->currentOrder);
        if (!Validate::isLoadedObject($order)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $confirmationUrl = $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $order->id,
            'key' => $customer->secure_key,
        ]);

        // 2) Cria o pagamento na PaySuite e redireciona para o checkout
        try {
            $paymentData = [
                'amount' => number_format($total, 2, '.', ''),
                'reference' => preg_replace('/[^A-Za-z0-9]/', '', $order->reference),
                'description' => 'Encomenda ' . $order->reference,
                'webhook_url' => $this->context->link->getModuleLink($this->module->name, 'webhook', [], true),
                'return_url' => $confirmationUrl,
            ];
            if ($contactId) {
                // Confirmar o nome do campo na documentação de POST /payments
                $paymentData['contact_id'] = $contactId;
            }

            $payment = $this->module->getApi()->createPayment($paymentData);

            if (empty($payment['checkout_url'])) {
                throw new \RuntimeException('Resposta sem checkout_url.');
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('PaySuite: ' . $e->getMessage(), 3, null, 'Order', (int) $order->id, true);

            $order->setCurrentState((int) Configuration::get('PS_OS_ERROR'));

            // Devolve os produtos ao cliente (a encomenda gastou o carrinho)
            $duplicate = $cart->duplicate();
            if (!empty($duplicate['success'])) {
                $this->context->cart = $duplicate['cart'];
                $this->context->cookie->id_cart = (int) $duplicate['cart']->id;
                $this->context->cookie->write();
            }

            $this->errors[] = $this->module->l('Não foi possível iniciar o pagamento. Tenta novamente.');

            // Só mostra a causa técnica quando o modo debug do PrestaShop está ligado
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                $this->errors[] = 'DEBUG: ' . $e->getMessage();
            }

            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }

        Tools::redirect($payment['checkout_url']);
    }

    /**
     * Devolve o ID do contacto na PaySuite (existente ou novo).
     * Se a API de contactos falhar, devolve null e a compra segue sem contacto.
     */
    private function resolveContactId(Cart $cart, Customer $customer)
    {
        try {
            $address = new Address((int) $cart->id_address_invoice);
            $phone = '';
            if (Validate::isLoadedObject($address)) {
                $phone = $this->normalizePhone($address->phone_mobile ?: $address->phone);
            }

            return $this->module->getApi()->findOrCreateContact(
                trim($customer->firstname . ' ' . $customer->lastname),
                $customer->email,
                $phone
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('PaySuite contacto: ' . $e->getMessage(), 2, null, 'Cart', (int) $cart->id, true);

            return null;
        }
    }

    /**
     * Normaliza o telefone para o formato +258XXXXXXXXX.
     */
    private function normalizePhone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }
        if (strpos($digits, '258') === 0 && strlen($digits) === 12) {
            return '+' . $digits;
        }
        if (strlen($digits) === 9) { // número local moçambicano (82/84/85/86/87...)
            return '+258' . $digits;
        }

        return '+' . $digits;
    }
}