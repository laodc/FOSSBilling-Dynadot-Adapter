<?php

declare(strict_types=1);

/**
 * @copyright LaoDC (https://laodc.com)
 * @license   MIT
 *
 * Support at https://github.com/laodc/
 */

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

final class Registrar_Adapter_Dynadot extends Registrar_AdapterAbstract
{
    private $config = [
        'ApiCredentials' => [
            'live' => [
                'key' => '',
                'secret' => '',
            ],
            'sandbox' => [
                'key' => '',
                'secret' => '',
            ],
        ],
        'ApiUrl' => [
            'live' => 'https://api.dynadot.com/restful/v2',
            'sandbox' => 'https://api-sandbox.dynadot.com/restful/v2',
        ],
    ];
    private const MODULE_VERSION = '0.1.3';

    public function __construct(array $options)
    {
        if (isset($options['ApiKey']) && !empty($options['ApiKey'])) {
            $this->config['ApiCredentials']['live']['key'] = $options['ApiKey'];
            unset($options['ApiKey']);
        } else {
            throw new Registrar_Exception('Dynadot Registrar module error. Please update configuration parameter "API Key" at "Configuration -> Domain registration"', [':domain_registrar' => 'Dynadot', ':missing' => 'Dynadot API Key'], 3_001);
        }

        if (isset($options['ApiSecret']) && !empty($options['ApiSecret'])) {
            $this->config['ApiCredentials']['live']['secret'] = $options['ApiSecret'];
            unset($options['ApiSecret']);
        } else {
            throw new Registrar_Exception('Dynadot Registrar module error. Please update configuration parameter "API Secret" at "Configuration -> Domain registration"', [':domain_registrar' => 'Dynadot', ':missing' => 'Dynadot API Secret'], 3_001);
        }

        if (isset($options['ApiSandboxKey']) && !empty($options['ApiSandboxKey'])) {
            $this->config['ApiCredentials']['sandbox']['key'] = $options['ApiSandboxKey'];
            unset($options['ApiSandboxKey']);
        }

        if (isset($options['ApiSandboxSecret']) && !empty($options['ApiSandboxSecret'])) {
            $this->config['ApiCredentials']['sandbox']['secret'] = $options['ApiSandboxSecret'];
            unset($options['ApiSandboxSecret']);
        }
    }

