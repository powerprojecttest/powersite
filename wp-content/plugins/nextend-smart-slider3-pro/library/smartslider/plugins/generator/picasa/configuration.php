<?php

class N2SliderGeneratorPicasaConfiguration {

    private $data;

    /** @var  N2SSPluginGeneratorPicasa */
    protected $group;

    /**
     * N2SliderGeneratorPicasaConfiguration constructor.
     *
     * @param $group
     */
    public function __construct($group) {
        $this->group = $group;
        $this->data  = new N2Data(array(
            'apiKey'      => '',
            'apiSecret'   => '',
            'accessToken' => ''
        ));

        $this->data->loadJSON(N2Base::getApplication('smartslider')->storage->get('picasa'));

    }

    public function wellConfigured() {
        if (!$this->data->get('apiKey') || !$this->data->get('apiSecret') || !$this->data->get('accessToken')) {
            return false;
        }

        $api = $this->getApi();
        try {
            if ($api->isAccessTokenExpired()) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getApi() {
		if(!class_exists('Google_Client')){
			$path = dirname(__FILE__) . '/../youtube';

			require_once $path . '/googleclient/Exception.php';
			require_once $path . '/googleclient/Auth/Abstract.php';
			require_once $path . '/googleclient/Auth/OAuth2.php';
			require_once $path . '/googleclient/Auth/Exception.php';
			require_once $path . '/googleclient/Config.php';
			require_once $path . '/googleclient/Service.php';
			require_once $path . '/googleclient/Client.php';
			require_once $path . '/googleclient/Service/Resource.php';
			require_once $path . '/googleclient/Model.php';
			require_once $path . '/googleclient/Collection.php';
			require_once $path . '/googleclient/Task/Retryable.php';
			require_once $path . '/googleclient/Service/Exception.php';
			require_once $path . '/googleclient/Service/YouTube.php';
			require_once $path . '/googleclient/Http/Request.php';
			require_once $path . '/googleclient/Http/CacheParser.php';
			require_once $path . '/googleclient/Http/REST.php';
			require_once $path . '/googleclient/IO/Exception.php';
			require_once $path . '/googleclient/IO/Abstract.php';
			require_once $path . '/googleclient/IO/Curl.php';
			require_once $path . '/googleclient/Logger/Abstract.php';
			require_once $path . '/googleclient/Logger/Null.php';
			require_once $path . '/googleclient/Utils.php';

			require_once $path . '/googleclient/Task/Exception.php';
			require_once $path . '/googleclient/Task/Runner.php';
		}

        $client = new Google_Client();
        $client->setAccessType('offline');
        $client->setClientId(trim($this->data->get('apiKey')));
        $client->setClientSecret(trim($this->data->get('apiSecret')));
        $client->addScope('https://picasaweb.google.com/data/');


        $client->setRedirectUri(N2Base::getApplication('smartslider')->router->createUrl(array(
            "generator/finishAuth",
            array(
                'group' => N2Request::getVar('group')
            )
        )));

        $token = n2_base64_decode($this->data->get('accessToken', null));
        try {
            if ($token) {
                $client->setAccessToken($token);
                if ($client->isAccessTokenExpired()) {
                    $refreshToken = $client->getRefreshToken();
                    if (!empty($refreshToken)) {
                        $client->refreshToken($refreshToken);

                        try {
                            $oldAccessToken = json_decode(n2_base64_decode($this->data->get('accessToken')), true);
                            if (!is_array($oldAccessToken)) {
                                $oldAccessToken = array();
                            }
                        } catch (Exception $e) {
                            $oldAccessToken = array();
                        }

                        $this->data->set('accessToken', n2_base64_encode(json_encode(array_merge($oldAccessToken, json_decode($client->getAccessToken(), true)))));
                        $this->addData($this->data->toArray());
                    }
                }
            }
        } catch (Exception $e) {
            N2Message::error($e->getMessage());
        }

        return $client;
    }

    public function getData() {
        return $this->data->toArray();
    }

    public function addData($data, $store = true) {
        $this->data->loadArray($data);
        if ($store) {
            N2Base::getApplication('smartslider')->storage->set('picasa', null, json_encode($this->data->toArray()));
        }
    }

    public function render() {
        $this->group->loadElements();

        $form = new N2Form();
        $form->loadArray($this->getData());

        $settings = new N2Tab($form, 'picasa-generator', 'Picasa api');
        new N2ElementText($settings, 'apiKey', 'Client ID', '', array(
            'style' => 'width:600px;'
        ));
        new N2ElementText($settings, 'apiSecret', 'Client secret', '', array(
            'style' => 'width:250px;'
        ));
        new N2ElementPicasaToken($settings, 'accessToken', n2_('Token'));
        new N2ElementContainer($settings, 'callback', n2_('Callback url'));
        new N2ElementToken($settings);

        echo $form->render('generator');

        try {
            $this->getApi();
        } catch (Exception $e) {
            N2Message::error($e->getMessage());
        }
    }

    public function startAuth($approvalPrompt = 'auto') {
        if (session_id() == "") {
            session_start();
        }
        $this->addData(N2Request::getVar('generator'), false);

        $_SESSION['data'] = $this->getData();

        $client = $this->getApi();
        $client->setApprovalPrompt($approvalPrompt);
        $client->setAccessType('offline');

        return $client->createAuthUrl();
    }

    public function finishAuth() {
        if (session_id() == "") {
            session_start();
        }
        $this->addData($_SESSION['data'], false);
        unset($_SESSION['data']);
        try {
            $client = $this->getApi();
            $client->authenticate($_GET['code']);
            $accessToken = $client->getAccessToken();

            if ($accessToken) {
                $data = $this->getData();

                try {
                    $oldAccessToken = json_decode(n2_base64_decode($data['accessToken']), true);
                    if (!is_array($oldAccessToken)) {
                        $oldAccessToken = array();
                    }
                } catch (Exception $e) {
                    $oldAccessToken = array();
                }

                $newAccessToken = array_merge($oldAccessToken, json_decode($accessToken, true));

                if (!isset($newAccessToken['refresh_token'])) {
                    header('Location: ' . $this->startAuth('force'));
                    exit;
                }

                $data['accessToken'] = n2_base64_encode(json_encode($newAccessToken));
                $this->addData($data);

                return true;
            }

            return false;
        } catch (Exception $e) {
            return $e;
        }
    }

}