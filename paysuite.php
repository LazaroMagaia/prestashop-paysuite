<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

require_once __DIR__ . '/classes/PaySuiteApi.php';

class PaySuite extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'paysuite';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.0';
        $this->author = 'Hypertech, LDA';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('PaySuite');
        $this->description = $this->l('Pagamentos via PaySuite.');
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->installOrderState();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('PAYSUITE_TOKEN')
            && Configuration::deleteByName('PAYSUITE_WEBHOOK_SECRET')
            && Configuration::deleteByName('PAYSUITE_OS_PENDING');
    }

    private function installOrderState()
    {
        // Se já existe (por exemplo numa reinstalação), não criamos outro.
        $existing = (int) Configuration::get('PAYSUITE_OS_PENDING');
        if ($existing && Validate::isLoadedObject(new OrderState($existing))) {
            return true;
        }

        $state = new OrderState();
        $state->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $state->name[(int) $lang['id_lang']] = 'Aguardando pagamento PaySuite';
        }
        $state->color = '#4169E1';
        $state->module_name = $this->name;
        $state->send_email = false;
        $state->hidden = false;
        $state->delivery = false;
        $state->logable = false;
        $state->invoice = false;
        $state->paid = false;

        if (!$state->add()) {
            return false;
        }

        return Configuration::updateValue('PAYSUITE_OS_PENDING', (int) $state->id);
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPaySuite')) {
            // Campo vazio = mantém o valor atual (não os mostramos no formulário)
            $token = trim((string) Tools::getValue('PAYSUITE_TOKEN'));
            if ($token !== '') {
                Configuration::updateValue('PAYSUITE_TOKEN', $token);
            }

            $secret = trim((string) Tools::getValue('PAYSUITE_WEBHOOK_SECRET'));
            if ($secret !== '') {
                Configuration::updateValue('PAYSUITE_WEBHOOK_SECRET', $secret);
            }

            $output .= $this->displayConfirmation($this->l('Configuração guardada.'));
        }

        return $output . $this->renderForm();
    }

    private function renderForm()
    {
        $fields = [
            'form' => [
                'legend' => ['title' => $this->l('Configuração PaySuite')],
                'input' => [
                    [
                        'type' => 'password',
                        'label' => $this->l('Token de acesso'),
                        'name' => 'PAYSUITE_TOKEN',
                        'desc' => Configuration::get('PAYSUITE_TOKEN')
                            ? $this->l('Já existe um token guardado. Deixa vazio para o manter.')
                            : $this->l('Token obtido no painel da PaySuite.'),
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('Segredo do webhook'),
                        'name' => 'PAYSUITE_WEBHOOK_SECRET',
                        'desc' => Configuration::get('PAYSUITE_WEBHOOK_SECRET')
                            ? $this->l('Já existe um segredo guardado. Deixa vazio para o manter.')
                            : $this->l('Segredo usado para validar a assinatura dos webhooks.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Guardar')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->table = $this->table;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitPaySuite';
        $helper->fields_value = [
            'PAYSUITE_TOKEN' => '',
            'PAYSUITE_WEBHOOK_SECRET' => '',
        ];

        return $helper->generateForm([$fields]);
    }

    public function hookPaymentOptions($params)
    {
        // Sem token ou sem segredo do webhook, o módulo não está pronto
        if (!$this->active
            || !Configuration::get('PAYSUITE_TOKEN')
            || !Configuration::get('PAYSUITE_WEBHOOK_SECRET')) {
            return [];
        }

        $currency = new Currency((int) $params['cart']->id_currency);
        if (!Validate::isLoadedObject($currency) || $currency->iso_code !== 'MZN') {
            return [];
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Pagar com PaySuite'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true));

        return [$option];
    }

    public function getApi()
    {
        $token = Configuration::get('PAYSUITE_TOKEN');
        if (!$token) {
            throw new \RuntimeException('Token PaySuite não configurado.');
        }

        return new PaySuiteApi($token);
    }
}