    /**
     * Helper function to check if in test environment mode.
     */
    public function isTestEnv(): bool
    {
        return $this->_testMode;
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Dynadot registrar',
            'form' => [
                'ApiKey' => [
                    'text',
                    [
                        'label' => 'API Key',
                        'description' => 'Dynadot account API key',
                        'required' => true,
                    ],
                ],
                'ApiSecret' => [
                    'text',
                    [
                        'label' => 'API Secret',
                        'description' => 'Dynadot account API secret key',
                        'required' => true,
                    ],
                ],
                'ApiSandboxKey' => [
                    'text',
                    [
                        'label' => 'Sandbox API Key',
                        'description' => 'Dynadot account sandbox API key',
                        'required' => false,
                    ],
                ],
                'ApiSandboxSecret' => [
                    'text',
                    [
                        'label' => 'Sandbox API Secret Key',
                        'description' => '',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $url = sprintf('/domains/%s/search', urlencode($this->_getDomainName($domain)));
        $params = [
            'show_price' => 'false',
        ];
        $response = $this->_makeRequest('GET', $url, $params);

        if ($response->code === 200) {
            if (strtolower($response->data->premium) === 'yes') {
                throw new Registrar_Exception('Premium domains cannot be registered.');
            }

            return strtolower($response->data->available) === 'yes' ? true : false;
        }

        return false;
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        $url = sprintf('/domains/%s/search', urlencode($this->_getDomainName($domain)));
        $params = [
            'show_price' => 'true',
        ];
        $response = $this->_makeRequest('GET', $url, $params);

        if ($response->code === 200) {
            if (strtolower($response->data->premium) === 'yes') {
                throw new Registrar_Exception('Premium domains cannot be transferred.');
            }

            if (strtolower($response->data->available) === 'no') {
                if (!empty($response->data->price_list[0]->transfer_price)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function modifyNs(Registrar_Domain $domain): true
    {
        $url = sprintf('/domains/%s/nameservers', urlencode($this->_getDomainName($domain)));

        $ns = [];
        $ns[] = $domain->getNs1();
        $ns[] = $domain->getNs2();
        if ($domain->getNs3()) {
            $ns[] = $domain->getNs3();
        }
        if ($domain->getNs4()) {
            $ns[] = $domain->getNs4();
        }

        $params = [
            'nameserver_list' => $ns,
        ];

        $response = $this->_makeRequest('PUT', $url, $params);

        if ($response->code === 200) {
            return true;
        }

        $this->getLog()->error($response->error->description);
        $placeholders = [':action:' => __trans('modify name servers'), ':type:' => 'Dynadot'];

        throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
    }

    public function modifyContact(Registrar_Domain $domain): true
    {
        $domain_info = $this->getDomainDetails($domain);

        foreach (['Admin', 'Billing', 'Registrar', 'Tech'] as $type) {
            /** @var Registrar_Domain_Contact * */
            $contact = $domain->{'getContact' . $type}();

            $params = $this->_ContactToDynadotArray($contact);

            $contact_id = $domain_info->{'getContact' . $type}();

            if ($contact_id === 0) {
                $url = '/contacts';
                $response = $this->_makeRequest('POST', $url, $params);
            } else {
                $url = sprintf('/contacts/%d', $contact_id);
                $response = $this->_makeRequest('PUT', $url, $params);
            }

            if (!in_array($response->code, [200, 202])) {
                $this->getLog()->error($response->error->description);
                $placeholders = [':action:' => __trans('modify contact'), ':type:' => 'Dynadot'];

                throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
            }
        }

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): true
    {
        $url = sprintf('/domains/%s/transfer_in', urlencode($this->_getDomainName($domain)));

        $params = [
            'domain' => [
                'duration' => $domain->getRegistrationPeriod(),
                'auth_code' => $domain->getEpp(),
                'registrant_contact' => $this->_ContactToDynadotArray($domain->getContactRegistrar()),
                'admin_contact' => $this->_ContactToDynadotArray($domain->getContactAdmin()),
                'tech_contact' => $this->_ContactToDynadotArray($domain->getContactTech()),
                'billing_contact' => $this->_ContactToDynadotArray($domain->getContactBilling()),
            ],
        ];

        $response = $this->_makeRequest('POST', $url, $params);

        if (in_array($response->code, [200, 202])) {
            return true;
        }

        $this->getLog()->error($response->error->description);
        $placeholders = [':action:' => __trans('transfer in domain'), ':type:' => 'Dynadot'];

        throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $url = sprintf('/domains/%s', urlencode($this->_getDomainName($domain)));

        $response = $this->_makeRequest('GET', $url);

        if ($response->code !== 200) {
            $this->getLog()->error($response->error->description);
            $placeholders = [':action:' => __trans('get domain details'), ':type:' => 'Dynadot'];

            throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
        }

        $info = $response->data->domain_info;

        $newDomain = clone $domain;

        $newDomain->setRegistrationTime($info->registration);
        $newDomain->setExpirationTime($info->expiration);
        $newDomain->setPrivacyEnabled($info->privacy === 'privacy');
        $newDomain->setLocked($info->locked === 'Yes');

        if (!empty($info->glue_info->name_server_settings->nameservers)) {
            $n = 1;
            foreach ($info->glue_info->name_server_settings->nameservers as $ns) {
                $newDomain->{'setNs' . $n++}($ns->server_name);
            }
        }

        if ($info->registrant_contactId > 0) {
            $newDomain->setContactRegistrar($this->_getContactInfo($info->registrant_contact_id));
        }

        if ($info->admin_contactId > 0) {
            $newDomain->setContactAdmin($this->_getContactInfo($info->admin_contactId));
        }

        if ($info->tech_contactId > 0) {
            $newDomain->setContactTech($this->_getContactInfo($info->tech_contactId));
        }

        if ($info->billing_contactId > 0) {
            $newDomain->setContactBilling($this->_getContactInfo($info->billing_contactId));
        }

        return $newDomain;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        $url = sprintf('/domains/%s/transfer_auth_code', urlencode($this->_getDomainName($domain)));

        $params = [
            'new_code' => 'false',
            'unlock_domain_for_transfer' => 'true',
        ];

        $response = $this->_makeRequest('GET', $url, $params);

        if ($response->code === 200) {
            return $response->data->auth_code;
        }

        $this->getLog()->error($response->error->description);
        $placeholders = [':action:' => __trans('get the transfer code'), ':type:' => 'Dynadot'];

        throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
    }

    public function registerDomain(Registrar_Domain $domain): true
    {
        $url = sprintf('/domains/%s/register', urlencode($this->_getDomainName($domain)));

        $ns = [];
        $ns[] = $domain->getNs1();
        $ns[] = $domain->getNs2();
        if ($domain->getNs3()) {
            $ns[] = $domain->getNs3();
        }
        if ($domain->getNs4()) {
            $ns[] = $domain->getNs4();
        }

        $params = [
            'domain' => [
                'duration' => $domain->getRegistrationPeriod(),
                'privacy' => $domain->getPrivacyEnabled() ? 'full' : 'off',
                'registrant_contact' => $this->_ContactToDynadotArray($domain->getContactRegistrar()),
                'admin_contact' => $this->_ContactToDynadotArray($domain->getContactAdmin()),
                'tech_contact' => $this->_ContactToDynadotArray($domain->getContactTech()),
                'billing_contact' => $this->_ContactToDynadotArray($domain->getContactBilling()),
                'nameserver_list' => $ns,
            ],
            'register_premium' => 'false',
        ];

        $response = $this->_makeRequest('POST', $url, $params);

        if ($response->code === 200) {
            return true;
        }

        $this->getLog()->error($response->error->description);
        $placeholders = [':action:' => __trans('register domain'), ':type:' => 'Dynadot'];

        throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $url = sprintf('/domains/%s/renew', urlencode($this->_getDomainName($domain)));

        $params = [
            'duration' => $domain->getRegistrationPeriod(),
            'year' => $domain->getRegistrationPeriod(),
            'no_renew_if_late_renew_fee_needed' => $this->_ContactToDynadotArray($domain->getContactRegistrar()),
        ];

        $response = $this->_makeRequest('POST', $url, $params);

        if ($response->code === 200) {
            return true;
        }

        $this->getLog()->error($response->error->description);
        $placeholders = [':action:' => __trans('renew domain'), ':type:' => 'Dynadot'];

        throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
    }

    public function deleteDomain(Registrar_Domain $domain): never
    {
        $url = sprintf('/domains/%s/grace_delete', urlencode($this->_getDomainName($domain)));

        $params = [
            'add_to_waiting_list' => 'yes',
        ];

        $response = $this->_makeRequest('DELETE', $url, $params);

        if ($response->code === 200) {
            return true;
        }

        $this->getLog()->error($response->error->description);
        $placeholders = [':action:' => __trans('delete domain'), ':type:' => 'Dynadot'];

        throw new Registrar_Exception('Failed to :action: with the :type: registrar, check the error logs for further details', $placeholders);
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $url = sprintf('/domains/%s/privacy', urlencode($this->_getDomainName($domain)));

        // "full", "partial", or "off"
        $params = [
            'privacy_level' => 'full',
            'whois_privacy_option' => 'true',
        ];

        $response = $this->_makeRequest('PUT', $url, $params);

        if ($response->code === 200) {
            return true;
        }

        $this->getLog()->error($response->error->description);

        return false;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $url = sprintf('/domains/%s/privacy', urlencode($this->_getDomainName($domain)));

        // "full", "partial", or "off"
        $params = [
            'privacy_level' => 'off',
            'whois_privacy_option' => 'false',
        ];

        $response = $this->_makeRequest('PUT', $url, $params);

        if ($response->code === 200) {
            return true;
        }

        $this->getLog()->error($response->error->description);

        return false;
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $url = sprintf('/domains/%s/domain_lock', urlencode($this->_getDomainName($domain)));

        $params = [
            'lock' => 'true',
        ];

        $response = $this->_makeRequest('PUT', $url, $params);

        if ($response->code === 200) {
            return true;
        }

        $this->getLog()->error($response->error->description);

        return false;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $url = sprintf('/domains/%s/domain_lock', urlencode($this->_getDomainName($domain)));

        $params = [
            'lock' => 'false',
        ];

        $response = $this->_makeRequest('PUT', $url, $params);

        if ($response->code === 200) {
            return true;
        }

        $this->getLog()->error($response->error->description);

        return false;
    }

    /************************************
     *  Internal functions for dyndaot  *
     ************************************/

    /**
     * Call Dynadot API endpoints and returns the result.
     *
     * @param string $method  submission method: [GET, POST, PUT, DELETE]
     * @param string $request the URL endpoint to request
     * @param array  $params  the data to submit to the endpoint
     *
     * @return stdClass json structured output
     *
     * @throws Registrar_Exception if there was an error while making an api request
     *
     * @internal
     */
    private function _makeRequest(string $method, string $request, array $params = []): stdClass
    {
        $method = strtoupper($method);

        $client = $this->getHttpClient()->withOptions([
            'timeout' => 60,
        ]);

        $url = $this->_getApiUrl() . $request;

        $requestId = $this->_generateRequestId();
        $headers = $this->_addHttpHeaders($requestId);

        try {
            switch ($method) {
                case 'GET':
                case 'DELETE':
                    if (!empty($params)) {
                        $url .= '?' . $this->_buildParams($params);
                    }
                    $headers['X-Signature'] = $this->_sign($requestId, $this->_getUrlPath($url), []);
                    $response = $client->request($method, $url, [
                        'headers' => $headers,
                    ]);

                    break;
                case 'POST':
                case 'PUT':
                    $headers['X-Signature'] = $this->_sign($requestId, $this->_getUrlPath($url), $params);
                    $response = $client->request($method, $url, [
                        'body' => json_encode($params),
                        'headers' => $headers,
                    ]);

                    break;
                default:
                    $e = new Registrar_Exception("Unknown method ({$method})");

                    throw $e;
            }
        } catch (HttpExceptionInterface $error) {
            $e = new Registrar_Exception("HttpClientException: {$error->getMessage()}.");
            $this->getLog()->error($e->getMessage());

            throw $e;
        }

        $data = $response->getContent();

        $this->getLog()->info('API Result: ' . $data);

        return json_decode($data);
    }

    /**
     * Obtain the active endpoint.
     *
     * @return string the API endpoint
     *
     * @internal
     */
    private function _getApiUrl(): string
    {
        if ($this->isTestEnv()) {
            return $this->config['ApiUrl']['sandbox'];
        }

        return $this->config['ApiUrl']['live'];
    }

    /**
     * Obtain the active API active key.
     *
     * @return string the API key
     *
     * @internal
     */
    private function _getApiKey(): string
    {
        if ($this->isTestEnv()) {
            return $this->config['ApiCredentials']['sandbox']['key'];
        }

        return $this->config['ApiCredentials']['live']['key'];
    }

    /**
     * Obtain the active API active secret.
     *
     * @return string the API secret
     *
     * @internal
     */
    private function _getApiSecret(): string
    {
        if ($this->isTestEnv()) {
            return $this->config['ApiCredentials']['sandbox']['secret'];
        }

        return $this->config['ApiCredentials']['live']['secret'];
    }

    /**
     * Helper function to get the current FQDN in lowercase formatting.
     *
     * @param Registrar_Domain $domain domain object
     *
     * @return string the domain name
     *
     * @internal
     */
    private function _getDomainName(Registrar_Domain $domain): string
    {
        return strtolower($domain->getSld() . $domain->getTld());
    }

    /**
     * Obtain contact information from API.
     *
     * @return Registrar_Domain_Contact provides the contact information, or empty object if not found
     *
     * @internal
     */
    private function _getContactInfo(int $contactId): Registrar_Domain_Contact
    {
        $url = sprintf('/contacts/%d', $contactId);

        $response = $this->_makeRequest('GET', $url);

        $contact = new Registrar_Domain_Contact();
        if ($response->code === 200) {
            $contact
                ->setCompany($response->data->organization)
                ->setFirstName($response->data->name)
                ->setEmail($response->data->email)
                ->setTel($response->data->phone_number)
                ->setTelCc($response->data->phone_cc)
                ->setFax($response->data->fax_number)
                ->setFaxCc($response->data->fax_cc)
                ->setAddress1($response->data->address1)
                ->setAddress2($response->data->address2)
                ->setCity($response->data->city)
                ->setState($response->data->state)
                ->setZip($response->data->zip)
                ->setCountry($response->data->country);
        }

        return $contact;
    }

    /**
     * Helper function to get the current FQDN in lowercase formatting.
     *
     * @param Registrar_Domain_Contact $contact contact object
     *
     * @return array contact information in structure suitable for Dynadots system
     *
     * @internal
     */
    private function _ContactToDynadotArray(Registrar_Domain_Contact $contact): array
    {
        return [
            'organization' => $contact->getCompany() ?? '',
            'name' => $contact->getFirstName() . ' ' . $contact->getLastName(),
            'email' => $contact->getEmail(),
            'phone_number' => $contact->getTel(),
            'phone_cc' => $contact->getTelCc(),
            'address1' => $contact->getAddress1(),
            'address2' => $contact->getAddress2() ?? '',
            'city' => $contact->getCity(),
            'state' => $contact->getState(),
            'zip' => $contact->getZip(),
            'country' => $contact->getCountry(),
        ];
    }

    /**
     * Obtain the url path section of the full URL.
     *
     * Example: http://localhost/api/endpoint
     * Return: /api/endpoint?arg=1
     *
     * @param string $url full URL string
     *
     * @return string the path including any params
     *
     * @internal
     */
    private function _getUrlPath(string $url): string
    {
        $params = parse_url($url);

        $output = $params['path'];
        if (!empty($params['query'])) {
            $output .= '?' . $params['query'];
        }

        return $output;
    }

    /**
     * Convert array of data fields into a compliant url param string.
     *
     * @param array $params list of key=>value data
     *
     * @return string formatted query string
     *
     * @internal
     */
    private function _buildParams(array $params): string
    {
        foreach ($params as &$param) {
            if (is_bool($param)) {
                $param = ($param) ? 'true' : 'false';
            }
        }

        return http_build_query($params);
    }

    /**
     * Required HTTP headers to send to API.
     *
     * @param string $requestId unique id string for api call
     *
     * @return array list of HTTP headers
     *
     * @internal
     */
    private function _addHttpHeaders(string $requestId): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->_getApiKey(),
            'X-Request-ID' => $requestId,
        ];
    }

    /**
     * Required for Dynadot API to secure data being sent over the air.
     *
     * @param string $requestId unique if string for api call
     * @param string $path      api endpoint path
     * @param array  $params    list of key=>value data
     *
     * @return string base64 encoded hash
     *
     * @internal
     */
    private function _sign(string $requestId, string $path, array $params): string
    {
        $stringToSign = implode("\n", [
            $this->_getApiKey(),
            $path,
            $requestId,
            !empty($params) ? json_encode($params, JSON_UNESCAPED_SLASHES) : '',
        ]);

        return base64_encode(hash_hmac('sha256', $stringToSign, $this->_getApiSecret(),true));
    }

    /**
     * Helper function to create a unique id string.
     *
     * @return string unique id
     *
     * @internal
     */
    private function _generateRequestId(): string
    {
        $bytes = bin2hex(random_bytes(16));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($bytes, 0, 8),
            substr($bytes, 8, 4),
            substr($bytes, 12, 4),
            substr($bytes, 16, 4),
            substr($bytes, 20, 12)
        );
    }
}
